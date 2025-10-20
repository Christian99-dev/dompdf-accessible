<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticElement;

require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';

// Load Accessibility Managers
require_once __DIR__ . '/AccessibleTCPDF/BDCAction.php';
require_once __DIR__ . '/AccessibleTCPDF/BDCStateManager.php';
require_once __DIR__ . '/AccessibleTCPDF/TaggingManager.php';
require_once __DIR__ . '/AccessibleTCPDF/ContentWrapperManager.php';
require_once __DIR__ . '/AccessibleTCPDF/DrawingManager.php';

/**
 * AccessibleTCPDF - PDF/UA compatible TCPDF extension
 *
 * This class extends TCPDF to provide PDF/UA (Universal Accessibility)
 * compliance by implementing accessibility features.
 */
class AccessibleTCPDF extends TCPDF
{   
    // ========================================================================
    // MANAGER INSTANCES - Microservice Architecture
    // ========================================================================
    
    /**
     * BDC State Manager - Handles BDC/EMC lifecycle
     * @var BDCStateManager
     */
    private BDCStateManager $bdcManager;
    
    /**
     * Tagging Manager - Resolves semantic elements to PDF tags
     * @var TaggingManager
     */
    private TaggingManager $taggingManager;
    
    /**
     * Content Wrapper Manager - Font injection & Artifact wrapping
     * @var ContentWrapperManager
     */
    private ContentWrapperManager $contentWrapper;
    

    
    // ========================================================================
    // LEGACY STATE (will be moved to managers)
    // ========================================================================
    
    /**
     * Reference to semantic elements storage from Canvas
     * This is a direct reference to the $_semantic_elements array from CanvasSemanticTrait
     * @var SemanticElement[]|null
     */
    private ?array $semanticElementsRef = null;
    
    /**
     * Reference to transparent elements storage from Canvas
     * This is a direct reference to the $_transparent_elements array from CanvasSemanticTrait
     * @var SemanticElement[]|null
     */
    private ?array $transparentElementsRef = null;
    
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
     * Saved page object IDs before Close() for structure tree building  
     * @var array
     */
    private array $savedPageObjIds = [];

    /**
     * Saved StructTreeRoot object ID for catalog modification
     * @var int|null
     */
    private ?int $savedStructTreeRootObjId = null;

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
     * @param array|null $semanticElementsRef Reference to semantic elements array from Canvas
     * @param array|null $transparentElementsRef Reference to transparent elements array from Canvas
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
        ?array &$semanticElementsRef = null,
        ?array &$transparentElementsRef = null
    ) 
    {        
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        $this->pdfua = $pdfua === true && $semanticElementsRef !== null;
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
        
        // Store reference to semantic elements from Canvas
        $this->semanticElementsRef = &$semanticElementsRef;
        $this->transparentElementsRef = &$transparentElementsRef;
        
        // BDC State Manager - No dependencies
        $this->bdcManager = new BDCStateManager();
        
        // Tagging Manager - Needs semantic elements reference
        $this->taggingManager = new TaggingManager($this->semanticElementsRef);
        
        // Content Wrapper Manager - No dependencies
        $this->contentWrapper = new ContentWrapperManager();
        
        // Drawing Manager is static - no initialization needed
    }

    /**
     * Set the current frame ID being rendered
     * This is called from the Renderer/Canvas, so that AccessibleTCPDF knows
     * which frame is currently being processed.
     * 
     * @param string|null $frameId The frame ID
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        $this->currentFrameId = $frameId;
    }

    // ========================================================================
    // UTILS
    // These methods provide a helper functions inside this class
    // ========================================================================

    /**
     * Build the structure tree from collected semantic elements
     * Uses _newobj() to properly register objects in xref table
     * 
     * @return array|null Array with 'struct_tree_root_obj_id', or null if no structure
     */
    private function _createStructureTree(): ?array
    {
        if (empty($this->structureTree)) {
            return null;
        }
        
        // Group structure elements by page
        $pageStructures = [];
        foreach ($this->structureTree as $struct) {
            $page = $struct['page'];
            if (!isset($pageStructures[$page])) {
                $pageStructures[$page] = [];
            }
            $pageStructures[$page][] = $struct;
        }
        
        // ========================================================================
        // PHASE 1: COLLECT ALL SEMANTIC ELEMENTS (including non-rendered containers)
        // ========================================================================
        
        // Collect ALL semantic elements that need StructElems
        // This includes rendered elements AND their container ancestors (table, tr, tbody)
        $allSemanticElements = [];
        
        foreach ($this->structureTree as $struct) {
            $semantic = $struct['semantic'];
            
            // Collect this element + all its ancestors
            $ancestors = $semantic->collectAncestors($this->semanticElementsRef);
            
            foreach ($ancestors as $frameId => $ancestor) {
                if (!isset($allSemanticElements[$frameId])) {
                    $allSemanticElements[$frameId] = $ancestor;
                }
            }
        }
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Collected %d semantic elements (including containers)", count($allSemanticElements))
        );
        
        // Sort by depth (root first, leaves last) AND element ID (parents before children)
        // to ensure parents are created before children in PDF output
        usort($allSemanticElements, function($a, $b) {
            $depthCompare = $a->getDepth($this->semanticElementsRef) <=> $b->getDepth($this->semanticElementsRef);
            if ($depthCompare !== 0) {
                return $depthCompare; // Different depths: sort by depth
            }
            // Same depth: sort by ID (lower ID = parent comes first)
            // IDs are Frame IDs as strings, cast to int for numeric comparison
            return (int)$a->id <=> (int)$b->id;
        });
        
        // ========================================================================
        // PHASE 2: CALCULATE OBJECT IDs
        // ========================================================================
        
        // We need to know all object IDs beforehand for /P references
        // Calculate future object IDs (without actually allocating them yet)
        $nextN = $this->n;  // Current object counter
        
        // Build Frame-ID-to-ObjID mapping
        $frameIdToObjId = [];
        foreach ($allSemanticElements as $semantic) {
            $frameIdToObjId[$semantic->id] = ++$nextN;
        }
        
        $numLinkStructElems = count($this->annotationObjects);
        $calculatedLinkObjIds = [];
        for ($i = 0; $i < $numLinkStructElems; $i++) {
            $calculatedLinkObjIds[] = ++$nextN;
        }
        $parentTreeObjId = ++$nextN;
        $documentObjId = ++$nextN;
        $structTreeRootObjId = ++$nextN;
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Calculated ObjIDs: %d StructElems, Document=%d, StructTreeRoot=%d", 
                count($allSemanticElements), $documentObjId, $structTreeRootObjId)
        );
        
        // ========================================================================
        // PHASE 3: OUTPUT StructElems (in depth order)
        // ========================================================================
        
        // Now output objects in order
        // Each _newobj() call will increment $this->n to match our calculated IDs
        $structElemObjIds = [];
        
        // Build mapping: element ID → [MCID array, page] for elements that were actually rendered
        $frameIdToMCIDs = [];  // Note: variable name kept for compatibility, but now uses element IDs
        foreach ($this->structureTree as $struct) {
            $elementId = $struct['semantic']->id;
            if (!isset($frameIdToMCIDs[$elementId])) {
                $frameIdToMCIDs[$elementId] = [
                    'mcids' => [],
                    'page' => $struct['page']
                ];
            }
            $frameIdToMCIDs[$elementId]['mcids'][] = $struct['mcid'];
        }
        
        // Track MCID → StructElem mapping for ParentTree
        $mcidToObjId = [];
        
        // Output ALL StructElems (including non-rendered containers)
        foreach ($allSemanticElements as $semantic) {
            // Allocate object ID NOW (immediately before output)
            $objId = $this->_newobj();
            $structElemObjIds[] = $objId;
            
            // Get PDF structure tag
            $pdfTag = $semantic->getPdfStructureTag();
            
            // Determine parent: lookup via findParent() or fallback to Document
            $parent = $semantic->findParent($this->semanticElementsRef, false);
            $parentObjId = $parent !== null && isset($frameIdToObjId[$parent->id])
                ? $frameIdToObjId[$parent->id]
                : $documentObjId;
            
            // Build StructElem object
            $out = '<<';
            $out .= ' /Type /StructElem';
            $out .= ' /S /' . $pdfTag;
            $out .= sprintf(' /P %d 0 R', $parentObjId);  // CORRECT parent from hierarchy!
            
            // Add /K (kids):
            // - If element was rendered: MCIDs
            // - If container (not rendered): child StructElems
            if (isset($frameIdToMCIDs[$semantic->id])) {
                // Element was rendered → has MCIDs
                $mcids = $frameIdToMCIDs[$semantic->id]['mcids'];
                $pageNum = $frameIdToMCIDs[$semantic->id]['page'];
                
                // Track MCID → StructElem mapping for ParentTree
                foreach ($mcids as $mcid) {
                    $mcidToObjId[$mcid] = $objId;
                }
                
                // Add page reference - REQUIRED when /K is integer!
                if (isset($this->savedPageObjIds[$pageNum])) {
                    $out .= sprintf(' /Pg %d 0 R', $this->savedPageObjIds[$pageNum]);
                }
                
                // Use simple integer MCID (or array if multiple)
                if (count($mcids) === 1) {
                    $out .= ' /K ' . $mcids[0];
                } else {
                    $out .= ' /K [' . implode(' ', $mcids) . ']';
                }
            } else {
                // Container element (not rendered) → find children StructElems
                $childFrameIds = [];
                foreach ($allSemanticElements as $potentialChild) {
                    $childParent = $potentialChild->findParent($this->semanticElementsRef, false);
                    if ($childParent !== null && $childParent->id === $semantic->id) {
                        $childFrameIds[] = $potentialChild->id;
                    }
                }
                
                if (!empty($childFrameIds)) {
                    $childObjIds = array_map(fn($fid) => $frameIdToObjId[$fid], $childFrameIds);
                    $out .= ' /K [' . implode(' 0 R ', $childObjIds) . ' 0 R]';
                }
            }
            
            // Add alt text for images
            if ($semantic->isImage() && $semantic->hasAltText()) {
                $altText = TCPDF_STATIC::_escape($semantic->getAltText());
                $out .= ' /Alt (' . $altText . ')';
            }
            
            $out .= ' >>';
            $out .= "\n".'endobj';
            
            // CRITICAL: _newobj() already outputs "N 0 obj"
            // We just need to output the content and "endobj"
            $this->_out($out);
            
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("StructElem %d: /%s (frame %d, parent obj %d)", 
                    $objId, $pdfTag, $semantic->id, $parentObjId)
            );
        }
        
        // Create Link StructElems for annotations (PDF/UA 7.18.5 requirement)
        $linkStructElemObjIds = [];
        foreach ($this->annotationObjects as $annot) {
            $linkObjId = $this->_newobj();
            
            // Build Link StructElem with OBJR (Object Reference) to annotation
            $out = '<<';
            $out .= ' /Type /StructElem';
            $out .= ' /S /Link';
            $out .= sprintf(' /P %d 0 R', $documentObjId);  // Parent is Document
            $out .= sprintf(' /K << /Type /OBJR /Obj %d 0 R >>', $annot['obj_id']);  // Reference to annotation object
            
            // Add /Alt for accessibility (screenreaders)
            $altText = !empty($annot['url']) ? 'Link to ' . $annot['url'] : $annot['text'];
            $out .= ' /Alt (' . TCPDF_STATIC::_escape($altText) . ')';
            
            $out .= ' >>';
            $out .= "\n".'endobj';
            $this->_out($out);  // _newobj() already output "N 0 obj"
            
            $linkStructElemObjIds[] = $linkObjId;
        }
        
        // Combine all StructElem IDs for Document's /K array
        // IMPORTANT: Only include TOP-LEVEL elements (parent not in structure tree)
        // Otherwise we get duplicate references (parent's /K already references children)
        $topLevelObjIds = [];
        foreach ($allSemanticElements as $semantic) {
            $parent = $semantic->findParent($this->semanticElementsRef, false);
            // Top-level = no parent OR parent not in our structure tree
            if ($parent === null || !isset($frameIdToObjId[$parent->id])) {
                $topLevelObjIds[] = $frameIdToObjId[$semantic->id];
            }
        }
        
        // Links are also top-level (parent is Document)
        $topLevelObjIds = array_merge($topLevelObjIds, $linkStructElemObjIds);
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Document /K will reference %d top-level StructElems", count($topLevelObjIds))
        );
        
        // Output ParentTree (allocate ID immediately before output)
        $parentTreeObjId = $this->_newobj();
        // CRITICAL ParentTree structure:
        // - Page's /StructParents N maps to an ARRAY of StructElems for MCIDs
        // - Annotation's /StructParent N maps to a SINGLE StructElem
        // 
        // Format: /Nums [ 
        //   0 [10 0 R 11 0 R]   ← Index 0 = Page's MCIDs (array, ONE entry per MCID!)
        //   1 15 0 R             ← Index 1 = First annotation (single ref)
        //   2 16 0 R             ← Index 2 = Second annotation (single ref)
        // ]
        //
        // CRITICAL: The array at index 0 must have ONE entry per MCID in order!
        // mcidToObjId[0] = first StructElem, mcidToObjId[1] = second StructElem, etc.
        $out = '<< /Nums [';
        
        // Index 0: Array of StructElems for page's MCIDs
        // Build array in MCID order (0, 1, 2, ...)
        $out .= ' 0 [';
        if (!empty($mcidToObjId)) {
            // Sort by MCID (key) to ensure correct order
            ksort($mcidToObjId);
            $mcidObjRefs = array_map(fn($objId) => $objId . ' 0 R', $mcidToObjId);
            $out .= implode(' ', $mcidObjRefs);
        }
        $out .= ']';
        
        // Subsequent indices: One per annotation
        foreach ($linkStructElemObjIds as $idx => $linkObjId) {
            $annotIndex = $idx + 1;  // Start from 1 (0 is used by page)
            $out .= sprintf(' %d %d 0 R', $annotIndex, $linkObjId);
        }
        
        $out .= ' ] >>';
        $out .= "\n".'endobj';
        $this->_out($out);  // _newobj() already output "N 0 obj"
        
        // Output Document container element
        // CRITICAL: Use calculated $documentObjId from earlier!
        // Call _newobj() to allocate the next ID (which should match our calculation)
        $actualDocumentObjId = $this->_newobj();
        $out = '<<';
        $out .= ' /Type /StructElem';
        $out .= ' /S /Document';
        // CRITICAL FIX: PDF/UA Rule 7.1 requires ALL StructElems have /P (Parent)
        // Document's parent is the StructTreeRoot
        $out .= sprintf(' /P %d 0 R', $structTreeRootObjId);
        $out .= ' /K [' . implode(' 0 R ', $topLevelObjIds) . ' 0 R]';  // Only TOP-LEVEL elements!
        $out .= ' >>';
        $out .= "\n".'endobj';
        $this->_out($out);  // _newobj() already output "N 0 obj"
        
        // Output StructTreeRoot
        // CRITICAL: Use calculated $structTreeRootObjId from earlier!
        $actualStructTreeRootObjId = $this->_newobj();
        $out = '<<';
        $out .= ' /Type /StructTreeRoot';
        $out .= sprintf(' /K [%d 0 R]', $documentObjId);
        $out .= sprintf(' /ParentTree %d 0 R', $parentTreeObjId);
        $out .= ' /ParentTreeNextKey 2';
        
        // PDF/UA COMPLIANCE: RoleMap for non-standard structure types
        // Strong, Em → Span (inline emphasis)
        // Standard types (H1, P, Link, Div, etc.) must NOT be in RoleMap
        $out .= ' /RoleMap << /Strong /Span /Em /Span >>';
        
        $out .= sprintf(' /IDTree << /Nums [ 0 %d 0 R ] >>', $documentObjId);
        $out .= ' >>';
        $out .= "\n".'endobj';
        $this->_out($out);  // _newobj() already output "N 0 obj"
        
        // Return StructTreeRoot ObjID
        return [
            'struct_tree_root_obj_id' => $structTreeRootObjId,
            'document_obj_id' => $documentObjId
        ];
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
        
        // Get decision from DrawingManager
        $context = DrawingManager::analyzeDrawingContext(
            $operationName, 
            $this->currentFrameId, 
            $this->bdcManager, 
            $this->semanticElementsRef
        );
        
        // Close BDC if needed (for decorative elements that should end semantic tagging)
        if ($context['should_close_bdc']) {
            $this->_out($this->bdcManager->closeBDC());
        }
        
        // Execute drawing operation
        if ($context['wrap_as_artifact']) {
            // Get Artifact wrapper operators from DrawingManager
            $operators = DrawingManager::getArtifactWrapOperators($this->bdcManager);
            $this->_out($operators['before']);
            $drawingCallback();
            $this->_out($operators['after']);
        } else {
            $drawingCallback();
        }
    }

    // ========================================================================
    // TCPDF CORE OVERRIDES
    // All following methods override TCPDF to inject PDF/UA accessibility features.
    //
    // IMPORTANT: Semantic elements can be NULL
    // -----------------------------------------
    // Dompdf creates additional frames DURING reflow (after semantic registration):
    // - Line-break frames when text wraps
    // - Anonymous inline boxes for whitespace
    // - Page-break frames
    // 
    // These auto-generated frames are NOT in the semantic registry. This is correct:
    // they inherit the accessibility context from their parent frame automatically.
    // 
    // Example: "Very long heading" splits into Frame 4 + Frame 66
    // - Frame 4: Opens /H1 tag → renders "Very long heading"  
    // - Frame 66: NULL semantic → renders "that wraps" (inherits /H1 from parent)
    // - Frame 4: Closes /H1 tag
    // 
    // Always check: if($this->pdfua !== true || $semantic === null) bypass to parent
    // ========================================================================
    
    /**
     * Override SetFont to redirect Base 14 fonts via ContentWrapperManager
     * 
     * REFACTORED: Font mapping delegated to manager
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
            // Map font via ContentWrapperManager
            $family = $this->contentWrapper->mapPDFUAFont($family);
        }
        
        parent::SetFont($family, $style, $size, $fontfile, $subset, $out);
    }

    /**
     * Override setFontSize to suppress font operations in PDF/UA mode
     * 
     * CRITICAL: We inject Tf (font selection) directly in getCellCode() BT...ET blocks.
     * All standalone font operations outside BDC create unnecessary Artifact blocks.
     * 
     * SOLUTION: Suppress ALL font output in PDF/UA mode.
     * Parent calculations still happen (font properties set in memory).
     * Actual Tf output happens in getCellCode() where we inject it into BT...ET.
     * 
     * @param float $size The font size in points
     * @param boolean $out if true output the font size command
     * @public
     */
    public function setFontSize($size, $out=true) {
        // In PDF/UA mode: suppress ALL font output (we inject Tf in getCellCode instead)
        parent::setFontSize($size, $this->pdfua ? false : $out);
    }

    /**
     * Override endPage to wrap final page graphics operations as Artifact
     * 
     * CRITICAL FIX for content[65] validation error:
     * TCPDF outputs graphics state reset operations at page end (after last content).
     * These appear AFTER the final EMC but BEFORE endstream, causing "untagged content" errors.
     * 
     * SOLUTION: We can't wrap this because parent::endPage() closes the page buffer.
     * Instead, we'll catch this in _endpage() override.
     * 
     * @param boolean $tocpage if true set the tocpage state to false
     * @public
     */
    public function endPage($tocpage=false) {
        // Just call parent - we handle artifacts in _endpage() override
        parent::endPage($tocpage);
    }
    
    /**
     * Override _endpage to wrap final graphics operations before page closes
     * 
     * This is called by endPage() BEFORE state changes to 1.
     * Perfect place to inject final Artifact wrapper.
     * 
     * @protected
     */
    protected function _endpage() {
        // PDF/UA FIX: Close any open BDC block BEFORE wrapping page-end graphics
        if ($this->pdfua && $this->bdcManager->getActiveBDCFrame() !== null && $this->page > 0 && $this->state == 2) {
            $this->_out("\n" . $this->bdcManager->closeBDC());
        }
        
        // PDF/UA FIX: Wrap any remaining page content as Artifact
        if ($this->pdfua && $this->page > 0 && $this->state == 2) {
            $this->_out("\n/Artifact BMC");  // Leading newline to separate from previous content
        }
        
        // Call parent (sets state = 1)
        parent::_endpage();
        
        // PDF/UA FIX: Close Artifact after state change
        // We need to manually output EMC because state is now 1
        if ($this->pdfua && $this->page > 0) {
            // Manually append to page buffer since state is already 1
            if (isset($this->pages[$this->page])) {
                $this->pages[$this->page] .= "EMC\n";
            }
        }
    }

    /**
     * Override setGraphicVars to wrap graphics-state operations as Artifacts
     * 
     * CRITICAL FIX: Graphics operations (colors, line widths) must ALWAYS be
     * marked as Artifacts, even when inside tagged content blocks.
     * This prevents table borders from being associated with text MCIDs.
     */
    protected function setGraphicVars($gvars, $extended=false) {
        // Just call parent - we wrap drawing operations themselves, not state changes
        parent::setGraphicVars($gvars, $extended);
    }

    /**
     * Override setExtGState to wrap ExtGState operations as Artifacts
     */
    protected function setExtGState($gs) {
        // SUPPRESS ExtGState when inside tagged content blocks
        if ($this->pdfua && $this->bdcManager->isInsideTaggedContent()) {
            return;
        }
        
        // Only wrap if PDF/UA mode AND we're OUTSIDE tagged content blocks
        if ($this->pdfua && $this->page > 0 && $this->state == 2) {
            $this->_out('/Artifact BMC');
            parent::setExtGState($gs);
            $this->_out('EMC');
        } else {
            parent::setExtGState($gs);
        }
    }
    
    /**
     * Override StartTransform to prevent untagged 'q' operator
     */
    public function StartTransform() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'q'
        if ($this->pdfua && $this->bdcManager->isInsideTaggedContent()) {
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
        if ($this->pdfua && $this->bdcManager->isInsideTaggedContent()) {
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
        if ($this->pdfua && $this->bdcManager->isInsideTaggedContent()) {
            return; // Suppress 'q' output
        }
        
        parent::_outSaveGraphicsState();
    }
    
    /**
     * Override _outRestoreGraphicsState to prevent untagged 'Q' operator
     * 
     * @protected
     */
    protected function _outRestoreGraphicsState() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'Q'
        if ($this->pdfua && $this->bdcManager->isInsideTaggedContent()) {
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
        if ($this->pdfua && $this->bdcManager->getActiveBDCFrame() !== null) {
            // Inject EMC into the current page buffer
            if ($this->state == 2 && isset($this->page)) {
                $this->setPageBuffer($this->page, $this->bdcManager->closeBDC(), true);
            }
        }
        
        // Call parent first to output all standard resources
        parent::_putresources();
        
        // Now output our structure tree objects (if PDF/UA mode is enabled)
        if ($this->pdfua && !empty($this->structureTree)) {
            // Save page_obj_id now that _putpages() has been called
            if (property_exists($this, 'page_obj_id') && isset($this->page_obj_id) && is_array($this->page_obj_id)) {
                $this->savedPageObjIds = $this->page_obj_id;
                
                // BUILD structure tree
                $structureTreeData = $this->_createStructureTree();
                if ($structureTreeData !== null) {
                    // Save struct tree root ID for catalog modification
                    $this->savedStructTreeRootObjId = $structureTreeData['struct_tree_root_obj_id'];
                }
            }
        }
    }
    
    /**
     * Override _getannotsrefs() to add /StructParents and /Tabs after annotations
     * This is cleaner than buffer manipulation and doesn't break xref table
     * 
     * CRITICAL: Adobe ignores /StructParents 0, so we use 1
     * FIX 3: /Tabs /S activates reading order in Adobe and PAC
     */
    protected function _getannotsrefs($n)
    {
        // Get parent's annotation refs
        $annots = parent::_getannotsrefs($n);
        
        // If PDF/UA mode, add /StructParents and /Tabs after annotations
        if ($this->pdfua && !empty($this->structureTree)) {
            // Page's /StructParents maps to ParentTree for resolving MCIDs
            $annots .= ' /StructParents 0';
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
     * Override _putcatalog() to add PDF/UA specific entries  
     * Strategy: Copy TCPDF's logic but inject PDF/UA entries at the right positions
     */
    protected function _putcatalog()
    {
        // PDF/UA PATCH 1: Add DisplayDocTitle to viewer preferences
        if ($this->pdfua && !empty($this->structureTree)) {
            if (!isset($this->viewer_preferences)) {
                $this->viewer_preferences = [];
            }
            $this->viewer_preferences['DisplayDocTitle'] = 'true';
        }
        
        // === START: TCPDF's _putcatalog() logic (slightly simplified) ===
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
        
        // === PDF/UA PATCH 2 & 3: Add StructTreeRoot, MarkInfo, and Lang ===
        if ($this->pdfua && !empty($this->structureTree) && isset($this->savedStructTreeRootObjId)) {
            $out .= ' /StructTreeRoot ' . $this->savedStructTreeRootObjId . ' 0 R';
            $out .= ' /MarkInfo << /Marked true >>';
            $out .= ' /Lang '.$this->_textstring('en-US', $oid);  // PDF/UA requires language
        } 
        // === ELSE: TCPDF's standard language handling ===
        elseif (isset($this->l['a_meta_language'])) {
            $out .= ' /Lang '.$this->_textstring($this->l['a_meta_language'], $oid);
        }
        // Note: TCPDF has /StructTreeRoot and /MarkInfo commented out in original code
        
        // === CONTINUE TCPDF logic ===
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
        // === END TCPDF logic ===
        $out .= ' >>';
        $out .= "\n".'endobj';
        $this->_out($out);
        return $oid;
    }

    /**
     * Override _putXMP() to add PDF/UA identification metadata
     * Uses reflection to inject pdfuaid namespace into parent's XMP without full override
     */
    protected function _putXMP()
    {
        // If not PDF/UA mode, use parent's XMP unchanged
        if (!$this->pdfua || empty($this->structureTree)) {
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
     * Override getCellCode() to add PDF/UA tagging via Two-Phase Architecture
     * 
     * ARCHITECTURE: Complete separation of concerns
     * ============================================
     * 
     * PHASE 1: TAGGING RESOLUTION (TaggingManager)
     *   - Determines WHAT should be tagged
     *   - Resolves transparent tags to their parents
     *   - Identifies artifacts vs. semantic content
     * 
     * PHASE 2: BDC LIFECYCLE MANAGEMENT (BDCStateManager)
     *   - Determines WHEN to open/close BDC blocks
     *   - Independent of tagging decisions
     *   - Handles transparent tags by continuing current BDC
     * 
     * PHASE 3: EXECUTION
     *   - Applies the BDC action
     *   - Wraps content appropriately
     *   - Injects font operators
     * 
     * This two-phase approach solves the transparent tag problem:
     * - Transparent tags DON'T get early returns
     * - They participate in full BDC lifecycle
     * - BDC Manager decides to CONTINUE (not OPEN NEW)
     * - Sequential processing invariant maintained ✓
     * 
     * @param float $w Cell width
     * @param float $h Cell height
     * @param string $txt Text string
     * @param ... (all other TCPDF Cell parameters)
     * @return string PDF code with BDC/EMC tagging
     */
    protected function getCellCode($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='', $stretch=0, $ignore_min_height=false, $calign='T', $valign='M')
    {
        // Get original cell code from parent TCPDF
        $cellCode = parent::getCellCode($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);    
        
        // If not PDF/UA mode, return as-is
        if ($this->pdfua !== true) {
            return $cellCode;
        }
        
        // ====================================================================
        // PHASE 1: TAGGING RESOLUTION - Determine WHAT to tag
        // ====================================================================
        $decision = $this->taggingManager->resolveTagging($this->currentFrameId);
        
        // ====================================================================
        // PHASE 2: BDC LIFECYCLE - Determine WHEN to open/close BDC
        // ====================================================================
        $bdcAction = $this->bdcManager->determineBDCAction(
            currentFrameId: $this->currentFrameId ?? '',
            targetElement: $decision->element,
            isTransparent: $decision->isTransparent,
            isArtifact: $decision->isArtifact
        );
        
        // ====================================================================
        // PHASE 3: EXECUTION - Apply the BDC action
        // ====================================================================
        $result = '';
        
        if ($bdcAction->isOpenNew()) {
            // ACTION: Open new BDC block (close previous if exists)
            $mcid = $this->mcidCounter++;
            $result = $this->bdcManager->closePreviousAndOpenNew(
                $decision->pdfTag, 
                $mcid, 
                $decision->element->id
            );
            
            // Track in structure tree
            // Note: Transparent inline tags are already filtered out in registration
            $this->structureTree[] = [
                'type' => 'content',
                'tag' => $decision->pdfTag,
                'mcid' => $mcid,
                'page' => $this->page,
                'semantic' => $decision->element
            ];
            
        } elseif ($bdcAction->isContinue()) {
            // ACTION: Continue in current BDC (no changes)
            // Transparent tags, NULL semantics, and same-element content all continue
            // No PDF operators needed - just return styled content
            
        } elseif ($bdcAction->isCloseAndArtifact()) {
            // ACTION: Close BDC and wrap as Artifact
            $result = $this->bdcManager->closeBDC();
            $cellCode = $this->contentWrapper->wrapAsArtifact($cellCode);
        }
        
        // ====================================================================
        // FONT INJECTION - Always inject font operator into BT...ET blocks
        // ====================================================================
        if (isset($this->CurrentFont['i']) && $this->FontSizePt) {
            $cellCode = $this->contentWrapper->injectFontOperator(
                $cellCode, 
                $this->CurrentFont['i'], 
                $this->FontSizePt
            );
        }
        
        return $result . $cellCode;
    }

    // ========================================================================
    // DRAWING OPERATIONS - Using DrawingManager for PDF/UA compliance
    // This is the CORE architectural decision point for ALL PDF drawing operations.
    // Every drawing method (Line, Rect, Circle, etc.) should use this pattern.
    // ========================================================================

    /**
     * Override Line() using universal drawing pattern
     */
    public function Line($x1, $y1, $x2, $y2, $style=array()) {
        $this->_executeDrawingOperation("Line", function() use ($x1, $y1, $x2, $y2, $style) {
            parent::Line($x1, $y1, $x2, $y2, $style);
        });
    }

    /**
     * Override Rect() using universal drawing pattern
     */
    public function Rect($x, $y, $w, $h, $style='', $border_style=array(), $fill_color=array()) {
        $this->_executeDrawingOperation("Rect", function() use ($x, $y, $w, $h, $style, $border_style, $fill_color) {
            parent::Rect($x, $y, $w, $h, $style, $border_style, $fill_color);
        });
    }

    /**
     * Override Circle() using universal drawing pattern
     */
    public function Circle($x0, $y0, $r, $angstr=0, $angend=360, $style='', $line_style=array(), $fill_color=array(), $nc=2) {
        $this->_executeDrawingOperation("Circle", function() use ($x0, $y0, $r, $angstr, $angend, $style, $line_style, $fill_color, $nc) {
            parent::Circle($x0, $y0, $r, $angstr, $angend, $style, $line_style, $fill_color, $nc);
        });
    }

    /**
     * Override Ellipse() using universal drawing pattern
     */
    public function Ellipse($x0, $y0, $rx, $ry=0, $angle=0, $astart=0, $afinish=360, $style='', $line_style=array(), $fill_color=array(), $nc=2) {
        $this->_executeDrawingOperation("Ellipse", function() use ($x0, $y0, $rx, $ry, $angle, $astart, $afinish, $style, $line_style, $fill_color, $nc) {
            parent::Ellipse($x0, $y0, $rx, $ry, $angle, $astart, $afinish, $style, $line_style, $fill_color, $nc);
        });
    }

    /**
     * Override Polygon() using universal drawing pattern
     */
    public function Polygon($p, $style='', $line_style=array(), $fill_color=array(), $closed=true) {
        $this->_executeDrawingOperation("Polygon", function() use ($p, $style, $line_style, $fill_color, $closed) {
            parent::Polygon($p, $style, $line_style, $fill_color, $closed);
        });
    }
}