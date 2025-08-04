<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\Frame;
use Dompdf\FrameDecorator\Block as BlockFrameDecorator;
use Dompdf\FrameDecorator\Page as PageFrameDecorator;

/**
 * Reflows pages
 *
 * @package dompdf
 */
class Page extends AbstractFrameReflower
{

    /**
     * Cache of the callbacks array
     *
     * @var array
     */
    private $_callbacks;

    /**
     * Cache of the canvas
     *
     * @var \Dompdf\Canvas
     */
    private $_canvas;

    /**
     * Page constructor.
     * @param PageFrameDecorator $frame
     */
    function __construct(PageFrameDecorator $frame)
    {
        parent::__construct($frame);
    }

    /**
     * @param PageFrameDecorator $frame
     * @param int $page_number
     */
    function apply_page_style(Frame $frame, $page_number)
    {
        $style = $frame->get_style();
        $page_styles = $style->get_stylesheet()->get_page_styles();

        // http://www.w3.org/TR/CSS21/page.html#page-selectors
        if (count($page_styles) > 1) {
            $odd = $page_number % 2 == 1;
            $first = $page_number == 1;

            $style = clone $page_styles["base"];

            // FIXME RTL
            if ($odd && isset($page_styles[":right"])) {
                $style->merge($page_styles[":right"]);
            }

            if ($odd && isset($page_styles[":odd"])) {
                $style->merge($page_styles[":odd"]);
            }

            // FIXME RTL
            if (!$odd && isset($page_styles[":left"])) {
                $style->merge($page_styles[":left"]);
            }

            if (!$odd && isset($page_styles[":even"])) {
                $style->merge($page_styles[":even"]);
            }

            if ($first && isset($page_styles[":first"])) {
                $style->merge($page_styles[":first"]);
            }

            $frame->set_style($style);
        }

        $frame->calculate_bottom_page_edge();
    }

    /**
     * Paged layout:
     * http://www.w3.org/TR/CSS21/page.html
     *
     * @param BlockFrameDecorator|null $block
     */
    function reflow(?BlockFrameDecorator $block = null)
    {
        /** @var PageFrameDecorator $frame */
        $frame = $this->_frame;
        $child = $frame->get_first_child();
        $fixed_children = [];
        $prev_child = null;
        $current_page = 0;

        while ($child) {
            $this->apply_page_style($frame, $current_page + 1);

            $style = $frame->get_style();

            // Pages are only concerned with margins
            $cb = $frame->get_containing_block();
            $left = (float)$style->length_in_pt($style->margin_left, $cb["w"]);
            $right = (float)$style->length_in_pt($style->margin_right, $cb["w"]);
            $top = (float)$style->length_in_pt($style->margin_top, $cb["h"]);
            $bottom = (float)$style->length_in_pt($style->margin_bottom, $cb["h"]);

            $content_x = $cb["x"] + $left;
            $content_y = $cb["y"] + $top;
            $content_width = $cb["w"] - $left - $right;
            $content_height = $cb["h"] - $top - $bottom;

            // Only if it's the first page, we save the nodes with a fixed position
            if ($current_page == 0) {
                foreach ($child->get_children() as $onechild) {
                    if ($onechild->get_style()->position === "fixed") {
                        $fixed_children[] = $onechild->deep_copy();
                    }
                }
                $fixed_children = array_reverse($fixed_children);
            }

            $child->set_containing_block($content_x, $content_y, $content_width, $content_height);

            // Check for begin reflow callback
            $this->_check_callbacks("begin_page_reflow", $child);

            //Insert a copy of each node which have a fixed position
            if ($current_page >= 1) {
                foreach ($fixed_children as $fixed_child) {
                    $child->insert_child_before($fixed_child->deep_copy(), $child->get_first_child());
                }
            }

            $child->reflow();

            // Apply semantic tags to the frame tree after layout is complete
            $this->_apply_semantic_tags($child);
            $this->get_dompdf()->log('frametree', "reflow() Semantic tags applied to frame tree for page " . ($current_page + 1));
            

            // Configure which properties to log - empty array = log ALL properties
            // $propertiesToLog = ["_semantic_tag", "_aria_role", "_aria_label", "_aria_describedby"];
            $propertiesToLog = ["_semantic_tag"]; 
            // $propertiesToLog = [];
            $this->get_dompdf()->log('frametree', 'Frametree nodes:');
            $this->get_dompdf()->log('frametree', '');
            foreach ($child->get_children() as $index => $frame_child) {
                $node = $frame_child->get_node();
                $nodeInfo = $node ? ($node->nodeType === XML_ELEMENT_NODE ? $node->nodeName : '#text') : 'no-node';
                
                $this->get_dompdf()->log('frametree', "=== Child $index - Node: $nodeInfo ===");
                
                // Use reflection to get ALL properties
                $reflection = new \ReflectionClass($frame_child);
                $properties = $reflection->getProperties();
                
                foreach ($properties as $property) {
                    $property->setAccessible(true);
                    $propertyName = $property->getName();
                    
                    // Skip property if filtering is active and property is not in the list
                    if (!empty($propertiesToLog) && !in_array($propertyName, $propertiesToLog)) {
                        continue;
                    }
                    
                    try {
                        $value = $property->getValue($frame_child);
                        
                        // Handle different value types safely
                        if ($value === null) {
                            $displayValue = 'null';
                        } elseif (is_bool($value)) {
                            $displayValue = $value ? 'true' : 'false';
                        } elseif (is_string($value) || is_numeric($value)) {
                            $displayValue = (string)$value;
                        } elseif (is_array($value)) {
                            $displayValue = '[Array with ' . count($value) . ' elements]';
                        } elseif (is_object($value)) {
                            // Skip problematic objects that might cause circular references
                            if (in_array($propertyName, ['_parent', '_first_child', '_last_child', '_prev_sibling', '_next_sibling', '_decorator', '_node'])) {
                                $displayValue = '[' . get_class($value) . ' - skipped]';
                            } else {
                                $displayValue = '[Object: ' . get_class($value) . ']';
                            }
                        } else {
                            $displayValue = '[Unknown type]';
                        }
                        
                        $this->get_dompdf()->log('frametree', "  $propertyName: $displayValue");
                        
                    } catch (\Exception $e) {
                        $this->get_dompdf()->log('frametree', "  $propertyName: [Error accessing property]");
                    }
                }
                $this->get_dompdf()->log('frametree', "");
            }

            $next_child = $child->get_next_sibling();

            // Check for begin render callback
            $this->_check_callbacks("begin_page_render", $child);

            // Render the page
            $frame->get_renderer()->render($child);

            // Check for end render callback
            $this->_check_callbacks("end_page_render", $child);

            if ($next_child) {
                $frame->next_page();
            }

            // Wait to dispose of all frames on the previous page
            // so callback will have access to them
            if ($prev_child) {
                $prev_child->dispose(true);
            }
            $prev_child = $child;
            $child = $next_child;
            $current_page++;
        }

        // Dispose of previous page if it still exists
        if ($prev_child) {
            $prev_child->dispose(true);
        }
    }

    /**
     * Check for callbacks that need to be performed when a given event
     * gets triggered on a page
     *
     * @param string $event The type of event
     * @param Frame  $frame The frame that event is triggered on
     */
    protected function _check_callbacks(string $event, Frame $frame): void
    {
        if (!isset($this->_callbacks)) {
            $dompdf = $this->get_dompdf();
            $this->_callbacks = $dompdf->getCallbacks();
            $this->_canvas = $dompdf->getCanvas();
        }

        if (isset($this->_callbacks[$event])) {
            $fs = $this->_callbacks[$event];
            $canvas = $this->_canvas;
            $fontMetrics = $this->get_dompdf()->getFontMetrics();

            foreach ($fs as $f) {
                $f($frame, $canvas, $fontMetrics);
            }
        }
    }

    /**
     * Extract semantic tags from DOM and apply them to frame tree
     * This method reads HTML5 semantic elements and preserves their meaning
     * for accessibility purposes in the frame tree structure
     *
     * @param Frame $frame The frame to process for semantic tags
     */
    protected function _apply_semantic_tags(Frame $frame): void
    {
        $node = $frame->get_node();

        if ($node && $node->nodeType === XML_ELEMENT_NODE) {
            $tagName = strtolower($node->nodeName);

            // Define semantic HTML5 tags and their PDF structure equivalents
            $semanticTags = [
                'main' => 'Document',
                'header' => 'Header',
                'footer' => 'Footer',
                'nav' => 'Navigation',
                'section' => 'Section',
                'article' => 'Article',
                'aside' => 'Aside',
                'h1' => 'H1',
                'h2' => 'H2',
                'h3' => 'H3',
                'h4' => 'H4',
                'h5' => 'H5',
                'h6' => 'H6',
                'p' => 'P',
                'ul' => 'L',
                'ol' => 'L',
                'li' => 'LI',
                'table' => 'Table',
                'thead' => 'THead',
                'tbody' => 'TBody',
                'tfoot' => 'TFoot',
                'tr' => 'TR',
                'th' => 'TH',
                'td' => 'TD',
                'figure' => 'Figure',
                'figcaption' => 'Caption',
                'blockquote' => 'BlockQuote'
            ];

            // Check if this element has semantic meaning
            if (isset($semanticTags[$tagName])) {
                $structureType = $semanticTags[$tagName];

                // Add semantic tag information to frame
                $frame->set_semantic_tag($structureType);

                // Log the semantic tag assignment for debugging
                $this->get_dompdf()->log('frametree', "_apply_semantic_tags() Applied semantic tag: {$tagName} -> {$structureType} to frame");

                // Check for ARIA attributes that enhance semantics
                if ($node->hasAttribute('role')) {
                    $role = $node->getAttribute('role');
                    $frame->set_aria_role($role);
                    $this->get_dompdf()->log('frametree', "_apply_semantic_tags() Applied ARIA role: {$role} to frame");
                }

                if ($node->hasAttribute('aria-label')) {
                    $label = $node->getAttribute('aria-label');
                    $frame->set_aria_label($label);
                    $this->get_dompdf()->log('frametree', "_apply_semantic_tags() Applied ARIA label: {$label} to frame");
                }

                if ($node->hasAttribute('aria-describedby')) {
                    $describedBy = $node->getAttribute('aria-describedby');
                    $frame->set_aria_describedby($describedBy);
                    $this->get_dompdf()->log('frametree', "_apply_semantic_tags() Applied ARIA describedby: {$describedBy} to frame");
                }
            }
        }

        // Recursively apply semantic tags to all child frames
        foreach ($frame->get_children() as $child) {
            $this->_apply_semantic_tags($child);
        }
    }

    /**
     * Log the complete frame tree structure as JSON-like objects
     *
     * @param Frame $frame The root frame to log
     * @param int $page_number The current page number
     */
    protected function _logCompleteFrameTree(Frame $frame, int $page_number): void
    {
        $this->get_dompdf()->log('frametree', "=== COMPLETE FRAME TREE FOR PAGE $page_number ===");
        $treeData = $this->_buildFrameTreeData($frame);
        $this->get_dompdf()->log('frametree', json_encode($treeData, JSON_PRETTY_PRINT));
        $this->get_dompdf()->log('frametree', "=== END FRAME TREE FOR PAGE $page_number ===");
    }

    /**
     * Build frame tree data structure for logging
     *
     * @param Frame $frame The frame to build data for
     * @param int $depth Current depth for limiting recursion
     * @return array
     */
    protected function _buildFrameTreeData(Frame $frame, int $depth = 0): array
    {
        if ($depth > 20) { // Prevent infinite recursion
            return ['error' => 'Max depth reached'];
        }

        $node = $frame->get_node();
        $nodeName = $node ? ($node->nodeType === XML_ELEMENT_NODE ? $node->nodeName : '#text') : 'unknown';

        $position = $frame->get_position();
        $style = $frame->get_style();

        $frameData = [
            'nodeName' => $nodeName,
            'frameClass' => get_class($frame),
            'nodeType' => $node ? $node->nodeType : null,
            'position' => [
                'x' => isset($position['x']) ? round($position['x'], 2) : null,
                'y' => isset($position['y']) ? round($position['y'], 2) : null,
                'w' => isset($position['w']) ? round($position['w'], 2) : null,
                'h' => isset($position['h']) ? round($position['h'], 2) : null,
            ],
            'style' => [
                'display' => $style ? $style->display : null,
                'position' => $style ? $style->position : null,
            ]
        ];

        // Add semantic tag information if available
        $semanticTag = $frame->get_semantic_tag();
        if ($semanticTag) {
            $frameData['semanticTag'] = $semanticTag;
        }

        $ariaRole = $frame->get_aria_role();
        if ($ariaRole) {
            $frameData['ariaRole'] = $ariaRole;
        }

        $ariaLabel = $frame->get_aria_label();
        if ($ariaLabel) {
            $frameData['ariaLabel'] = $ariaLabel;
        }

        $ariaDescribedby = $frame->get_aria_describedby();
        if ($ariaDescribedby) {
            $frameData['ariaDescribedby'] = $ariaDescribedby;
        }

        // Add text content for text nodes
        if ($node && $node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if (!empty($text)) {
                $frameData['textContent'] = substr($text, 0, 100); // Limit length
            }
        }

        // Add children
        $children = [];
        foreach ($frame->get_children() as $child) {
            $children[] = $this->_buildFrameTreeData($child, $depth + 1);
        }

        if (!empty($children)) {
            $frameData['children'] = $children;
        }

        return $frameData;
    }
}
