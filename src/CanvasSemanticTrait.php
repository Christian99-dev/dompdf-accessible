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
     * Storage for semantic information
     * Maps element IDs to their SemanticElement objects
     * ONLY contains elements that should get StructElems in PDF!
     * 
     * @var SemanticElement[]
     */
    protected array $_semantic_elements = [];
    
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
}