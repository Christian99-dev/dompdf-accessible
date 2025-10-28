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
     * Set current frame ID in tree (resolves to semantic parent)
     *
     * This is called during rendering to track which node is being processed.
     * 
     * @param string|null $frameId The frame ID being rendered (null = clear)
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        // Tree not initialized? Skip silently
        if ($this->_semantic_tree === null) {
            return;
        }
        
        // If frameId is null, just clear
        if ($frameId === null) {
            if (method_exists($this->_pdf, 'setCurrentFrameId')) {
                $this->_pdf->setCurrentFrameId(null);
            }
            return;
        }
        
        // Tunnel to PDF backend
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
    
        // Simplified recursive function - register ALL frames for complete tree
        $registerSemanticContainers = function(Frame $frame) use (&$registerSemanticContainers, &$registeredCount) {
            $node = $frame->get_node();
            $nodeName = $node->nodeName;
            
            // REGISTER ALL FRAMES (including #text, br, hr, decorative elements)
            // This ensures every Frame ID has a corresponding node in the tree
            // Even if they're not semantic, they need to be in the tree for proper navigation
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
            
            // Direct tree access - add ALL frames!
            $this->_semantic_tree->add(
                $frameId,       // Frame ID
                $nodeName,      // Tag name
                $attributes,    // Attributes
                $display,       // CSS display
                $parentId       // Parent frame ID (tree handles linking!)
            );
            
            $registeredCount++;
            
            SimpleLogger::log("dompdf_logs", __METHOD__, 
                sprintf("Registered: frameId=%s, tag=<%s>, parent=%s", 
                    $frameId, $nodeName, $parentId ?? 'none')
            );
            
            // Process all children
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