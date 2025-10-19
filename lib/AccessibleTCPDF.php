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
     * Saved object counter before Close() for structure tree building
     * @var int|null
     */
    private ?int $savedN = null;

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
     * Track which frame currently has an open BDC block
     * Format: ['frameId' => int, 'pdfTag' => string, 'mcid' => int, 'semanticId' => string] or null
     * This prevents opening multiple BDC blocks for the same frame (which happens when
     * TCPDF renders one frame as multiple Cell() calls)
     * @var array|null
     */
    private ?array $activeBDCFrame = null;

    /**
     * Track BDC/EMC nesting depth
     * Incremented on BDC, decremented on EMC
     * Used to prevent wrapping graphics ops as Artifacts when inside tagged content
     * @var int
     */
    private int $bdcDepth = 0;

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
            
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                "PDF/UA mode: Core fonts redirected to DejaVu for embedding compliance."
            );
        }
        
        // Store reference to semantic elements
        if ($semanticElementsRef !== null) {
            $this->semanticElementsRef = &$semanticElementsRef;
        }
    }

    // ========================================================================
    // SEMANTIC ELEMENT FRAME MANAGEMENT
    // These methods provide a reusable framework for tagging TCPDF operation
    // ========================================================================

    /**
     * Set the current frame ID being rendered
     * 
     * @param string|null $frameId The frame ID
     */
    public function setCurrentFrameId(?string $frameId): void
    {
        $this->currentFrameId = $frameId;
    }
    
    /**
     * Get the current semantic element being rendered
     * 
     * @return SemanticElement|null
     */
    private function _getCurrentSemanticElement(): ?SemanticElement
    {
        // If no semantic elements or no current frame, return null
        if ($this->semanticElementsRef === null || $this->currentFrameId === null) {
            return null;
        }

        // Frames die während Reflow erstellt werden, sind nicht registriert
        if (!isset($this->semanticElementsRef[$this->currentFrameId])) {
            return null;  // Das ist OK - diese Frames erben den Kontext vom Parent
        }
        
        return $this->semanticElementsRef[$this->currentFrameId];
    }
    
    /**
     * Find parent semantic element for a given frame ID
     * Searches backwards through frame IDs to find the nearest non-#text parent
     * 
     * @param int $frameId The child frame ID
     * @return SemanticElement|null
     */
    private function _findParentSemanticElement(int $frameId): ?SemanticElement
    {
        if ($this->semanticElementsRef === null) {
            return null;
        }
        
        // Search backwards from current frame ID to find parent
        // Parent frames have lower IDs in Dompdf's frame tree
        for ($parentId = $frameId - 1; $parentId >= 0; $parentId--) {
            if (isset($this->semanticElementsRef[(string)$parentId])) {
                $parent = $this->semanticElementsRef[(string)$parentId];
                
                // Skip #text parents, go further up
                if ($parent->tag === '#text') {
                    continue;
                }
                
                return $parent;
            }
        }
        
        return null;
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
        
        // CRITICAL FIX STRATEGY:
        // 1. Count total objects needed
        // 2. Reserve ALL object IDs upfront using _newobj()
        // 3. Build PDF strings with correct IDs
        // 4. Output everything in correct order using _out()
        
        // CRITICAL FIX STRATEGY:
        // DO NOT pre-allocate object IDs with _newobj()!
        // _newobj() must be called IMMEDIATELY before _out() to ensure correct PDF object numbering
        // 
        // TCPDF's internal _out() writes to the CURRENT $this->n value
        // If we pre-allocate with _newobj(), the IDs get messed up
        
        // We need to know Document ID beforehand for /P references in StructElems
        // So we calculate it based on current $this->n
        $numStructElems = count($this->structureTree);
        $numLinkStructElems = count($this->annotationObjects);
        
        // Calculate future object IDs (without actually allocating them yet)
        $nextN = $this->n;  // Current object counter
        $calculatedStructElemObjIds = [];
        for ($i = 0; $i < $numStructElems; $i++) {
            $calculatedStructElemObjIds[] = ++$nextN;
        }
        $calculatedLinkObjIds = [];
        for ($i = 0; $i < $numLinkStructElems; $i++) {
            $calculatedLinkObjIds[] = ++$nextN;
        }
        $parentTreeObjId = ++$nextN;
        $documentObjId = ++$nextN;
        $structTreeRootObjId = ++$nextN;
        
        // Now output objects in order
        // Each _newobj() call will increment $this->n to match our calculated IDs
        $structElemObjIds = [];
        
        // Output StructElems first
        foreach ($pageStructures as $pageNum => $structs) {
            foreach ($structs as $struct) {
                // Allocate object ID NOW (immediately before output)
                $objId = $this->_newobj();
                $structElemObjIds[] = $objId;
                
                // Build StructElem object
                $out = '<<';
                $out .= ' /Type /StructElem';
                $out .= ' /S /' . $struct['tag'];
                $out .= sprintf(' /P %d 0 R', $documentObjId);  // Use CALCULATED Document ID!
                
                // CRITICAL: For page content, use SIMPLE INTEGER /K + /Pg
                // NOT MCR dictionary! MCR is for XObjects.
                // Add page reference - REQUIRED when /K is integer!
                if (isset($this->savedPageObjIds[$pageNum])) {
                    $out .= sprintf(' /Pg %d 0 R', $this->savedPageObjIds[$pageNum]);
                }
                
                // Use simple integer MCID
                $out .= ' /K ' . $struct['mcid'];
                
                // Add alt text for images
                if ($struct['semantic']->isImage() && $struct['semantic']->hasAltText()) {
                    $altText = TCPDF_STATIC::_escape($struct['semantic']->getAltText());
                    $out .= ' /Alt (' . $altText . ')';
                }
                
                $out .= ' >>';
                $out .= "\n".'endobj';
                
                // CRITICAL: _newobj() already outputs "N 0 obj"
                // We just need to output the content and "endobj"
                $this->_out($out);
            }
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
        $allStructElemObjIds = array_merge($structElemObjIds, $linkStructElemObjIds);
        
        // Output ParentTree (allocate ID immediately before output)
        $parentTreeObjId = $this->_newobj();
        // CRITICAL ParentTree structure:
        // - Page's /StructParents N maps to an ARRAY of StructElems for MCIDs
        // - Annotation's /StructParent N maps to a SINGLE StructElem
        // 
        // Format: /Nums [ 
        //   0 [10 0 R 11 0 R]   ← Index 0 = Page's MCIDs (array)
        //   1 15 0 R             ← Index 1 = First annotation (single ref)
        //   2 16 0 R             ← Index 2 = Second annotation (single ref)
        // ]
        $out = '<< /Nums [';
        
        // Index 0: Array of StructElems for page's MCIDs
        $out .= ' 0 [';
        $out .= implode(' 0 R ', $structElemObjIds) . ' 0 R';
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
        // CRITICAL: Use calculated $documentObjId from line 487!
        // Call _newobj() to allocate the next ID (which should match our calculation)
        $actualDocumentObjId = $this->_newobj();
        $out = '<<';
        $out .= ' /Type /StructElem';
        $out .= ' /S /Document';
        // CRITICAL FIX: PDF/UA Rule 7.1 requires ALL StructElems have /P (Parent)
        // Document's parent is the StructTreeRoot
        $out .= sprintf(' /P %d 0 R', $structTreeRootObjId);
        $out .= ' /K [' . implode(' 0 R ', $allStructElemObjIds) . ' 0 R]';  // Include Links!
        $out .= ' >>';
        $out .= "\n".'endobj';
        $this->_out($out);  // _newobj() already output "N 0 obj"
        
        // Output StructTreeRoot
        // CRITICAL: Use calculated $structTreeRootObjId from line 488!
        $actualStructTreeRootObjId = $this->_newobj();
        $out = '<<';
        $out .= ' /Type /StructTreeRoot';
        $out .= sprintf(' /K [%d 0 R]', $documentObjId);
        $out .= sprintf(' /ParentTree %d 0 R', $parentTreeObjId);
        $out .= ' /ParentTreeNextKey 2';
        // RoleMap is empty - all tags (H1, P, Link, Document) are PDF standard types
        // Adding standard types to RoleMap causes "circular mapping" veraPDF errors
        $out .= ' /RoleMap << >>';
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
     * Override SetFont to redirect Base 14 fonts to embedded equivalents in PDF/UA mode
     * 
     * CRITICAL: PDF/UA Rule 7.21.4.1 requires ALL fonts to be embedded.
     * TCPDF's Base 14 fonts (Helvetica, Times, Courier, Symbol, ZapfDingbats) are NOT embedded.
     * We redirect them to DejaVu equivalents which ARE embedded.
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
            // Map Base 14 fonts to DejaVu equivalents
            $fontMap = [
                'helvetica' => 'dejavusans',
                'times' => 'dejavuserif',
                'courier' => 'dejavusansmono',
                'symbol' => 'dejavusans',
                'zapfdingbats' => 'dejavusans'
            ];
            
            $familyLower = strtolower($family);
            if (isset($fontMap[$familyLower])) {
                $oldFamily = $family;
                $family = $fontMap[$familyLower];
                
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("PDF/UA: Redirected font '%s' → '%s' for embedding", $oldFamily, $family)
                );
            }
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
        if ($this->pdfua && $this->activeBDCFrame !== null && $this->page > 0 && $this->state == 2) {
            $this->_out("\nEMC");  // Close the last content BDC (with leading newline)
            $this->bdcDepth--;
            $this->activeBDCFrame = null;
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
     * PDF/UA requires all content to be either tagged or marked as Artifact
     * 
     * Graphics-state operations (line width, cap, join, dash, colors) are decorative
     * and must be marked as Artifacts to prevent "untagged content" validation errors.
     * 
     * CRITICAL: Do NOT create nested Artifacts inside BDC blocks!
     * Only wrap as Artifact when OUTSIDE tagged content ($bdcDepth == 0).
     * 
     * @param array $gvars Array of graphic variables
     * @param boolean $extended If true restore extended graphic variables
     * @protected
     */
    protected function setGraphicVars($gvars, $extended=false) {
        // Only wrap as Artifact when OUTSIDE BDC blocks
        // State >= 1 allows wrapping during page end (state=1) and normal rendering (state=2)
        if ($this->pdfua && $this->page > 0 && $this->state >= 1 && $this->bdcDepth == 0) {
            // Wrap graphics-state output as Artifact
            $this->_out('/Artifact BMC');
            parent::setGraphicVars($gvars, $extended);
            $this->_out('EMC');
        } else {
            // Normal mode OR inside BDC - just call parent without wrapping
            parent::setGraphicVars($gvars, $extended);
        }
    }

    /**
     * Override setExtGState to wrap ExtGState operations as Artifacts
     * PDF/UA requires all content to be either tagged or marked as Artifact
     * 
     * ExtGState operations (/GS1 gs, /GS2 gs, etc.) control transparency and blending.
     * 
     * CRITICAL: veraPDF does NOT allow nested Artifacts OR untagged content inside BDC blocks!
     * We SUPPRESS setExtGState() when inside tagged content ($bdcDepth > 0).
     * This prevents untagged /GS1 gs operations from appearing in BDC blocks.
     * 
     * TRADE-OFF: Transparency may not work correctly for tagged content.
     * This is acceptable for PDF/UA compliance as transparency is primarily visual.
     * 
     * @param int $gs extgstate identifier
     * @protected
     */
    protected function setExtGState($gs) {
        // SUPPRESS ExtGState when inside tagged content blocks
        if ($this->pdfua && $this->bdcDepth > 0) {
            return; // Suppress ExtGState inside BDC
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
     * CRITICAL: The 'q' (save graphics state) is a graphics-state operator that
     * must be tagged as Artifact or suppressed when inside BDC blocks!
     * 
     * @public
     */
    public function StartTransform() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'q'
        if ($this->pdfua && ($this->bdcDepth > 0)) {
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
     * CRITICAL: The 'Q' (restore graphics state) must be suppressed if the
     * corresponding 'q' was suppressed!
     * 
     * @public
     */
    public function StopTransform() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'Q'
        if ($this->pdfua && ($this->bdcDepth > 0)) {
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
     * CRITICAL: Many TCPDF methods call this directly (not via StartTransform)!
     * Footer uses this before Cell() calls, which creates untagged 'q' inside BDC!
     * 
     * @protected
     */
    protected function _outSaveGraphicsState() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'q'
        if ($this->pdfua && ($this->bdcDepth > 0)) {
            return; // Suppress 'q' output
        }
        
        parent::_outSaveGraphicsState();
    }
    
    /**
     * Override _outRestoreGraphicsState to prevent untagged 'Q' operator
     * CRITICAL: Must suppress if corresponding 'q' was suppressed!
     * 
     * @protected
     */
    protected function _outRestoreGraphicsState() {
        // PDF/UA FIX: SUPPRESS when inside BDC to avoid untagged 'Q'
        if ($this->pdfua && ($this->bdcDepth > 0)) {
            return; // Suppress 'Q' output
        }
        
        parent::_outRestoreGraphicsState();
    }

    /**
     * Extract graphics-state line from cellCode
     * 
     * CRITICAL: veraPDF treats ANY graphics operation (even empty 'q') as untagged contentItem!
     * We must extract ALL graphics operations including q/Q.
     * 
     * Pattern in TCPDF output:
     * "0.570000 w 0 J 0 j [] 0 d 0 G 0 g\nq 0.000000 0.000000 0.000000 rg BT 0 Tr 0.000000 w ET BT ... TJ ET Q"
     * 
     * We extract EVERYTHING except the actual text rendering "BT ... TJ ET":
     * 1. "0.570000 w 0 J 0 j [] 0 d 0 G 0 g" → Artifact
     * 2. "q 0.000000 0.000000 0.000000 rg" → Artifact  
     * 3. "BT 0 Tr 0.000000 w ET" → Artifact
     * 4. "Q" → Artifact
     * 
     * And keep inside BDC: "BT ... TJ ET" (ONLY the text)
     * 
     * @param string $cellCode Original PDF code from parent::getCellCode()
     * @return array [$graphicsLines, $cleanedCellCode]
     * @protected
     */
    protected function _extractGraphicsOps($cellCode) {
        $graphicsLines = [];
        
        // Extract first line if it's graphics-state
        $lines = explode("\n", $cellCode, 2);
        if (count($lines) >= 2 && preg_match('/^[\d\.]+ w [\d]+ J [\d]+ j \[.*?\] [\d]+ d [\d\.]+ G [\d\.]+ g$/', $lines[0])) {
            $graphicsLines[] = $lines[0];
            $cellCode = $lines[1];
        }
        
        // Extract "q ...color... BT 0 Tr 0.000000 w ET" and remove q/Q entirely
        $cellCode = preg_replace_callback(
            '/q ([^\n]+?)\s+BT (\d+) Tr ([\d\.]+) w ET\s+(.*?)\s+Q/s',
            function($matches) use (&$graphicsLines) {
                // Extract ALL graphics operations
                $graphicsLines[] = 'q ' . $matches[1];  // Graphics state save + color
                $graphicsLines[] = 'BT ' . $matches[2] . ' Tr ' . $matches[3] . ' w ET';  // Render mode
                $graphicsLines[] = 'Q';  // Graphics state restore
                // Return ONLY the text rendering part (without q/Q)
                return $matches[4];
            },
            $cellCode
        );
        
        if (empty($graphicsLines)) {
            return ['', $cellCode];
        }
        
        return [implode("\n", $graphicsLines), $cellCode];
    }

    /**
     * Override _putresources() to output structure tree objects BEFORE catalog
     * This ensures xref table is correctly built
     */
    protected function _putresources()
    {
        // CRITICAL: Close any open BDC block before finalizing the document
        if ($this->activeBDCFrame !== null) {
            // Inject EMC into the current page buffer
            if ($this->state == 2 && isset($this->page)) {
                $this->setPageBuffer($this->page, "EMC\n", true);
                $this->bdcDepth--;  // Decrement when closing final BDC
            }
            $this->activeBDCFrame = null;
        }
        
        // Call parent first to output all standard resources
        parent::_putresources();
        
        // Now output our structure tree objects (if PDF/UA mode is enabled)
        if ($this->pdfua && !empty($this->structureTree)) {
            // Save page_obj_id now that _putpages() has been called
            if (property_exists($this, 'page_obj_id') && isset($this->page_obj_id) && is_array($this->page_obj_id)) {
                $this->savedPageObjIds = $this->page_obj_id;
                $this->savedN = $this->n;
                
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
     * Override getCellCode() to add PDF/UA tagging DIRECTLY into the PDF code string
     * 
     * CRITICAL: This is THE KEY method for text tagging in TCPDF.
     * getCellCode() returns the PDF code string that Cell() will output via _out().
     * By wrapping this string with BDC/EMC markers, we ensure proper tagging.
     * 
     * TCPDF's flow: Text() → Cell() → getCellCode() → returns string → _out(string)
     * 
     * This automatically tags ALL text operations:
     * - Text()
     * - Write()  
     * - MultiCell() (uses Cell internally)
     * - Cell() itself
     * 
     * @param float $w Cell width
     * @param float $h Cell height
     * @param string $txt Text string
     * @param ... (all other TCPDF Cell parameters)
     * @return string PDF code with BDC/EMC tagging
     */
    protected function getCellCode($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='', $stretch=0, $ignore_min_height=false, $calign='T', $valign='M')
    {
        // Get the original cell code from parent
        $cellCode = parent::getCellCode($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);    
        
        // If not PDF/UA mode, return as-is
        if ($this->pdfua !== true) {
            return $cellCode;
        }
        
        // Get current semantic element from Dompdf's frame tree
        $semantic = $this->_getCurrentSemanticElement();
        
        // Only log if there's actual text content (avoid spam from empty cells)
        if ($txt !== '' && $txt !== null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf(
                    "getCellCode with text: \"%s\" | Semantic: %s%s",
                    substr($txt, 0, 50) . (strlen($txt) > 50 ? '...' : ''),
                    $semantic ? (string)$semantic : 'NONE',
                    $semantic && $semantic->isDecorative() ? ' (decorative)' : ''
                )
            );
        }
        
        // If no semantic element (e.g., TCPDF footer), mark as Artifact
        if ($semantic === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "Marking as Artifact");
            
            // CRITICAL: Close any open BDC before starting Artifact
            $result = '';
            if ($this->activeBDCFrame !== null) {
                $result .= "EMC\n";
                $this->bdcDepth--;  // Decrement when closing BDC
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Closed BDC for frame %s before Artifact (bdcDepth=%d)", $this->activeBDCFrame['semanticId'], $this->bdcDepth)
                );
                $this->activeBDCFrame = null;
            }
            
            return $result . "/Artifact BMC\n" . $cellCode . "EMC\n";
        }
        
        // If decorative, mark as Artifact
        if ($semantic->isDecorative()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "Marking decorative as Artifact");
            
            // CRITICAL: Close any open BDC before starting Artifact
            $result = '';
            if ($this->activeBDCFrame !== null) {
                $result .= "EMC\n";
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Closed BDC for frame %s before decorative Artifact", $this->activeBDCFrame['semanticId'])
                );
                $this->activeBDCFrame = null;
            }
            
            return $result . "/Artifact BMC\n" . $cellCode . "EMC\n";
        }
        
        // For #text nodes, use parent element for tagging
        $tagElement = $semantic;
        if ($semantic->tag === '#text') {
            $parentSemantic = $this->_findParentSemanticElement($semantic->frameId);
            if ($parentSemantic === null) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    "Skipping #text - no parent found, marking as Artifact"
                );
                return "/Artifact BMC\n" . $cellCode . "EMC\n";
            }
            $tagElement = $parentSemantic;
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                "Using parent element for #text: " . $tagElement
            );
        }
        
        $pdfTag = $tagElement->getPdfStructureTag();
        $semanticId = $tagElement->id;  // Unique ID for this semantic element
        
        // FRAME BOUNDARY DETECTION:
        // Check if we're still in the same frame as the active BDC block
        $sameFrame = ($this->activeBDCFrame !== null && 
                     $this->activeBDCFrame['semanticId'] === $semanticId);
        
        if ($sameFrame) {
            // Same frame - extract graphics line and output as Artifact BEFORE content
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Continuing frame %s (no new BDC)", $semanticId)
            );
            
            // Extract graphics-state line and output as Artifact
            list($graphicsLine, $cleanedCellCode) = $this->_extractGraphicsOps($cellCode);
            
            if (!empty($graphicsLine)) {
                return "/Artifact BMC\n" . $graphicsLine . "\nEMC\n" . $cleanedCellCode;
            }
            
            return $cleanedCellCode;
        }
        
        // Different frame - close previous BDC (if any) and open new one
        $result = '';
        
        // Close previous BDC if exists
        if ($this->activeBDCFrame !== null) {
            $result .= "EMC\n";
            $this->bdcDepth--;  // Decrement depth when closing BDC
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Closed previous BDC for frame %s", $this->activeBDCFrame['semanticId'])
            );
        }
        
        // Open new BDC for this frame
        $mcid = $this->mcidCounter++;
        
        // CRITICAL FIX: Extract graphics-state line from cellCode
        // and output it as Artifact BEFORE the BDC block
        list($graphicsLine, $cleanedCellCode) = $this->_extractGraphicsOps($cellCode);
        
        // DEBUG: Log extraction results
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("EXTRACTED Graphics: [%s] | Cleaned: [%s]", 
                str_replace("\n", "\\n", substr($graphicsLine, 0, 100)),
                str_replace("\n", "\\n", substr($cleanedCellCode, 0, 100))
            )
        );
        
        // CRITICAL FIX: Inject Tf (font selection) into BT...ET block
        // veraPDF requires font to be set BEFORE text rendering!
        // Pattern: "BT ... Td ... TJ ET" → "BT /F3 24.000000 Tf ... Td ... TJ ET"
        if (isset($this->CurrentFont['i']) && $this->FontSizePt) {
            $cleanedCellCode = preg_replace(
                '/^BT\s+/',
                sprintf('BT /F%d %F Tf ', $this->CurrentFont['i'], $this->FontSizePt),
                $cleanedCellCode
            );
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("INJECTED Tf: /F%d %F Tf into BT...ET block", $this->CurrentFont['i'], $this->FontSizePt)
            );
        }
        
        if (!empty($graphicsLine)) {
            $result .= "/Artifact BMC\n" . $graphicsLine . "\nEMC\n";
        }
        
        $result .= sprintf("/%s << /MCID %d >> BDC\n", $pdfTag, $mcid);
        $this->bdcDepth++;  // Increment depth when opening BDC
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Opened new BDC: %s MCID=%d for frame %s (bdcDepth=%d)", $pdfTag, $mcid, $semanticId, $this->bdcDepth)
        );
        
        // Track this as the active BDC frame
        $this->activeBDCFrame = [
            'frameId' => $tagElement->frameId,
            'pdfTag' => $pdfTag,
            'mcid' => $mcid,
            'semanticId' => $semanticId
        ];
        
        // Track in structure tree
        $this->structureTree[] = [
            'type' => 'content',
            'tag' => $pdfTag,
            'mcid' => $mcid,
            'page' => $this->page,
            'semantic' => $tagElement
        ];
        
        // DEBUG: Log the final return
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("RETURNING: [%s] + cleanedCellCode (WITHOUT trailing EMC - next call will close it)", 
                str_replace("\n", "\\n", substr($result, 0, 150))
            )
        );
        
        // Return: [optional EMC for previous BDC] + [graphics ops as Artifact] + BDC + cleaned cell code
        // NOTE: We do NOT add EMC at the end here! The NEXT getCellCode() call will close this BDC.
        // The final BDC on a page will be closed by the next getCellCode() call (e.g. footer)
        // or by TCPDF's page closing mechanism.
        return $result . $cleanedCellCode;
    }

    /*
     * FUTURE EXTENSIONS - Template for other TCPDF methods:
     * 
     * public function Image($file, $x='', $y='', $w=0, $h=0, $type='', ...) {
     *     if ($this->pdfua !== true) {
     *         return parent::Image($file, $x, $y, $w, $h, $type, ...);
     *     }
     *     
     *     $semantic = $this->_getCurrentSemanticElement();
     *     
     *     return $this->_wrapWithTag(
     *         $semantic,
     *         function() use ($file, $x, $y, $w, $h, $type, ...) {
     *             return parent::Image($file, $x, $y, $w, $h, $type, ...);
     *         },
     *         false  // Images don't need font preparation
     *     );
     * }
     * 
     * public function Line($x1, $y1, $x2, $y2, $style=array()) {
     *     if ($this->pdfua !== true) {
     *         return parent::Line($x1, $y1, $x2, $y2, $style);
     *     }
     *     
     *     // Lines are usually decorative, so semantic would be null or decorative
     *     $semantic = $this->_getCurrentSemanticElement();
     *     
     *     return $this->_wrapWithTag(
     *         $semantic,
     *         function() use ($x1, $y1, $x2, $y2, $style) {
     *             return parent::Line($x1, $y1, $x2, $y2, $style);
     *         },
     *         false  // Lines don't need font preparation
     *     );
     * }
     */
}