<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;

require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';

/**
 * AccessibleTCPDF - PDF/UA compatible TCPDF extension
 *
 * This class extends TCPDF to provide PDF/UA (Universal Accessibility)
 * compliance by implementing accessibility features.
 */
class AccessibleTCPDF extends TCPDF
{   
    /**
     * Reference to semantic elements storage from Canvas
     * This is a direct reference to the $_semantic_elements array from CanvasSemanticTrait
     * @var SemanticElement[]
     */
    private array $semanticElementsRef;

    /**
     * Constructor
     * 
     * @param string $orientation Page orientation (P or L)
     * @param string $unit Unit of measure (pt, mm, cm, in)
     * @param mixed $format Page format
     * @param boolean $unicode Enable unicode
     * @param string $encoding Character encoding
     * @param boolean $diskcache Enable disk caching
     * @param boolean $pdfa Enable PDF/A mode 
     * @param boolean $pdfua Enable PDF/UA mode
     * @param array|null $semanticElementsRef Reference to semantic elements array from Canvas
     */
    public function __construct(
        $orientation = 'P', 
        $unit = 'mm', 
        $format = 'A4', 
        $unicode = true, 
        $encoding = 'UTF-8', 
        $diskcache = false, 
        $pdfa = false, 
        $pdfua = true,
        ?array &$semanticElementsRef = null
    ) 
    {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "AccessibleTCPDF initialized");
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "PDF/UA support " . ($pdfua ? "enabled" : "disabled"));
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "PDF/A support " . ($pdfa ? "enabled" : "disabled"));
        
        // Store reference to semantic elements
        if ($semanticElementsRef !== null) {
            $this->semanticElementsRef = &$semanticElementsRef;
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf(
                    "Semantic elements reference received: %d elements (OK if 0 - will be filled later)",
                    count($semanticElementsRef)
                )
            );
        } else {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "No semantic elements reference provided");
        }
    }
}