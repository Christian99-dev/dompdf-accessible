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
    // ========================================================================
    // OLD STRUCTURE (KEPT for backward compatibility!)
    // ========================================================================
    
    /**
     * Storage for semantic information
     * Maps element IDs to their SemanticElement objects
     * ONLY contains elements that should get StructElems in PDF!
     * 
     * @var SemanticElement[]
     */
    protected array $_semantic_elements = [];
    
    // ========================================================================
    // NEW STRUCTURE (Tree for O(1) navigation!)
    // ========================================================================
    
    /**
     * Semantic tree structure for O(1) parent/child navigation
     * @var SemanticTree|null
     */
    protected ?SemanticTree $_semantic_tree = null;
    
    /**
     * Register a semantic element
     * 
     * OPTIMIZATION: Skip registration of transparent inline tags
     * These tags (strong, em, span, etc.) provide only styling, not structure.
     * They should NOT create separate StructElems in the PDF structure tree.
     * Instead, their styling is applied via font changes in the parent's BDC context.
     * 
     * @param string $elementId Unique identifier for the element (e.g., "frame_123")
     * @param SemanticElement|array $semanticData Either a SemanticElement object or legacy array format
     */
    public function registerSemanticElement(SemanticElement $semanticElement): void
    {        
        $this->_semantic_elements[$semanticElement->id] = $semanticElement;
        
        // Get text content for debugging
        $textContent = 'N/A';
        if (isset($semanticElement->attributes['text_content'])) {
            $textContent = substr($semanticElement->attributes['text_content'], 0, 100);
        }
        
        SimpleLogger::log(
            "canvas_semantic_trait_logs",
            "registerSemanticElement()",
            sprintf(
                "REGISTER: frame_%s | <%s> [%s] | parent: %s | text: '%s'",
                $semanticElement->id,
                $semanticElement->tag,
                $semanticElement->display,
                $semanticElement->parentId ?? 'none',
                $textContent
            )
        );  
    }
    
    // ========================================================================
    // NEW TREE METHODS (parallel to old structure!)
    // ========================================================================
    
    /**
     * Initialize semantic tree (call once before registration!)
     */
    public function initializeSemanticTree(): void
    {
        $this->_semantic_tree = new SemanticTree();
        
        SimpleLogger::log("canvas_semantic_trait_logs", __METHOD__, 
            "Semantic Tree initialized"
        );
    }
    
    /**
     * Get the semantic tree for direct access
     * 
     * Usage: $canvas->getSemanticTree()->add($id, $tag, $attrs, $display, $parentId)
     * 
     * @return SemanticTree|null The tree or null if not initialized
     */
    public function getSemanticTree(): ?SemanticTree
    {
        return $this->_semantic_tree;
    }    

    /**
     * Set current frame ID - TUNNEL to backend
     * This method forwards the frame ID directly to AccessibleTCPDF
     * 
     * @param string|null $frameId The frame ID being rendered
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        // Direct tunnel to backends, which implement this method, e.g. AccessibleTCPDF
        if (method_exists($this->_pdf, 'setCurrentFrameId')) {
            $this->_pdf->setCurrentFrameId($frameId);
        }
    }
    
    // ========================================================================
    // NEW TREE CURRENT NODE TRACKING (parallel to setCurrentFrameId!)
    // ========================================================================
    
    /**
     * Set current frame node in tree (O(1) lookup!)
     * 
     * This is called during rendering to track which node is being processed.
     * Uses the tree's HashMap for O(1) lookup instead of searching!
     * 
     * @param string|null $frameId The frame ID being rendered (null = clear)
     */
    public function setCurrentFrameNode(?string $frameId): void
    {
        // Tree not initialized? Skip silently
        if ($this->_semantic_tree === null) {
            return;
        }
        
        // Clear current node
        if ($frameId === null) {
            $this->_semantic_tree->clearCurrentNode();
            
            SimpleLogger::log("canvas_semantic_trait_logs", __METHOD__,
                "TREE: Cleared current node"
            );
            return;
        }
        
        // Set current node via O(1) HashMap lookup!
        $success = $this->_semantic_tree->setCurrentNodeById($frameId);
        
        if ($success) {
            $current = $this->_semantic_tree->getCurrentNode();
            
            SimpleLogger::log("canvas_semantic_trait_logs", __METHOD__,
                sprintf("TREE: Set current node to node_%s <%s> depth=%d",
                    $current->id,
                    $current->tag,
                    $current->getDepth()
                )
            );
        } else {
            SimpleLogger::log("canvas_semantic_trait_logs", __METHOD__,
                sprintf("TREE: WARNING - Node %s not found in tree", $frameId)
            );
        }
        
        // Tunnel to PDF backend (if it supports tree nodes)
        if (method_exists($this->_pdf, 'setCurrentFrameNode')) {
            $this->_pdf->setCurrentFrameNode($frameId);
        }
    }
}