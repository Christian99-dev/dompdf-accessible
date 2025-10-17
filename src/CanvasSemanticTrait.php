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
     * Maps element IDs to their semantic data
     * 
     * Structure:
     * [
     *   'frame_123' => [
     *     'tag' => 'h1',
     *     'attributes' => ['id' => 'title', 'class' => 'main'],
     *     'frame_id' => 123,
     *     'display' => 'block',
     *   ],
     *   ...
     * ]
     * 
     * @var array<string, array>
     */
    protected $_semantic_elements = [];
    
    /**
     * Register semantic information for an element
     * 
     * This method stores semantic data that can later be accessed during
     * rendering or post-processing (e.g., for PDF/UA tagging).
     * 
     * @param string $elementId Unique identifier for the element (e.g., "frame_123")
     * @param array $semanticData Semantic information with keys:
     *                            - 'tag': string (HTML tag name, e.g., 'h1', 'p', 'img')
     *                            - 'attributes': array (HTML attributes, e.g., ['id' => 'foo', 'class' => 'bar'])
     *                            - 'frame_id': int (Dompdf Frame ID)
     *                            - 'display': string (CSS display value, e.g., 'block', 'inline')
     *                            - Additional custom keys as needed
     */
    public function registerSemanticElement(string $elementId, array $semanticData): void
    {
        $this->_semantic_elements[$elementId] = $semanticData;
        
        SimpleLogger::log(
            "canvas_semantic_trait_logs",
            __METHOD__,
            sprintf(
                "Registered: %s | Tag: %s | Attrs: %s",
                $elementId,
                $semanticData['tag'] ?? 'unknown',
                json_encode($semanticData['attributes'] ?? [])
            )
        );
    }
}