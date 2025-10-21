<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf;

/**
 * Semantic element tracking for Canvas implementations
 * 
 * This trait provides a standardized way to store and retrieve semantic
 * information about rendered elements across all Canvas implementations.
 * 
 * DUAL STRUCTURE SUPPORT:
 * - OLD: $_semantic_elements array (KEPT for backward compatibility!)
 * - NEW: $_semantic_tree (parallel tree structure for O(1) navigation!)
 * 
 * Usage:
 * ```php
 * class TCPDF implements Canvas {
 *     use CanvasSemanticTrait;
 *     // ...
 * }
 * ```
 * 
 * @package dompdf
 */
trait CanvasSemanticTrait
{
   
    /**
     * Semantic tree structure for O(1) parent/child navigation
     * @var SemanticTree|null
     */
    protected ?SemanticTree $_semantic_tree = null;
        
    /**
     * Set current frame ID in tree (just passes frameId to PDF backend)
     *
     * This is called during rendering to track which node is being processed.
     * The tree itself doesn't store "current" - that's TCPDF's responsibility.
     * 
     * @param string|null $frameId The frame ID being rendered (null = clear)
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        // Tree not initialized? Skip silently
        if ($this->_semantic_tree === null) {
            return;
        }
        
        // Just tunnel to PDF backend - tree doesn't need to know "current"!
        if (method_exists($this->_pdf, 'setCurrentFrameId')) {
            $this->_pdf->setCurrentFrameId($frameId);
        }
    }

    /**
     * Register semantic elements for accessibility (SIMPLIFIED APPROACH)
     * 
     * DUAL REGISTRATION:
```    /**
     * Register semantic elements for accessibility (SIMPLIFIED APPROACH)
     * 
     * DUAL REGISTRATION:
     * - OLD: Registers to $_semantic_elements array (KEPT for compatibility!)
     * - NEW: Registers to $_semantic_tree (parallel tree structure!)
     * 
     * Only registers semantic containers, not text fragments.
     * Text fragments will automatically inherit from their immediate parent container.
     * This eliminates the need for complex backward searching and line-break detection.
     * 
     * @param Frame $frame The root frame to start from
     */
    public function registerAllSemanticElements(Frame $frame): void
    {

        if($this->_semantic_tree === null) return;
        
        $registeredCount = 0;
        
        SimpleLogger::log(
            "dompdf_logs",
            __METHOD__,
            "=== Starting registration ==="
        );
    
        // Simplified recursive function - only register semantic containers
        $registerSemanticContainers = function(Frame $frame) use (&$registerSemanticContainers, &$registeredCount) {
            $node = $frame->get_node();
            $nodeName = $node->nodeName;
            
            // SIMPLIFIED LOGIC: Only register semantic containers, skip text fragments and decorative elements
            // Skip text fragments and purely decorative elements (#text, br, hr)
            // Register all other elements as potential semantic containers (div, p, span, h1-h6, table, tr, td, th, ul, ol, li, etc.)
            if (!in_array($nodeName, ['#text', 'br', 'hr'])) {
                $attributes = [];
                if ($node->hasAttributes()) {
                    foreach ($node->attributes as $attr) {
                        $attributes[$attr->name] = $attr->value;
                    }
                }
                
                $parent = $frame->get_parent();
                $parentId = $parent ? $parent->get_id() : null;
                
                // Extract common data
                $frameId = $frame->get_id();
                $display = $frame->get_style()->display;
                
                // Direct tree access - no wrapper method!
                $this->_semantic_tree->add(
                    $frameId,       // Frame ID
                    $nodeName,      // Tag name
                    $attributes,    // Attributes
                    $display,       // CSS display
                    $parentId       // Parent frame ID (tree handles linking!)
                );
                
                $registeredCount++;
                
                SimpleLogger::log("dompdf_logs", __METHOD__, 
                    sprintf("DUAL: Registered to BOTH structures: %s <%s> (parent: %s)", 
                        $frameId, $nodeName, $parentId ?? 'none')
                );
            }
            
            // Process all children regardless of whether we registered this frame
            foreach ($frame->get_children() as $child) {
                $registerSemanticContainers($child);
            }
        };
        
        // Start registration
        $registerSemanticContainers($frame);
        
        SimpleLogger::log(
            "dompdf_logs",
            __METHOD__,
            sprintf(
                "=== Semantic registration complete: %d elements | Array: %d | Tree nodes: %d ===\n Tree: %s",
                $registeredCount,
                $registeredCount,  // Should match
                $this->_semantic_tree ? $this->_semantic_tree->getNodeCount() : 0, // Should also match,
                $this->_semantic_tree ? $this->_semantic_tree : 0
            )
        );
        
    }
}