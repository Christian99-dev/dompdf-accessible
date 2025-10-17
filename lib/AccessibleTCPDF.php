<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticElement;

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
     * @var SemanticElement[]|null
     */
    private ?array $semanticElementsRef = null;
    
    /**
     * Current frame ID being rendered
     * @var string|null
     */
    private ?string $currentFrameId = null;
    
    /**
     * Structure tree elements
     * @var array
     */
    private array $structureTree = [];
    
    /**
     * Current MCID (Marked Content ID) counter
     * @var int
     */
    private int $mcidCounter = 0;

    /**
     * PDF/UA mode enabled
     * @var boolean
     */
    private bool $pdfua = false;

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
        $pdfua = false,
        ?array &$semanticElementsRef = null
    ) 
    {        
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);

        if($pdfua && $semanticElementsRef !== null) {
            $this->pdfua = true;
        }

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

    // ------------------------ 
    // --- Frame management --- 
    // ------------------------ 

    /**
     * Set the current frame ID being rendered
     * 
     * @param string|null $frameId The frame ID
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        $this->currentFrameId = $frameId;
        
        if ($frameId !== null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Current frame ID set to: %s", $frameId)
            );
        }
    }
    
    /**
     * Get the current semantic element being rendered
     * 
     * @return SemanticElement|null
     */
    private function _getCurrentSemanticElement(): ?SemanticElement
    {
        if ($this->semanticElementsRef === null || $this->currentFrameId === null) {
            return null;
        }
        
        return $this->semanticElementsRef[$this->currentFrameId] ?? null;
    }

    // ------------------------- 
    // --- Private Utilities ---
    // -------------------------
    /**
     * Start marked content with proper PDF tagging
     * 
     * @param SemanticElement $semantic The semantic element
     */
    private function _startMarkedContent(SemanticElement $semantic): void
    {
        $tag = $semantic->getPdfStructureTag();
        $mcid = $this->mcidCounter++;
        
        // Build properties dictionary
        $properties = sprintf('/MCID %d', $mcid);
        
        // Add alt text for images
        if ($semantic->isImage() && $semantic->hasAltText()) {
            $altText = \TCPDF_STATIC::_escape($semantic->getAltText());
            $properties .= sprintf(' /Alt (%s)', $altText);
        }
        
        // Use BDC (Begin Dictionary Content) for tagged content
        $command = sprintf('/%s << %s >> BDC', $tag, $properties);
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Starting marked content: %s", $command)
        );
        
        // CRITICAL FIX: Inject directly into page buffer instead of using _out()
        // _out() doesn't work properly within text rendering context
        $this->_injectIntoPageBuffer($command . "\n");
        
        // Track in structure tree
        $this->structureTree[] = [
            'tag' => $tag,
            'mcid' => $mcid,
            'semantic' => $semantic,
            'page' => $this->page
        ];
    }
    
    /**
     * End marked content
     */
    private function _endMarkedContent(): void
    {
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "Ending marked content");
        
        // CRITICAL FIX: Inject directly into page buffer
        $this->_injectIntoPageBuffer("EMC\n");
    }
    
    /**
     * Inject content directly into the current page buffer
     * 
     * @param string $content The content to inject
     */
    private function _injectIntoPageBuffer(string $content): void
    {
        // Get current page buffer
        $pageBuffer = $this->getPageBuffer($this->page);
        
        // Append content to page buffer
        $this->setPageBuffer($this->page, $pageBuffer . $content);
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Injected into page buffer: %s", trim($content))
        );
    }

    // ---------------------------- 
    // --- TCPDF Core Override  --- 
    // ----------------------------
    // All following methods override TCPDF methods to inject accessibility features.
    // PLease put a if($this->pdfua !== true) return parent::Text(....) to bypass when not in PDF/UA mode.
    

    /**
     * Override Text() to add PDF tagging for accessibility
     */
    public function Text($x, $y, $txt, $fstroke = false, $fclip = false, $ffill = true, $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M', $rtloff = false)
    {
        if($this->pdfua !== true) return parent::Text($x, $y, $txt, $fstroke, $fclip, $ffill, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign, $rtloff);
        
        // Get current semantic element
        $semantic = $this->_getCurrentSemanticElement();
        
        // Log it
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf(
                "Text: \"%s\" | Semantic: %s",
                substr($txt, 0, 50) . (strlen($txt) > 50 ? '...' : ''),
                $semantic ? (string)$semantic : 'NONE',
                $semantic->isDecorative() ? ' (decorative)' : ''
            )
        );
        
        // If we have semantic information, wrap text in tagged content
        if ($semantic !== null && !$semantic->isDecorative()) {
            $this->_startMarkedContent($semantic);
        }
        
        // Call parent to actually render
        $result = parent::Text($x, $y, $txt, $fstroke, $fclip, $ffill, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign, $rtloff);
        
        // End marked content if we started it
        if ($semantic !== null && !$semantic->isDecorative()) {
            $this->_endMarkedContent();
        }
        
        return $result;
    }
}