<?php
/**
 * StructureTreeBuilder - Builds PDF/UA Structure Tree
 * 
 * Encapsulates structure tree building logic with clean separation:
 * - Collects MCID registrations during rendering
 * - Generates PDF object strings (NO I/O operations!)
 * - Returns strings for AccessibleTCPDF to output
 * 
 * ARCHITECTURE: String Generation Only
 * ====================================
 * build() method generates PDF object strings but does NOT call _newobj() or _out()
 * This enables:
 * - Testability: Can test string generation without TCPDF instance
 * - Maintainability: Clear separation between logic and I/O
 * - Flexibility: Strings can be logged/validated before output
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticNode;

class StructureTreeBuilder
{
    /**
     * Structure tree elements collected during rendering
     * Format: [ ['mcid' => int, 'page' => int, 'semantic' => SemanticNode], ... ]
     * @var array
     */
    private array $structureTree = [];

    /**
     * Hash set for O(1) duplicate detection (inside add()) instead of O(n) array scan
     * Format: [ "frameId:mcid:page" => true, ... ]
     * @var array
     */
    private array $registeredKeys = [];

    /**
     * Cached Structure Tree Root object ID after build()
     * @var int|null
     */
    private ?int $structureObjId = null;

    /**
     * Get Structure Tree Root object ID after build()
     * 
     * @return int|null Structure Tree Root object ID or null if not built yet
     */
    public function getStructureObjId(): ?int
    {
        return $this->structureObjId;
    }

    /**
     * Register a new MCID with its semantic element
     * 
     * Called when semantic BDC is opened (from onBDCOpened callback).
     * 
     * DEDUPLICATION: If the same (frameId, mcid, page) combination already exists,
     * we skip registration. This handles the case where drawing operations
     * re-open the same BDC after interruption.
     * 
     * PERFORMANCE: O(1) lookup using hash set instead of O(n) array scan.
     * 
     * @param SemanticNode $node Semantic node
     * @param int $mcid Marked Content ID
     * @param int $page Page number (1-based)
     * @return void
     */
    public function add(SemanticNode $node, int $mcid, int $page): void
    {
        // Build unique key for O(1) lookup
        $key = "{$node->id}:{$mcid}:{$page}";
        
        // Check if already registered (O(1) hash lookup)
        if (isset($this->registeredKeys[$key])) {
            return; // Skip duplicate (happens when drawing operations re-open BDC)
        }
        
        // New entry - register it
        $this->registeredKeys[$key] = true;
        $this->structureTree[] = [
            'mcid' => $mcid,
            'page' => $page,
            'semantic' => $node
        ];
    }

    /**
     * Check if any structure elements have been registered
     * 
     * @return bool True if structure tree is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->structureTree);
    }

    /**
     * Build PDF structure tree strings (NO I/O operations!)
     * 
     * CRITICAL: This method generates PDF object strings but does NOT call:
     * - _newobj() - Object ID allocation handled by caller
     * - _out() - Output handled by caller
     * 
     * Flow:
     * 1. COLLECT all semantic elements (including container ancestors)
     * 2. CALCULATE object IDs (pre-calculate for /P references)
     * 3. BUILD PDF strings for each object
     * 4. RETURN strings + metadata
     * 
     * Caller (AccessibleTCPDF::_putresources) will:
     * - Loop through strings
     * - Call _newobj() for each
     * - Call _out() to output the string
     * 
     * @param int $currentObjId Current object counter (e.g., $tcpdf->n)
     * @param array $pageObjIds Page object IDs for /Pg references (indexed by page number)
     * @param array $annotationObjects Annotation objects for Link StructElems
     * @return array ['strings' => [string, ...], 'struct_tree_root_obj_id' => int, 'document_obj_id' => int]
     */
    public function build(int $currentObjId, array $pageObjIds, array $annotationObjects): array
    {
        if (empty($this->structureTree)) {
            return ['strings' => [], 'struct_tree_root_obj_id' => null, 'document_obj_id' => null];
        }
        
        $strings = [];  // All PDF object strings to output
        
        // ========================================================================
        // PHASE 1: COLLECT ALL SEMANTIC ELEMENTS
        // ========================================================================
        // Strategy: Start with rendered elements (have MCID), then walk tree to collect:
        // 1. Ancestors (for hierarchy)
        // 2. Siblings that requiresStructureElement() (for PDF/UA rules like table columns)
        //
        // Example: <tr><td></td><td>Text</td></tr>
        // - TD with text is rendered → in $structureTree
        // - Walk ancestors → collect TR
        // - TR's children include empty TD → collect it via requiresStructureElement()
        
        $allSemanticElements = [];
        
        foreach ($this->structureTree as $struct) {
            $semantic = $struct['semantic'];
            
            // Skip non-semantic wrappers (html, body)
            if (!$semantic->isNonSemanticWrapper()) {
                $allSemanticElements[$semantic->id] = $semantic;
            }
            
            // Collect ancestors (skip wrappers)
            foreach ($semantic->getAncestors() as $ancestor) {
                if (!$ancestor->isNonSemanticWrapper() && !isset($allSemanticElements[$ancestor->id])) {
                    $allSemanticElements[$ancestor->id] = $ancestor;
                    
                    // CRITICAL: Collect ALL children of this ancestor that require structure presence
                    // This ensures empty table cells are included for consistent column counts
                    foreach ($ancestor->getChildren() as $child) {
                        if ($child->requiresStructureElement() && !isset($allSemanticElements[$child->id])) {
                            $allSemanticElements[$child->id] = $child;
                        }
                    }
                }
            }
        }
        
        SimpleLogger::log("pdf_backend_structure_tree_logs", __METHOD__, 
            sprintf("Collected %d semantic elements (including required siblings)", count($allSemanticElements))
        );
        
        // Pre-calculate depths for O(1) sorting
        foreach ($allSemanticElements as $semantic) {
            $semantic->getDepth();  // Cache depth in node
        }
        
        // Sort by depth (root first) and ID (parents before children)
        usort($allSemanticElements, function($a, $b) {
            $depthCompare = $a->getDepth() <=> $b->getDepth();
            if ($depthCompare !== 0) {
                return $depthCompare;
            }
            return (int)$a->id <=> (int)$b->id;
        });
        
        // ========================================================================
        // PHASE 2: CALCULATE OBJECT IDs
        // ========================================================================
        
        // Calculate future object IDs without allocating them
        $nextN = $currentObjId;
        
        // Build Frame-ID-to-ObjID mapping
        $frameIdToObjId = [];
        foreach ($allSemanticElements as $semantic) {
            $frameIdToObjId[$semantic->id] = ++$nextN;
        }
        
        // Calculate IDs for Link annotations
        $numLinkStructElems = count($annotationObjects);
        $calculatedLinkObjIds = [];
        for ($i = 0; $i < $numLinkStructElems; $i++) {
            $calculatedLinkObjIds[] = ++$nextN;
        }
        
        $parentTreeObjId = ++$nextN;
        $documentObjId = ++$nextN;
        $structTreeRootObjId = ++$nextN;
        
        SimpleLogger::log("pdf_backend_structure_tree_logs", __METHOD__, 
            sprintf("Calculated ObjIDs: %d StructElems, Document=%d, StructTreeRoot=%d", 
                count($allSemanticElements), $documentObjId, $structTreeRootObjId)
        );
        
        // ========================================================================
        // PHASE 3: BUILD PDF STRINGS (NO I/O!)
        // ========================================================================
        
        // Build mapping: element ID → [MCID array, page]
        $frameIdToMCIDs = [];
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
        
        // Track HTML ID → StructElem Object ID (for TH/TD Headers)
        $htmlIdToObjId = [];
        
        // Build StructElem strings
        $structElemObjIds = [];
        foreach ($allSemanticElements as $semantic) {
            $objId = $frameIdToObjId[$semantic->id];
            $structElemObjIds[] = $objId;
            
            $pdfTag = $semantic->getPdfStructureTag();
            
            // Track HTML id attribute for TH elements
            $htmlId = $semantic->getAttribute('id', null);
            if ($htmlId !== null && $htmlId !== '' && $pdfTag === 'TH') {
                $htmlIdToObjId[$htmlId] = $objId;
            }
            
            // Get parent object ID (skip non-semantic wrappers like html/body)
            $parent = $semantic->getParent();
            
            // Walk up tree skipping non-semantic wrappers
            while ($parent !== null && $parent->isNonSemanticWrapper()) {
                $parent = $parent->getParent();
            }
            
            $parentObjId = $parent !== null && isset($frameIdToObjId[$parent->id])
                ? $frameIdToObjId[$parent->id]
                : $documentObjId;
            
            // Build StructElem object string
            $out = '<<';
            $out .= ' /Type /StructElem';
            $out .= ' /S /' . $pdfTag;
            $out .= sprintf(' /P %d 0 R', $parentObjId);
            
            // Add /K (kids)
            if (isset($frameIdToMCIDs[$semantic->id])) {
                // Element was rendered → has MCIDs
                $mcids = $frameIdToMCIDs[$semantic->id]['mcids'];
                $pageNum = $frameIdToMCIDs[$semantic->id]['page'];
                
                // Track MCID → StructElem for ParentTree
                foreach ($mcids as $mcid) {
                    $mcidToObjId[$mcid] = $objId;
                }
                
                // Add page reference
                if (isset($pageObjIds[$pageNum])) {
                    $out .= sprintf(' /Pg %d 0 R', $pageObjIds[$pageNum]);
                }
                
                // Add MCID(s)
                if (count($mcids) === 1) {
                    $out .= ' /K ' . $mcids[0];
                } else {
                    $out .= ' /K [' . implode(' ', $mcids) . ']';
                }
            } else {
                // Container element → has child StructElems
                $children = $semantic->getChildren();
                
                // Collect actual children, skipping non-semantic wrappers and getting their children instead
                $actualChildren = [];
                foreach ($children as $child) {
                    if ($child->isNonSemanticWrapper()) {
                        // Skip wrapper, add its children instead
                        foreach ($child->getChildren() as $grandchild) {
                            $actualChildren[] = $grandchild;
                        }
                    } else {
                        $actualChildren[] = $child;
                    }
                }
                
                if (!empty($actualChildren)) {
                    $childObjIds = array_filter(
                        array_map(fn($child) => $frameIdToObjId[$child->id] ?? null, $actualChildren),
                        fn($objId) => $objId !== null && $objId > 0
                    );
                    
                    if (!empty($childObjIds)) {
                        $out .= ' /K [' . implode(' 0 R ', $childObjIds) . ' 0 R]';
                    }
                }
            }
            
            // Add alt text for images
            if ($semantic->isImage() && $semantic->hasAltText()) {
                $altText = TCPDF_STATIC::_escape($semantic->getAltText());
                $out .= ' /Alt (' . $altText . ')';
            }
            
            // Add TH Scope attribute
            if ($pdfTag === 'TH') {
                $scope = 'Column';
                $out .= ' /A << /O /Table /Scope /' . $scope . ' >>';
                
                $thId = $semantic->getAttribute('id', null);
                if ($thId !== null && $thId !== '') {
                    $out .= ' /ID (' . TCPDF_STATIC::_escape($thId) . ')';
                }
            }
            
            // Add TD Headers attribute
            if ($pdfTag === 'TD') {
                $headersAttr = $semantic->getAttribute('headers', null);
                if ($headersAttr !== null && $headersAttr !== '') {
                    $headerIds = array_filter(array_map('trim', explode(' ', trim($headersAttr))));
                    if (!empty($headerIds)) {
                        $headerIdStrings = array_map(function($id) {
                            return '(' . TCPDF_STATIC::_escape($id) . ')';
                        }, $headerIds);
                        $out .= ' /A << /O /Table /Headers [' . implode(' ', $headerIdStrings) . '] >>';
                    }
                }
            }
            
            // Add RowSpan/ColSpan for table cells
            if ($pdfTag === 'TD' || $pdfTag === 'TH') {
                $rowspan = (int)$semantic->getAttribute('rowspan', '1');
                if ($rowspan > 1) {
                    $out .= ' /RowSpan ' . $rowspan;
                }
                
                $colspan = (int)$semantic->getAttribute('colspan', '1');
                if ($colspan > 1) {
                    $out .= ' /ColSpan ' . $colspan;
                }
            }
            
            $out .= ' >>';
            $out .= "\n".'endobj';
            
            $strings[] = $out;
            
            SimpleLogger::log("pdf_backend_structure_tree_logs", __METHOD__, 
                sprintf("Built StructElem string for obj %d: /%s (frame %d, parent obj %d)", 
                    $objId, $pdfTag, $semantic->id, $parentObjId)
            );
        }
        
        // Build Link StructElem strings
        $linkStructElemObjIds = [];
        foreach ($annotationObjects as $annot) {
            $linkObjId = array_shift($calculatedLinkObjIds);
            
            $out = '<<';
            $out .= ' /Type /StructElem';
            $out .= ' /S /Link';
            $out .= sprintf(' /P %d 0 R', $documentObjId);
            $out .= sprintf(' /K << /Type /OBJR /Obj %d 0 R >>', $annot['obj_id']);
            
            $altText = !empty($annot['url']) ? 'Link to ' . $annot['url'] : $annot['text'];
            $out .= ' /Alt (' . TCPDF_STATIC::_escape($altText) . ')';
            
            $out .= ' >>';
            $out .= "\n".'endobj';
            
            $strings[] = $out;
            $linkStructElemObjIds[] = $linkObjId;
        }
        
        // Collect top-level StructElem IDs (parent not in structure tree)
        $topLevelObjIds = [];
        foreach ($allSemanticElements as $semantic) {
            $parent = $semantic->getParent();
            if ($parent === null || !isset($frameIdToObjId[$parent->id])) {
                $topLevelObjIds[] = $frameIdToObjId[$semantic->id];
            }
        }
        
        // Links are also top-level
        $topLevelObjIds = array_merge($topLevelObjIds, $linkStructElemObjIds);
        
        SimpleLogger::log("pdf_backend_structure_tree_logs", __METHOD__, 
            sprintf("Document /K will reference %d top-level StructElems", count($topLevelObjIds))
        );
        
        // Build ParentTree string
        $out = '<< /Nums [';
        $out .= ' 1 [';  // Adobe ignores index 0, use 1 for page
        if (!empty($mcidToObjId)) {
            ksort($mcidToObjId);
            $mcidObjRefs = array_map(fn($objId) => $objId . ' 0 R', $mcidToObjId);
            $out .= implode(' ', $mcidObjRefs);
        }
        $out .= ']';
        
        // Annotation indices
        foreach ($linkStructElemObjIds as $idx => $linkObjId) {
            $annotIndex = $idx + 2;
            $out .= sprintf(' %d %d 0 R', $annotIndex, $linkObjId);
        }
        
        $out .= ' ] >>';
        $out .= "\n".'endobj';
        $strings[] = $out;
        
        // Build Document string
        $out = '<<';
        $out .= ' /Type /StructElem';
        $out .= ' /S /Document';
        $out .= sprintf(' /P %d 0 R', $structTreeRootObjId);
        $out .= ' /K [' . implode(' 0 R ', $topLevelObjIds) . ' 0 R]';
        $out .= ' >>';
        $out .= "\n".'endobj';
        $strings[] = $out;
        
        // Build StructTreeRoot string
        $out = '<<';
        $out .= ' /Type /StructTreeRoot';
        $out .= sprintf(' /K [%d 0 R]', $documentObjId);
        $out .= sprintf(' /ParentTree %d 0 R', $parentTreeObjId);
        $out .= ' /ParentTreeNextKey 3';
        $out .= ' /RoleMap << /Strong /Span /Em /Span >>';
        
        // Build IDTree for TH elements
        if (!empty($htmlIdToObjId)) {
            $idTreeEntries = [];
            foreach ($htmlIdToObjId as $htmlId => $objId) {
                $idTreeEntries[] = '(' . TCPDF_STATIC::_escape($htmlId) . ') ' . $objId . ' 0 R';
            }
            $out .= ' /IDTree << /Names [' . implode(' ', $idTreeEntries) . '] >>';
        }
        
        $out .= ' >>';
        $out .= "\n".'endobj';
        $strings[] = $out;
        
        SimpleLogger::log("pdf_backend_structure_tree_logs", __METHOD__, 
            sprintf("Generated %d PDF object strings", count($strings))
        );

        // ========================================================================
        // RETURN STRINGS + METADATA AND CACHE STRUCTURE OBJ ID
        // ========================================================================
        $this->structureObjId = $structTreeRootObjId;
        
        return [
            'strings' => $strings,
            'struct_tree_root_obj_id' => $structTreeRootObjId,
            'document_obj_id' => $documentObjId
        ];
    }
}
