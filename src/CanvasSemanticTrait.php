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
     * Register semantic information for an element
     * 
     * This method stores semantic data that can later be accessed during
     * rendering or post-processing (e.g., for PDF/UA tagging).
     * 
     * @param string $elementId Unique identifier for the element (e.g., "frame_123")
     * @param SemanticElement|array $semanticData Either a SemanticElement object or legacy array format
     */
    public function registerSemanticElement(SemanticElement $semanticElement): void
    {
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
    }
}