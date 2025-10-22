<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticTree;
use Dompdf\SemanticNode;

/**
 * Tagging Decision - Result of semantic element resolution
 * 
 * This immutable value object represents the tagging decision
 * for a rendered element.
 * 
 * FOUR MODES:
 * 1. ARTIFACT: Content wrapped as /Artifact (no structure)
 * 2. TAGGED: Content wrapped in BDC/EMC with structure tag
 * 3. TRANSPARENT: Content inherits parent's BDC (styling only, no separate tag)
 * 4. LINE_BREAK: Line-break frame continues parent's BDC (multi-line text)
 * 
 * @package dompdf-accessible
 */
class TaggingDecision
{
    public function __construct(
        public readonly ?SemanticNode $element,
        public readonly string $pdfTag,
        public readonly bool $isArtifact,
        public readonly bool $isTransparent,
        public readonly bool $isLineBreak,
        public readonly string $reason = '',
        public readonly ?string $frameId = null
    ) {}
    
    /**
     * Create Artifact decision
     */
    public static function artifact(string $reason = '', ?string $frameId = null): self
    {
        return new self(null, '', true, false, false, $reason, $frameId);
    }
    
    /**
     * Create Tagged Content decision
     */
    public static function tagged(SemanticNode $element, string $pdfTag, ?string $frameId = null): self
    {
        return new self($element, $pdfTag, false, false, false, '', $frameId);
    }
    
    /**
     * Create Transparent decision (element only provides styling, no separate BDC)
     * 
     * Used for: <strong>, <em>, <span>, etc.
     * These elements change font/color but don't create structure elements.
     */
    public static function transparent(string $reason = '', ?string $frameId = null): self
    {
        return new self(null, '', false, true, false, $reason, $frameId);
    }
    
    /**
     * Create Inherit decision (frame inherits from immediate parent)
     * 
     * Used for text fragments, line-break frames, and reflow frames that have no direct semantic element.
     * 
     * @param SemanticNode $parentElement The parent element to inherit from
     * @param string $pdfTag The PDF tag from parent
     * @param string $reason Optional reason for inheritance
     */
    public static function inherit(SemanticNode $parentElement, string $pdfTag, string $reason = 'Inherit from parent', ?string $frameId = null): self
    {
        return new self($parentElement, $pdfTag, false, false, true, $reason, $frameId);
    }
}

/**
 * BDC State Manager - Manages PDF Structure Tagging (Complete Solution)
 * 
 * This class is the SINGLE source of truth for PDF/UA structure tagging.
 * It combines semantic resolution + BDC lifecycle management in one cohesive unit.
 * 
 * This class encapsulates ALL state management for PDF Tagged Content:
 * - Tracks active BDC (Marked Content) blocks
 * - Manages nesting depth for proper EMC closing
 * - Handles frame transitions and boundary detection
 * - Generates PDF operators (BDC/EMC strings)
 * 
 * **Single Responsibility:** BDC/EMC State Machine
 * 
 * Usage:
 * ```php
 * $manager = new BDCStateManager();
 * 
 * // Check if new BDC needed
 * if ($manager->shouldOpenNewBDC($semanticId)) {
 *     $pdf .= $manager->closePreviousBDC();
 *     $pdf .= $manager->openBDC($pdfTag, $mcid, $semanticId);
 * }
 * 
 * // Close at end
 * $pdf .= $manager->closeBDC();
 * ```
 * 
 * @package dompdf-accessible
 */
class BDCStateManager
{
    /**
     * Currently active BDC block
     * Format: ['semanticId' => string, 'pdfTag' => string, 'mcid' => int] or null
     * @var array|null
     */
    private ?array $activeBDCFrame = null;
    
    /**
     * Current BDC nesting depth
     * Used to prevent wrapping graphics operations as Artifacts inside tagged content
     * @var int
     */
    private int $bdcDepth = 0;
    
    // ========================================================================
    // SEMANTIC RESOLUTION (private, internal use only)
    // ========================================================================
    
    /**
     * Resolve tagging decision for current frame (INTERNAL)
     * 
     * This method implements the complete tagging logic with early returns:
     * 1. No frame ID → Artifact
     * 2. No semantic element → Try parent (text fragment/reflow)
     * 3. Decorative element → Artifact
     * 4. Transparent inline tag → Transparent
     * 5. #text node → Use parent element
     * 6. Regular element → Tag with PDF structure tag
     * 
     * @param SemanticTree $tree The semantic tree
     * @param string|null $frameId Current frame ID being rendered
     * @return TaggingDecision Decision with element, PDF tag, and mode flags
     */
    private function resolveTagging(SemanticTree $tree, ?string $frameId): TaggingDecision
    {
        // EARLY RETURN 1: No frame ID
        if ($frameId === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "No frame ID → Artifact");
            return TaggingDecision::artifact('No frame ID', $frameId);
        }
        
        // EARLY RETURN 2: No semantic element → Try parent (text fragment)
        $semantic = $tree->getNodeById($frameId);
        if ($semantic === null) {
            return $this->handleMissingNode($tree, $frameId);
        }
        
        // EARLY RETURN 3: Decorative element → Artifact
        if ($semantic->isDecorative()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Element %s is decorative → Artifact", $semantic->id)
            );
            return TaggingDecision::artifact(sprintf('Decorative <%s>', $semantic->tag), $frameId);
        }
        
        // EARLY RETURN 4: Transparent inline tag → Transparent
        if ($semantic->isTransparentInlineTag()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Element %s is transparent <%s> → Transparent (styling only)", 
                    $semantic->id, $semantic->tag)
            );
            return TaggingDecision::transparent(sprintf('Transparent <%s>', $semantic->tag), $frameId);
        }
        
        // EARLY RETURN 5: #text node → Use parent element
        if ($semantic->tag === '#text') {
            return $this->handleTextNode($tree, $semantic);
        }
        
        // FINAL: Regular semantic element → Tagged
        $pdfTag = $semantic->getPdfStructureTag();
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Resolved frame %s: <%s> → /%s", $semantic->id, $semantic->tag, $pdfTag)
        );
        return TaggingDecision::tagged($semantic, $pdfTag, $frameId);
    }
    
    /**
     * Handle missing node (text fragment or reflow frame)
     * 
     * @param SemanticTree $tree The semantic tree
     * @param string $frameId Frame ID
     * @return TaggingDecision Inherit from parent or Artifact
     */
    private function handleMissingNode(SemanticTree $tree, string $frameId): TaggingDecision
    {
        $parent = $tree->findContentContainerParent($frameId);
        
        // No parent found → Artifact
        if ($parent === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                "No semantic element or parent for frame {$frameId} → Artifact"
            );
            return TaggingDecision::artifact('No semantic element (e.g., TCPDF footer)', $frameId);
        }
        
        // Parent found → Inherit
        $pdfTag = $parent->getPdfStructureTag();
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Frame %s inherits from parent <%s> /%s (frame %s)", 
                $frameId, $parent->tag, $pdfTag, $parent->id)
        );
        return TaggingDecision::inherit($parent, $pdfTag, 'Inherit from parent', $frameId);
    }
    
    /**
     * Handle #text node (resolve to parent element)
     * 
     * @param SemanticTree $tree The semantic tree
     * @param SemanticNode $textNode The #text node
     * @return TaggingDecision Tagged with parent's tag or Artifact
     */
    private function handleTextNode(SemanticTree $tree, SemanticNode $textNode): TaggingDecision
    {
        $parent = $tree->findContentContainerParent($textNode->id);
        
        // No parent found → Artifact
        if ($parent === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Could not resolve #text parent for %s → Artifact", $textNode->id)
            );
            return TaggingDecision::artifact('Could not resolve #text parent', $textNode->id);
        }
        
        // Parent found → Tag with parent's tag
        $pdfTag = $parent->getPdfStructureTag();
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Using parent <%s> for #text node → /%s", $parent->tag, $pdfTag)
        );
        return TaggingDecision::tagged($parent, $pdfTag, $textNode->id);
    }
    
    // ========================================================================
    // BDC LIFECYCLE MANAGEMENT
    // ========================================================================
    
    /**
     * Check if a new BDC block should be opened
     * 
     * Decision Logic:
     * - No active BDC → Open new
     * - Different semantic ID → Close previous, open new
     * - Same semantic ID → Continue (no new BDC)
     * 
     * @param string $semanticId The semantic element ID (Frame ID)
     * @return bool True if new BDC should be opened
     */
    private function shouldOpenNewBDC(string $semanticId): bool
    {
        // No active BDC → need to open new one
        if ($this->activeBDCFrame === null) {
            return true;
        }
        
        // Different semantic ID → frame boundary crossed, need new BDC
        if ($this->activeBDCFrame['semanticId'] !== $semanticId) {
            return true;
        }
        
        // Same semantic ID → continue current BDC
        return false;
    }
    
    /**
     * Check if currently inside tagged content
     * 
     * This is used by graphics operations to determine if they should:
     * - Wrap as Artifact (outside BDC, depth = 0)
     * - Suppress completely (inside BDC, depth > 0)
     * 
     * @return bool True if inside tagged content (depth > 0)
     */
    public function isInsideTaggedContent(): bool
    {
        return $this->bdcDepth > 0;
    }
    
    /**
     * Get active BDC frame info
     * 
     * @return array|null ['semanticId', 'pdfTag', 'mcid'] or null if no active BDC
     */
    public function getActiveBDCFrame(): ?array
    {
        return $this->activeBDCFrame;
    }
    
    /**
     * Open a new BDC (Begin Marked Content) block
     * 
     * Generates PDF operator: /Tag << /MCID N >> BDC
     * 
     * @param string $pdfTag PDF structure tag (e.g., 'P', 'H1', 'Figure')
     * @param int $mcid Marked Content ID for ParentTree reference
     * @param string $semanticId Semantic element ID for frame tracking
     * @return string PDF code to output (BDC operator)
     */
    private function openBDC(string $pdfTag, int $mcid, string $semanticId): string
    {
        // Store active BDC state
        $this->activeBDCFrame = [
            'semanticId' => $semanticId,
            'pdfTag' => $pdfTag,
            'mcid' => $mcid
        ];
        
        // Increment nesting depth
        $this->bdcDepth++;
        
        // Log state change
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Opened BDC: /%s MCID=%d for frame %s (depth=%d)", 
                $pdfTag, $mcid, $semanticId, $this->bdcDepth)
        );
        
        // Generate PDF operator
        return sprintf("/%s << /MCID %d >> BDC\n", $pdfTag, $mcid);
    }
    
    /**
     * Close the current BDC block (if any)
     * 
     * Generates PDF operator: EMC
     * 
     * @return string PDF code to output (EMC operator or empty string)
     */
    public function closeBDC(): string
    {
        // No active BDC → nothing to close
        if ($this->activeBDCFrame === null) {
            return '';
        }
        
        // Decrement nesting depth
        $this->bdcDepth--;
        
        // Log state change
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Closed BDC for frame %s (depth=%d)", 
                $this->activeBDCFrame['semanticId'], $this->bdcDepth)
        );
        
        // Clear active BDC state
        $this->activeBDCFrame = null;
        
        // Generate PDF operator
        return "EMC\n";
    }
    
    /**
     * Close previous BDC and open new one (atomic operation)
     * 
     * This is a convenience method for the common pattern:
     * 1. Close previous BDC (if exists)
     * 2. Open new BDC immediately
     * 
     * @param string $pdfTag PDF structure tag for new BDC
     * @param int $mcid Marked Content ID for new BDC
     * @param string $semanticId Semantic element ID for new BDC
     * @return string PDF code with [EMC]\nBDC
     */
    public function closePreviousAndOpenNew(string $pdfTag, int $mcid, string $semanticId): string
    {
        $pdfCode = '';
        
        // Close previous (if exists)
        if ($this->activeBDCFrame !== null) {
            $pdfCode .= $this->closeBDC();
        }
        
        // Open new
        $pdfCode .= $this->openBDC($pdfTag, $mcid, $semanticId);
        
        return $pdfCode;
    }
    
    // ========================================================================
    // PUBLIC API - Single Entry Point
    // ========================================================================
    
    /**
     * Process frame and return PDF operators (UNIFIED API)
     * 
     * This is the SINGLE public entry point for structure tagging.
     * It combines semantic resolution + BDC lifecycle in one call.
     * 
     * **Usage:**
     * ```php
     * $result = $bdcManager->processFrame(
     *     $semanticTree, 
     *     $currentFrameId,
     *     $mcidCounter,
     *     $structureTree,
     *     $page
     * );
     * 
     * if ($result['wrapAsArtifact']) {
     *     $cellCode = wrapAsArtifact($cellCode);
     * }
     * return $result['pdfCode'] . $cellCode;
     * ```
     * 
     * @param SemanticTree $tree The semantic tree
     * @param string|null $frameId Current frame ID being rendered
     * @param int &$mcidCounter MCID counter (passed by reference, incremented when opening BDC)
     * @param array &$structureTree Structure tree (passed by reference, updated when opening BDC)
     * @param int $page Current page number
     * @return array ['pdfCode' => string, 'wrapAsArtifact' => bool] PDF operators to prepend
     */
    public function processFrame(
        SemanticTree $tree,
        ?string $frameId,
        int &$mcidCounter,
        array &$structureTree,
        int $page
    ): array {
        // PHASE 1: Resolve semantic tagging decision
        $decision = $this->resolveTagging($tree, $frameId);
        
        // PHASE 2: Execute BDC lifecycle based on decision
        return $this->processDecision($decision, $mcidCounter, $structureTree, $page);
    }
    
    /**
     * Process tagging decision and return PDF operators (1-PHASE ARCHITECTURE)
     * 
     * This method replaces the 2-phase approach:
     * - OLD: determineBDCAction() → match expression in getCellCode()
     * - NEW: processDecision() → Direct PDF code output
     * 
     * Analyzes TaggingDecision and returns appropriate PDF operators based on current state.
     * 
     * @param TaggingDecision $decision Tagging decision from TaggingManager
     * @param int &$mcidCounter MCID counter (passed by reference, incremented when opening BDC)
     * @param array &$structureTree Structure tree (passed by reference, updated when opening BDC)
     * @param int $page Current page number
     * @return array ['pdfCode' => string, 'wrapAsArtifact' => bool] PDF operators to prepend
     */
    public function processDecision(
        TaggingDecision $decision,
        int &$mcidCounter,
        array &$structureTree,
        int $page
    ): array {
        // Extract data from decision
        $currentFrameId = $decision->frameId ?? 'UNKNOWN';
        $targetElement = $decision->element;
        $isTransparent = $decision->isTransparent;
        $isArtifact = $decision->isArtifact;
        $isLineBreak = $decision->isLineBreak;
        
        // LOGGING
        $targetId = $targetElement ? $targetElement->id : 'NULL';
        $activeBDC = $this->activeBDCFrame ? $this->activeBDCFrame['semanticId'] : 'NONE';
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            "frameId={$currentFrameId}, targetId={$targetId}, activeBDC={$activeBDC}, " .
            "isArtifact={$isArtifact}, isTransparent={$isTransparent}, isLineBreak={$isLineBreak}"
        );
        
        // ====================================================================
        // CASE 1: Artifact content → Close BDC and signal artifact wrapping
        // ====================================================================
        if ($isArtifact) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ CLOSE_AND_ARTIFACT");
            return [
                'pdfCode' => $this->closeBDC(),
                'wrapAsArtifact' => true
            ];
        }
        
        // ====================================================================
        // CASE 2: Transparent tag → Continue in parent's BDC (no new BDC)
        // ====================================================================
        if ($isTransparent) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ CONTINUE (transparent)");
            return [
                'pdfCode' => '',
                'wrapAsArtifact' => false
            ];
        }
        
        // ====================================================================
        // CASE 3: Line-break frame → Continue in parent's BDC (multi-line text)
        // ====================================================================
        if ($isLineBreak && $targetElement !== null) {
            // Verify parent BDC is still active and matches
            if ($this->activeBDCFrame && $this->activeBDCFrame['semanticId'] === $targetElement->id) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ CONTINUE (line-break)");
                return [
                    'pdfCode' => '',
                    'wrapAsArtifact' => false
                ];
            }
            
            // Parent BDC not active → need to open new BDC (fallthrough to CASE 5)
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ OPEN_NEW (line-break, parent BDC closed)");
        }
        
        // ====================================================================
        // CASE 4: No target element → Continue (NULL semantic, inherits context)
        // ====================================================================
        if ($targetElement === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ CONTINUE (NULL)");
            return [
                'pdfCode' => '',
                'wrapAsArtifact' => false
            ];
        }
        
        // ====================================================================
        // CASE 5: Check if new BDC needed based on target element ID
        // ====================================================================
        if ($this->shouldOpenNewBDC($targetElement->id)) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ OPEN_NEW");
            
            // Allocate new MCID
            $mcid = $mcidCounter++;
            
            // Generate PDF operators (close previous, open new)
            $pdfOperators = $this->closePreviousAndOpenNew(
                $decision->pdfTag,
                $mcid,
                $targetElement->id
            );
            
            // Register in structure tree
            $structureTree[] = [
                'type' => 'content',
                'tag' => $decision->pdfTag,
                'mcid' => $mcid,
                'page' => $page,
                'semantic' => $targetElement
            ];
            
            return [
                'pdfCode' => $pdfOperators,
                'wrapAsArtifact' => false
            ];
        }
        
        // ====================================================================
        // CASE 6: Same element → Continue in current BDC
        // ====================================================================
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "→ CONTINUE (same element)");
        return [
            'pdfCode' => '',
            'wrapAsArtifact' => false
        ];
    }
}
