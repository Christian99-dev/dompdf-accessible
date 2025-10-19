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
        // OPTIMIZATION: Skip transparent inline tags - they don't need structure elements
        if ($semanticElement->isTransparentInlineTag()) {
            SimpleLogger::log(
                "canvas_semantic_trait_logs",
                "registerSemanticElement()",
                sprintf(
                    "SKIPPED transparent tag: %s | %s (styling only, no structure)",
                    $semanticElement->id,
                    $semanticElement
                )
            );
            return;
        }
        
        $this->_semantic_elements[$semanticElement->id] = $semanticElement;
        
        SimpleLogger::log(
            "canvas_semantic_trait_logs",
            "registerSemanticElement()",
            sprintf(
                "Registered: %s | %s",
                $semanticElement->id,
                $semanticElement
            )
        );  
    }    /**
     * Set current frame ID - TUNNEL to backend
     * This method forwards the frame ID directly to AccessibleTCPDF
     * 
     * @param string|null $frameId The frame ID being rendered
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        // Direct tunnel to backends, wich implement this mehtod
        if (method_exists($this->_pdf, 'setCurrentFrameId')) {
            $this->_pdf->setCurrentFrameId($frameId);
        }
    }
}