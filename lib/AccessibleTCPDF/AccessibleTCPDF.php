<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticNode;
use Dompdf\SemanticTree;

require_once __DIR__ . '/tcpdf/tcpdf.php';

// Load Accessibility Managers
require_once __DIR__ . '/BDCStateManager.php';
require_once __DIR__ . '/DrawingManager.php';

// new tagging system
require_once __DIR__ . '/tagging/TagOps.php';
require_once __DIR__ . '/tagging/TaggingStateManager.php';
require_once __DIR__ . '/tagging/ContentProcessor.php';
require_once __DIR__ . '/tagging/TextProcessor.php';
require_once __DIR__ . '/tagging/DrawingProcessor.php';
require_once __DIR__ . '/tagging/StructureTreeBuilder.php';

// SemanticTree for node access
require_once __DIR__ . '../../../src/SemanticTree.php';

/**
 * AccessibleTCPDF - PDF/UA compatible TCPDF extension
 *
 * This class extends TCPDF to provide PDF/UA (Universal Accessibility)
 * compliance by implementing accessibility features.
 */
class AccessibleTCPDF extends TCPDF
{   
    // ========================================================================
    // MANAGER INSTANCES
    // ========================================================================
    
    /**
     * BDC State Manager - Handles BDC/EMC lifecycle and semantic resolution
     * @var BDCStateManager
     */
    private BDCStateManager $bdcManager;

    /**
     * Drawing Manager - Static helper for drawing decisions
     * @var DrawingManager
     */
    private DrawingManager $drawingManager;
    
    /**
     * Tagging State Manager - Tracks semantic and artifact BDC state
     * @var TaggingStateManager
     */
    private TaggingStateManager $taggingStateManager;
    
    /**
     * Text Processor - Handles text rendering with PDF/UA tagging
     * @var TextProcessor
     */
    private TextProcessor $textProcessor;
    
    /**
     * Drawing Processor - Handles drawing operations with PDF/UA tagging
     * @var DrawingProcessor
     */
    private DrawingProcessor $drawingProcessor;

    /**
     * Structure Tree Builder - Manages the PDF structure tree for accessibility
     * @var StructureTreeBuilder
     */
    private StructureTreeBuilder $structureTreeBuilder;

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================
    
    /**
     * Semantic tree for O(1) node access and tree navigation
     * @var SemanticTree|null
     */
    private ?SemanticTree $semanticTree = null;

    /**
     * Current frame ID being rendered
     * Stored as string, Node fetched on-demand via getNodeById()
     * @var string|null
     */
    private ?string $currentFrameId = null;
    
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
     * Saved page object IDs before Close() for structure tree building  
     * @var array
     */
    private array $savedPageObjIds = [];

    /**
     * Mapping of annotation object IDs to their details for StructTree
     * Format: [ 'obj_id' => X, 'type' => 'Link', 'text' => '...', 'url' => '...', 'struct_parent' => N ]
     * @var array
     */
    private array $annotationObjects = [];

    /**
     * Counter for /StructParent indices in annotations
     * Page uses index 0, annotations start from 1
     * @var int
     */
    private int $structParentCounter = 1;

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
     * @param SemanticTree|null $semanticTree Semantic tree for accessibility
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
        ?SemanticTree $semanticTree = null
    ) 
    {        
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        $this->pdfua = $pdfua === true && $semanticTree !== null;
        if(!$this->pdfua) return;

        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "PDF/UA mode enabled.");

        // CRITICAL: Disable "Powered by TCPDF" link (separate from footer!)
        // This is rendered in Close() method independent of Footer() mechanism
        // The extra Q operator after the link causes PDF/UA validation errors
        $this->tcpdflink = false;
        
        // PDF/UA REQUIREMENT: All fonts must be embedded (PDF/UA 7.21.4.1)
        // Force core fonts to be embedded by setting unicode flag
        $this->isunicode = true;
        
        // Replace standard fonts with DejaVu equivalents
        $this->CoreFonts = [
            'courier' => 'dejavusansmono',
            'courierB' => 'dejavusansmono',
            'courierI' => 'dejavusansmono',
            'courierBI' => 'dejavusansmono',
            'helvetica' => 'dejavusans',
            'helveticaB' => 'dejavusans',
            'helveticaI' => 'dejavusans',
            'helveticaBI' => 'dejavusans',
            'times' => 'dejavuserif',
            'timesB' => 'dejavuserif',
            'timesI' => 'dejavuserif',
            'timesBI' => 'dejavuserif',
            'symbol' => 'dejavusans',
            'zapfdingbats' => 'dejavusans'
        ];
        
        // Store semantic tree reference
        $this->semanticTree = $semanticTree;
        
        // BDC State Manager - No dependencies
        $this->bdcManager = new BDCStateManager();
        
        // Drawing Manager - Needs semantic tree
        $this->drawingManager = new DrawingManager($this->semanticTree);
        
        // Tagging State Manager - No dependencies
        $this->taggingStateManager = new TaggingStateManager();
        
        // Text Processor - No dependencies (receives state via process())
        $this->textProcessor = new TextProcessor();
        
        // Drawing Processor - No dependencies (receives state via process())
        $this->drawingProcessor = new DrawingProcessor();

        // Structure Tree Builder
        $this->structureTreeBuilder = new StructureTreeBuilder($this->semanticTree);
    }

    /**
     * Set current frame ID (called from CanvasSemanticTrait)
     * 
     * Simply stores the frameId. AccessibleTCPDF fetches the node on-demand
     * using $this->semanticTree->getNodeById($frameId) when needed.
     * 
     * @param string|null $frameId The frame ID (null = clear)
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        $this->currentFrameId = $frameId;
    }

    // ========================================================================
    // UTILS
    // ========================================================================

    /**
     * Capture PDF output from a parent method call
     * 
     * TCPDF methods output directly to buffer via _out().
     * This helper captures that output by saving current buffer state,
     * executing the callback, then extracting the new content.
     * 
     * @param callable $callback Function that outputs to PDF buffer
     * @return string The captured PDF operators
     */
    private function captureParentOutput(callable $callback): string
    {
        // Save current page buffer length
        $beforeLength = isset($this->pages[$this->page]) ? strlen($this->pages[$this->page]) : 0;
        
        // Execute the callback (outputs to buffer)
        $callback();
        
        // Extract what was added to buffer
        $afterLength = isset($this->pages[$this->page]) ? strlen($this->pages[$this->page]) : 0;
        $captured = substr($this->pages[$this->page], $beforeLength, $afterLength - $beforeLength);
        
        // Remove the captured content from buffer (we'll re-add it with tagging)
        if (isset($this->pages[$this->page])) {
            $this->pages[$this->page] = substr($this->pages[$this->page], 0, $beforeLength);
        }
        
        return $captured;
    }

    /**
     * Execute a drawing operation with PDF/UA compliance
     * 
     * This method uses DrawingManager for decision logic and wraps drawing operations accordingly.
     * 
     * @param string $operationName Name for logging (Line, Rect, etc.)
     * @param callable $drawingCallback The actual drawing operation
     * @return void
     * @protected
     */
    private function _executeDrawingOperation(string $operationName, callable $drawingCallback): void
    {
        // Non-PDF/UA mode or not on page → Draw directly
        if (!$this->pdfua || $this->page <= 0 || $this->state != 2) {
            $drawingCallback();
            return;
        }
        
        // Get decision from DrawingManager (no array parameter needed!)
        $context = $this->drawingManager->analyzeDrawingContext(
            $operationName, 
            $this->currentFrameId,  // Pass frameId, not node!
            $this->bdcManager->isInsideTaggedContent(),
            $this->bdcManager->getActiveBDCFrame()
        );
        
        // Close BDC if needed (for decorative elements that should end semantic tagging)
        if ($context['should_close_bdc']) {
            $this->_out($this->bdcManager->closeBDC());
        }
        
        // Execute drawing operation
        if ($context['wrap_as_artifact']) {
            // Get Artifact wrapper operators from DrawingManager
            $operators = $this->drawingManager->getArtifactWrapOperators($this->bdcManager->getActiveBDCFrame());
            $this->_out($operators['before']);
            $drawingCallback();
            $this->_out($operators['after']);
        } else {
            $drawingCallback();
        }
    }

    // ========================================================================
    // TCPDF METHODS OVERRIDES
    // Always check: if($this->pdfua !== true || $semantic === null) bypass to parent
    // ========================================================================

    /** ==================================== */
    /** ==== SIMPLE (STRING) INJECTIONS ==== */
    /** ==================================== */

    /**
     * Override _putXMP() to add PDF/UA identification metadata
     * Uses reflection to inject pdfuaid namespace into parent's XMP without full override
     * 
     * CLEAN: No TagOps needed - just XML string manipulation
     * 
     * @protected
     */
    protected function _putXMP()
    {
        // If not PDF/UA mode, use parent's XMP unchanged
        if (!$this->pdfua) {
            return parent::_putXMP();
        }
        
        // SMART APPROACH: Temporarily add PDF/UA metadata to custom_xmp_rdf
        // TCPDF's _putXMP() outputs custom_xmp_rdf before </rdf:RDF>, perfect for injection!
        
        $savedCustomXmpRdf = $this->custom_xmp_rdf;
        
        // Inject PDF/UA identification
        $pdfuaMetadata = "\t\t".'<rdf:Description rdf:about="" xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/">'."\n";
        $pdfuaMetadata .= "\t\t\t".'<pdfuaid:part>1</pdfuaid:part>'."\n";
        $pdfuaMetadata .= "\t\t\t".'<pdfuaid:conformance>A</pdfuaid:conformance>'."\n";
        $pdfuaMetadata .= "\t\t".'</rdf:Description>'."\n";
        
        $this->custom_xmp_rdf = $savedCustomXmpRdf . $pdfuaMetadata;
        
        // Call parent - it will include our PDF/UA metadata
        $result = parent::_putXMP();
        
        // Restore original custom_xmp_rdf
        $this->custom_xmp_rdf = $savedCustomXmpRdf;
        
        return $result;
    }

    /**
     * Override _getannotsrefs() to add /StructParents and /Tabs after annotations
     * This is cleaner than buffer manipulation and doesn't break xref table
     * 
     * CRITICAL: Adobe ignores /StructParents 0, so we use 1
     * FIX 3: /Tabs /S activates reading order in Adobe and PAC
     * 
     * CLEAN: No TagOps needed - just string concatenation
     * 
     * @protected
     */
    protected function _getannotsrefs($n)
    {
        // Get parent's annotation refs
        $annots = parent::_getannotsrefs($n);
        
        // If PDF/UA mode, add /StructParents and /Tabs after annotations
        if ($this->pdfua && !$this->structureTreeBuilder->isEmpty()) {
            // Page's /StructParents maps to ParentTree for resolving MCIDs
            // CRITICAL: Adobe ignores /StructParents 0, so we use 1
            $annots .= ' /StructParents 1';
            // FIX 3: /Tabs /S tells Adobe to follow structure for reading order
            $annots .= ' /Tabs /S';
        }
        
        return $annots;
    }

    /**
     * Override Annotation() to:
     * 1. Add /Contents to Link annotations (PDF/UA 7.18.5 requirement)
     * 2. Track annotations for StructTree
     * 
     * This is THE ONLY place we need to intervene for PDF/UA Link compliance!
     * By setting $opt['Contents'] HERE, parent::Annotation() will include it automatically.
     * 
     * CLEAN: No TagOps needed - just metadata manipulation
     * 
     * @public
     */
    public function Annotation($x, $y, $w, $h, $text, $opt=array('Subtype'=>'Text'), $spaces=0)
    {
        // PDF/UA: Add /Contents to Link annotations (required for screenreaders)
        if ($this->pdfua && isset($opt['Subtype']) && $opt['Subtype'] === 'Link') {
            // Extract URL for Contents text
            $url = '';
            if (isset($opt['A']['S']) && $opt['A']['S'] === 'URI' && isset($opt['A']['URI'])) {
                $url = $opt['A']['URI'];
            } elseif (is_array($text) && isset($text['url'])) {
                $url = $text['url'];
            } elseif (is_string($text) && strpos($text, 'http') === 0) {
                $url = $text;
            }
            
            // CRITICAL: Set Contents in $opt BEFORE calling parent
            // Parent's _putannotsobjs() will then include it automatically
            if (!isset($opt['Contents']) && !empty($url)) {
                $opt['Contents'] = 'Link to ' . $url;
            }
        }
        
        // Call parent to create the annotation (will use our modified $opt)
        parent::Annotation($x, $y, $w, $h, $text, $opt, $spaces);
        
        // Track Link annotations for StructTree (PDF/UA requirement)
        if ($this->pdfua && isset($opt['Subtype']) && $opt['Subtype'] === 'Link') {
            // Get the annotation object ID (last entry in PageAnnots)
            $page = $this->page;
            if (isset($this->PageAnnots[$page])) {
                $lastAnnot = end($this->PageAnnots[$page]);
                if ($lastAnnot && isset($lastAnnot['n'])) {
                    // Extract URL
                    $url = '';
                    if (isset($opt['A']['S']) && $opt['A']['S'] === 'URI' && isset($opt['A']['URI'])) {
                        $url = $opt['A']['URI'];
                    } elseif (is_array($text) && isset($text['url'])) {
                        $url = $text['url'];
                    } elseif (is_string($text) && strpos($text, 'http') === 0) {
                        $url = $text;
                    }
                    
                    $this->annotationObjects[] = [
                        'obj_id' => $lastAnnot['n'],
                        'type' => 'Link',
                        'text' => is_string($text) ? $text : 'Link',
                        'url' => $url,
                        'page' => $page,
                        'struct_parent' => $this->structParentCounter++
                    ];
                }
            }
        }
    }
    
    /**
     * Override _putannotsobjs() to add /StructParent to Link annotations
     * 
     * MINIMAL override: Use parent for everything, then post-process PageAnnots
     * to inject /StructParent into Link annotations before parent outputs them.
     * 
     * CLEAN: No TagOps needed - just array manipulation
     * 
     * @protected
     */
    protected function _putannotsobjs()
    {
        // PDF/UA FIX: Inject /StructParent into Link annotations BEFORE parent processes them
        if ($this->pdfua) {
            foreach ($this->annotationObjects as $annot) {
                $page = $annot['page'];
                if (isset($this->PageAnnots[$page])) {
                    // Find the annotation in PageAnnots by obj_id
                    foreach ($this->PageAnnots[$page] as $key => $pageAnnot) {
                        if ($pageAnnot['n'] === $annot['obj_id']) {
                            // Inject /StructParent into opt array
                            // TCPDF's _putannotsobjs() will read this and include it in PDF output
                            if (!isset($this->PageAnnots[$page][$key]['opt']['StructParent'])) {
                                $this->PageAnnots[$page][$key]['opt']['StructParent'] = $annot['struct_parent'];
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        // Call parent - it will now output our injected /StructParent values
        parent::_putannotsobjs();
    }
    
    /**
     * Override SetFont to redirect Base 14 fonts to PDF/UA compliant alternatives
     * 
     * CLEAN: No TagOps needed - just font mapping logic
     * 
     * @param string $family Font family
     * @param string $style Font style
     * @param float $size Font size
     * @param string $fontfile Font file path
     * @param string $subset Subset mode
     * @param boolean $out Output font command
     * @public
     */
    public function SetFont($family, $style='', $size=null, $fontfile='', $subset='default', $out=true) {
        if ($this->pdfua) {
            // Map Base 14 fonts to PDF/UA compliant DejaVu equivalents
            static $fontMap = [
                'helvetica' => 'dejavusans',
                'times' => 'dejavuserif',
                'courier' => 'dejavusansmono'
            ];
            
            $familyLower = strtolower($family);
            if (isset($fontMap[$familyLower])) {
                error_log("[PDF/UA Font Mapping] $family → {$fontMap[$familyLower]}");
                $family = $fontMap[$familyLower];
            }
        }
        
        parent::SetFont($family, $style, $size, $fontfile, $subset, $out);
    }

    /**
     * Override _endpage to wrap final graphics operations before page closes
     * 
     * This is called by endPage() BEFORE state changes to 1.
     * Perfect place to inject final Artifact wrapper.
     * 
     * REFACTORED: Now uses TagOps for clean operator generation
     * 
     * @protected
     */
    protected function _endpage() {
        // PDF/UA FIX: Close any open BDC block BEFORE wrapping page-end graphics
        if ($this->pdfua && $this->taggingStateManager->hasAnyTaggingState() && $this->page > 0 && $this->state == 2) {
            $this->_out("\n" . $this->taggingStateManager->closeCurrentTag());
        }
        
        // PDF/UA FIX: Wrap any remaining page content as Artifact
        if ($this->pdfua && $this->page > 0 && $this->state == 2) {
            $this->_out("\n" . TagOps::artifactOpen());
        }
        
        // Call parent (sets state = 1)
        parent::_endpage();
        
        // PDF/UA FIX: Close Artifact after state change
        // We need to manually output EMC because state is now 1
        if ($this->pdfua && $this->page > 0) {
            // Manually append to page buffer since state is already 1
            if (isset($this->pages[$this->page])) {
                $this->pages[$this->page] .= TagOps::artifactClose();
            }
        }
    }

    /**
     * CLEAN: No TagOps needed - just PDF object string building
     * 
     * @protected
     */
    protected function _putcatalog()
    {
        /** 
         * === PATCH HISTORY of _putcatalog() for PDF/UA compliance ===
         * 
         * PDF/UA PATCH 1/2: Add DisplayDocTitle to viewer preferences
         * PDF/UA PATCH 2/2: Add Tagged PDF support (StructTreeRoot, MarkInfo)
        */

        // ===============================================================================================
        // === tcpdf->_putcatalog() PATCH 1/2: Add DisplayDocTitle to viewer preferences =================
        // ===============================================================================================
        if ($this->pdfua) {
            if (!isset($this->viewer_preferences)) {
                $this->viewer_preferences = [];
            }
            $this->viewer_preferences['DisplayDocTitle'] = 'true';
        }
        // ===============================================================================================
        
        // put XMP
        $xmpobj = $this->_putXMP();
        // if required, add standard sRGB ICC colour profile
        if ($this->pdfa_mode OR $this->force_srgb) {
            $iccobj = $this->_newobj();
            $icc = file_get_contents(dirname(__FILE__).'/tcpdf/include/sRGB.icc');
            $filter = '';
            if ($this->compress) {
                $filter = ' /Filter /FlateDecode';
                $icc = gzcompress($icc);
            }
            $icc = $this->_getrawstream($icc);
            $this->_out('<</N 3 '.$filter.'/Length '.strlen($icc).'>> stream'."\n".$icc."\n".'endstream'."\n".'endobj');
        }
        // start catalog
        $oid = $this->_newobj();
        $out = '<< ';
        if (!empty($this->efnames)) {
            $out .= ' /AF [ '. implode(' ', $this->efnames) .' ]';
        }
        $out .= ' /Type /Catalog';
        $out .= ' /Version /'.$this->PDFVersion;
        //$out .= ' /Extensions <<>>';
        $out .= ' /Pages 1 0 R';
        //$out .= ' /PageLabels ' //...;
        $out .= ' /Names <<';
        if ((!$this->pdfa_mode) AND !empty($this->n_js)) {
            $out .= ' /JavaScript '.$this->n_js;
        }
        if (!empty($this->efnames)) {
            $out .= ' /EmbeddedFiles <</Names [';
            foreach ($this->efnames AS $fn => $fref) {
                $out .= ' '.$this->_datastring($fn).' '.$fref;
            }
            $out .= ' ]>>';
        }
        $out .= ' >>';
        if (!empty($this->dests)) {
            $out .= ' /Dests '.($this->n_dests).' 0 R';
        }
        $out .= $this->_putviewerpreferences();
        if (isset($this->LayoutMode) AND (!TCPDF_STATIC::empty_string($this->LayoutMode))) {
            $out .= ' /PageLayout /'.$this->LayoutMode;
        }
        if (isset($this->PageMode) AND (!TCPDF_STATIC::empty_string($this->PageMode))) {
            $out .= ' /PageMode /'.$this->PageMode;
        }
        if (count($this->outlines) > 0) {
            $out .= ' /Outlines '.$this->OutlineRoot.' 0 R';
            $out .= ' /PageMode /UseOutlines';
        }
        //$out .= ' /Threads []';
        if ($this->ZoomMode == 'fullpage') {
            $out .= ' /OpenAction ['.$this->page_obj_id[1].' 0 R /Fit]';
        } elseif ($this->ZoomMode == 'fullwidth') {
            $out .= ' /OpenAction ['.$this->page_obj_id[1].' 0 R /FitH null]';
        } elseif ($this->ZoomMode == 'real') {
            $out .= ' /OpenAction ['.$this->page_obj_id[1].' 0 R /XYZ null null 1]';
        } elseif (!is_string($this->ZoomMode)) {
            $out .= sprintf(' /OpenAction ['.$this->page_obj_id[1].' 0 R /XYZ null null %F]', ($this->ZoomMode / 100));
        }
        //$out .= ' /AA <<>>';
        //$out .= ' /URI <<>>';
        $out .= ' /Metadata '.$xmpobj.' 0 R';


        // ===============================================================================================
        // === tcpdf->_putcatalog() PATCH 2/2: Add StructTreeRoot, MarkInfo, and Lang ====================
        // ===============================================================================================
        if ($this->pdfua) {
            $structTreeRootObjId = $this->structureTreeBuilder->getStructureObjId();
            if(!$this->structureTreeBuilder->isEmpty() && $structTreeRootObjId !== null) {
                $out .= ' /StructTreeRoot ' . $structTreeRootObjId . ' 0 R';
                $out .= ' /MarkInfo << /Marked true >>';
            }
        
            $out .= ' /Lang '.$this->_textstring('en-US', $oid);  // PDF/UA requires language
        } 
        // === ELSE: TCPDF's standard language handling ===
        elseif (isset($this->l['a_meta_language'])) {
            $out .= ' /Lang '.$this->_textstring($this->l['a_meta_language'], $oid);
        }
        // Note: TCPDF has /StructTreeRoot and /MarkInfo commented out in original code
        // ===============================================================================================
        
        // set OutputIntent to sRGB IEC61966-2.1 if required
        if ($this->pdfa_mode OR $this->force_srgb) {
            $out .= ' /OutputIntents [<<';
            $out .= ' /Type /OutputIntent';
            $out .= ' /S /GTS_PDFA1';
            $out .= ' /OutputCondition '.$this->_textstring('sRGB IEC61966-2.1', $oid);
            $out .= ' /OutputConditionIdentifier '.$this->_textstring('sRGB IEC61966-2.1', $oid);
            $out .= ' /RegistryName '.$this->_textstring('http://www.color.org', $oid);
            $out .= ' /Info '.$this->_textstring('sRGB IEC61966-2.1', $oid);
            $out .= ' /DestOutputProfile '.$iccobj.' 0 R';
            $out .= ' >>]';
        }
        //$out .= ' /PieceInfo <<>>';
        if (!empty($this->pdflayers)) {
            $lyrobjs = '';
            $lyrobjs_off = '';
            $lyrobjs_lock = '';
            foreach ($this->pdflayers as $layer) {
                $layer_obj_ref = ' '.$layer['objid'].' 0 R';
                $lyrobjs .= $layer_obj_ref;
                if ($layer['view'] === false) {
                    $lyrobjs_off .= $layer_obj_ref;
                }
                if ($layer['lock']) {
                    $lyrobjs_lock .= $layer_obj_ref;
                }
            }
            $out .= ' /OCProperties << /OCGs ['.$lyrobjs.']';
            $out .= ' /D <<';
            $out .= ' /Name '.$this->_textstring('Layers', $oid);
            $out .= ' /Creator '.$this->_textstring('TCPDF', $oid);
            $out .= ' /BaseState /ON';
            $out .= ' /OFF ['.$lyrobjs_off.']';
            $out .= ' /Locked ['.$lyrobjs_lock.']';
            $out .= ' /Intent /View';
            $out .= ' /AS [';
            $out .= ' << /Event /Print /OCGs ['.$lyrobjs.'] /Category [/Print] >>';
            $out .= ' << /Event /View /OCGs ['.$lyrobjs.'] /Category [/View] >>';
            $out .= ' ]';
            $out .= ' /Order ['.$lyrobjs.']';
            $out .= ' /ListMode /AllPages';
            //$out .= ' /RBGroups ['..']';
            //$out .= ' /Locked ['..']';
            $out .= ' >>';
            $out .= ' >>';
        }
        // AcroForm
        if (!empty($this->form_obj_id)
            OR ($this->sign AND isset($this->signature_data['cert_type']))
            OR !empty($this->empty_signature_appearance)) {
            $out .= ' /AcroForm <<';
            $objrefs = '';
            if ($this->sign AND isset($this->signature_data['cert_type'])) {
                // set reference for signature object
                $objrefs .= $this->sig_obj_id.' 0 R';
            }
            if (!empty($this->empty_signature_appearance)) {
                foreach ($this->empty_signature_appearance as $esa) {
                    // set reference for empty signature objects
                    $objrefs .= ' '.$esa['objid'].' 0 R';
                }
            }
            if (!empty($this->form_obj_id)) {
                foreach($this->form_obj_id as $objid) {
                    $objrefs .= ' '.$objid.' 0 R';
                }
            }
            $out .= ' /Fields ['.$objrefs.']';
            // It's better to turn off this value and set the appearance stream for each annotation (/AP) to avoid conflicts with signature fields.
            if (empty($this->signature_data['approval']) OR ($this->signature_data['approval'] != 'A')) {
                $out .= ' /NeedAppearances false';
            }
            if ($this->sign AND isset($this->signature_data['cert_type'])) {
                if ($this->signature_data['cert_type'] > 0) {
                    $out .= ' /SigFlags 3';
                } else {
                    $out .= ' /SigFlags 1';
                }
            }
            //$out .= ' /CO ';
            if (isset($this->annotation_fonts) AND !empty($this->annotation_fonts)) {
                $out .= ' /DR <<';
                $out .= ' /Font <<';
                foreach ($this->annotation_fonts as $fontkey => $fontid) {
                    $out .= ' /F'.$fontid.' '.$this->font_obj_ids[$fontkey].' 0 R';
                }
                $out .= ' >> >>';
            }
            $font = $this->getFontBuffer((($this->pdfa_mode) ? 'pdfa' : '') .'helvetica');
            $out .= ' /DA ' . $this->_datastring('/F'.$font['i'].' 0 Tf 0 g');
            $out .= ' /Q '.(($this->rtl)?'2':'0');
            //$out .= ' /XFA ';
            $out .= ' >>';
            // signatures
            if ($this->sign AND isset($this->signature_data['cert_type'])
                AND (empty($this->signature_data['approval']) OR ($this->signature_data['approval'] != 'A'))) {
                if ($this->signature_data['cert_type'] > 0) {
                    $out .= ' /Perms << /DocMDP '.($this->sig_obj_id + 1).' 0 R >>';
                } else {
                    $out .= ' /Perms << /UR3 '.($this->sig_obj_id + 1).' 0 R >>';
                }
            }
        }
        $out .= ' >>';
        $out .= "\n".'endobj';
        $this->_out($out);
        return $oid;
    }

    /** ==================================== */
    /** ==== STATE DEPENDENT INJECTIONS ==== */
    /** ==================================== */

    // /**
    //  * Override setExtGState to wrap ExtGState operations as Artifacts
    //  */
    protected function setExtGState($gs) {
        // PDF/UA: Wrap ExtGState operations as Artifact if inside tagged content
        if ($this->pdfua === false || $this->taggingStateManager->hasAnyTaggingState()) {
            parent::setExtGState($gs);
            return;
        }

        if ($this->page > 0 && $this->state == 2) {
            $this->_out(TagOps::artifactOpen());
            parent::setExtGState($gs);
            $this->_out(TagOps::artifactClose());
        } else {
            parent::setExtGState($gs);
        }
    }
    
    /**
     * Override StartTransform to prevent untagged 'q' operator
     */
    public function StartTransform() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'q'
        if ($this->pdfua && $this->taggingStateManager->hasAnyTaggingState()) {
            // Track transform matrix but don't output 'q'
            if ($this->state != 2) {
                return;
            }
            if ($this->inxobj) {
                $this->xobjects[$this->xobjid]['transfmrk'][] = strlen($this->xobjects[$this->xobjid]['outdata']);
            } else {
                $this->transfmrk[$this->page][] = $this->pagelen[$this->page];
            }
            ++$this->transfmatrix_key;
            $this->transfmatrix[$this->transfmatrix_key] = array();
            return;
        }
        
        parent::StartTransform();
    }
    
    /**
     * Override StopTransform to prevent untagged 'Q' operator
     */
    public function StopTransform() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'Q'
        if ($this->pdfua && $this->taggingStateManager->hasAnyTaggingState()) {
            // Restore transform matrix but don't output 'Q'
            if ($this->state != 2) {
                return;
            }
            if (isset($this->transfmatrix[$this->transfmatrix_key])) {
                array_pop($this->transfmatrix[$this->transfmatrix_key]);
                --$this->transfmatrix_key;
                if (isset($this->transfmrk[$this->page]) AND isset($this->transfmrk[$this->page][$this->transfmatrix_key])) {
                    $this->transfmrk[$this->page][$this->transfmatrix_key] = strlen($this->pages[$this->page]);
                } elseif ($this->inxobj) {
                    if (isset($this->xobjects[$this->xobjid]['transfmrk'][$this->transfmatrix_key])) {
                        $this->xobjects[$this->xobjid]['transfmrk'][$this->transfmatrix_key] = strlen($this->xobjects[$this->xobjid]['outdata']);
                    }
                }
            }
            return;
        }
        
        parent::StopTransform();
    }
    
    /**
     * Override _outSaveGraphicsState to prevent untagged 'q' operator
     * 
     * @protected
     */
    protected function _outSaveGraphicsState() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'q'
        if ($this->pdfua && $this->taggingStateManager->hasAnyTaggingState()) {
            return; // Suppress 'q' output
        }
        
        parent::_outSaveGraphicsState();
    }
    
    // /**
    //  * Override _outRestoreGraphicsState to prevent untagged 'Q' operator
    //  * 
    //  * @protected
    //  */
    protected function _outRestoreGraphicsState() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'Q'
        if ($this->pdfua && $this->taggingStateManager->hasAnyTaggingState()) {
            return; // Suppress 'Q' output
        }
        
        parent::_outRestoreGraphicsState();
    }

    /**
     * Override _putresources() to output structure tree objects BEFORE catalog
     * This ensures xref table is correctly built
     */
    protected function _putresources()
    {
        // CRITICAL: Close any open BDC block before finalizing the document
        if ($this->pdfua && $this->taggingStateManager->hasAnyTaggingState()) {
            // Inject EMC into the current page buffer
            if ($this->state == 2 && isset($this->page)) {
                $this->setPageBuffer($this->page, $this->taggingStateManager->closeCurrentTag(), true);
            }
        }
        
        // Call parent first to output all standard resources
        parent::_putresources();
        
        // Now output our structure tree objects (if PDF/UA mode is enabled)
        if ($this->pdfua && !$this->structureTreeBuilder->isEmpty()) {
            // Save page_obj_id now that _putpages() has been called
            if (property_exists($this, 'page_obj_id') && isset($this->page_obj_id) && is_array($this->page_obj_id)) {
                $this->savedPageObjIds = $this->page_obj_id;
                
                // BUILD structure tree
                $structureTreeData = $this->structureTreeBuilder->build(
                    $this->n,
                    $this->savedPageObjIds,
                    $this->annotationObjects
                );

                // Output structure tree objects
                if ($structureTreeData !== null && isset($structureTreeData['strings'])) {
                    foreach ($structureTreeData['strings'] as $structString) {
                        $this->_out($structString);
                    }
                }
            }
        }
    }
    
    /** ================================ */
    /** ==== TEXT / DRAW OPERATIONS ==== */
    /** ================================ */

    /**
     * Override getCellCode() to add PDF/UA tagging via Unified Architecture
     * 
     * ARCHITECTURE: Single Manager, Single Call
     * ==========================================
     * 
     * BDCStateManager handles EVERYTHING:
     * - Semantic resolution (Frame ID → SemanticNode)
     * - Tagging decision (Artifact, Tagged, Transparent)
     * - BDC lifecycle (when to open/close)
     * - PDF operator generation (BDC/EMC/Artifact)
     * 
     * This eliminates all architectural complexity:
     * - No TaggingManager (merged into BDCStateManager)
     * - No BDCAction enum (direct PDF code output)
     * - No match expression (handled internally)
     * - Single method call: processFrame()
     * 
     * @param float $w Cell width
     * @param float $h Cell height
     * @param string $txt Text string
     * @param ... (all other TCPDF Cell parameters)
     * @return string PDF code with BDC/EMC tagging
     */
    protected function getCellCode($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='', $stretch=0, $ignore_min_height=false, $calign='T', $valign='M') {
        // Get original cell code from parent TCPDF
        $cellCode = parent::getCellCode($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);    
        
        // If not PDF/UA mode, return as-is
        if ($this->pdfua !== true) {
            return $cellCode;
        }
        
        // Use TextProcessor to handle tagging
        return $this->textProcessor->process(
            $this->currentFrameId,
            $this->taggingStateManager,
            $this->semanticTree,
            fn() => $cellCode
        );  
    }

    /**
     * Override Line() using universal drawing pattern
     */
    public function Line($x1, $y1, $x2, $y2, $style=array()) {
        // If not PDF/UA mode, call parent directly
        if ($this->pdfua !== true) {
            parent::Line($x1, $y1, $x2, $y2, $style);
            return;
        }
        
        // Use DrawingProcessor to handle tagging
        $output = $this->drawingProcessor->process(
            $this->currentFrameId,
            $this->taggingStateManager,
            $this->semanticTree,
            fn() => $this->captureParentOutput(
                fn() => parent::Line($x1, $y1, $x2, $y2, $style)
            )
        );
        
        $this->_out($output);
    }

    /**
     * Override Rect() using universal drawing pattern
     */
    public function Rect($x, $y, $w, $h, $style='', $border_style=array(), $fill_color=array()) {
        // If not PDF/UA mode, call parent directly
        if ($this->pdfua !== true) {
            parent::Rect($x, $y, $w, $h, $style, $border_style, $fill_color);
            return;
        }
        
        // Use DrawingProcessor to handle tagging
        $output = $this->drawingProcessor->process(
            $this->currentFrameId,
            $this->taggingStateManager,
            $this->semanticTree,
            fn() => $this->captureParentOutput(
                fn() => parent::Rect($x, $y, $w, $h, $style, $border_style, $fill_color)
            )
        );
        
        $this->_out($output);
    }

    /**
     * Override Circle() using universal drawing pattern
     */
    public function Circle($x0, $y0, $r, $angstr=0, $angend=360, $style='', $line_style=array(), $fill_color=array(), $nc=2) {
        // If not PDF/UA mode, call parent directly
        if ($this->pdfua !== true) {
            parent::Circle($x0, $y0, $r, $angstr, $angend, $style, $line_style, $fill_color, $nc);
            return;
        }
        
        // Use DrawingProcessor to handle tagging
        $output = $this->drawingProcessor->process(
            $this->currentFrameId,
            $this->taggingStateManager,
            $this->semanticTree,
            fn() => $this->captureParentOutput(
                fn() => parent::Circle($x0, $y0, $r, $angstr, $angend, $style, $line_style, $fill_color, $nc)
            )
        );
        
        $this->_out($output);
    }

    /**
     * Override Ellipse() using universal drawing pattern
     */
    public function Ellipse($x0, $y0, $rx, $ry=0, $angle=0, $astart=0, $afinish=360, $style='', $line_style=array(), $fill_color=array(), $nc=2) {
        // If not PDF/UA mode, call parent directly
        if ($this->pdfua !== true) {
            parent::Ellipse($x0, $y0, $rx, $ry, $angle, $astart, $afinish, $style, $line_style, $fill_color, $nc);
            return;
        }
        
        // Use DrawingProcessor to handle tagging
        $output = $this->drawingProcessor->process(
            $this->currentFrameId,
            $this->taggingStateManager,
            $this->semanticTree,
            fn() => $this->captureParentOutput(
                fn() => parent::Ellipse($x0, $y0, $rx, $ry, $angle, $astart, $afinish, $style, $line_style, $fill_color, $nc)
            )
        );
        
        $this->_out($output);
    }

    /**
     * Override Polygon() using universal drawing pattern
     */
    public function Polygon($p, $style='', $line_style=array(), $fill_color=array(), $closed=true) {
        // If not PDF/UA mode, call parent directly
        if ($this->pdfua !== true) {
            parent::Polygon($p, $style, $line_style, $fill_color, $closed);
            return;
        }
        
        // Use DrawingProcessor to handle tagging
        $output = $this->drawingProcessor->process(
            $this->currentFrameId,
            $this->taggingStateManager,
            $this->semanticTree,
            fn() => $this->captureParentOutput(
                fn() => parent::Polygon($p, $style, $line_style, $fill_color, $closed)
            )
        );
        
        $this->_out($output);
    }
}