<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticElement;
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
        public readonly string $reason = ''
    ) {}
    
    /**
     * Create Artifact decision
     */
    public static function artifact(string $reason = ''): self
    {
        return new self(null, '', true, false, false, $reason);
    }
    
    /**
     * Create Tagged Content decision
     */
    public static function tagged(SemanticNode $element, string $pdfTag): self
    {
        return new self($element, $pdfTag, false, false, false, '');
    }
    
    /**
     * Create Transparent decision (element only provides styling, no separate BDC)
     * 
     * Used for: <strong>, <em>, <span>, etc.
     * These elements change font/color but don't create structure elements.
     */
    public static function transparent(string $reason = ''): self
    {
        return new self(null, '', false, true, false, $reason);
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
    public static function inherit(SemanticNode $parentElement, string $pdfTag, string $reason = 'Inherit from parent'): self
    {
        return new self($parentElement, $pdfTag, false, false, true, $reason);
    }
}

/**
 * Tagging Manager - Resolves semantic elements to PDF tagging decisions
 * 
 * This class encapsulates ALL semantic resolution logic:
 * - Semantic element lookup from tree
 * - Decorative element detection (aria-hidden, role=presentation)
 * - Transparent inline tag handling (strong, em, span)
 * - Parent element resolution for #text nodes
 * - PDF structure tag mapping (HTML → PDF/UA)
 * 
 * **Single Responsibility:** Semantic → PDF Tag Resolution
 * 
 * Usage:
 * ```php
 * $manager = new TaggingManager($semanticTree);
 * 
 * $decision = $manager->resolveTagging($frameId);
 * 
 * if ($decision->isArtifact) {
 *     // Wrap as /Artifact BMC ... EMC
 * } else {
 *     // Tag with $decision->pdfTag (e.g., /P, /H1)
 * }
 * ```
 * 
 * @package dompdf-accessible
 */
class TaggingManager
{
    /**
     * Semantic tree for O(1) node access
     * @var SemanticTree
     */
    private SemanticTree $tree;
    
    /**
     * Constructor
     * 
     * @param SemanticTree $tree Semantic tree with all nodes
     */
    public function __construct(SemanticTree $tree)
    {
        $this->tree = $tree;
    }
    
    /**
     * Get current semantic node by frame ID (O(1) HashMap lookup!)
     * 
     * @param string|null $frameId Frame ID being rendered
     * @return SemanticNode|null Node or null if not found
     */
    public function getCurrentElement(?string $frameId): ?SemanticNode
    {
        if ($frameId === null) {
            return null;
        }
        
        return $this->tree->getNodeById($frameId);  // O(1)!
    }
    
    /**
     * Resolve tagging decision for current frame
     * 
     * This is the MAIN method that implements the complete tagging logic:
     * 1. No semantic element → Check if line-break frame OR Artifact
     * 2. Decorative element → Artifact (aria-hidden, role=presentation)
     * 3. Transparent inline tag → Transparent (styling only, no BDC)
     * 4. #text node → Use parent element for tagging
     * 5. Regular element → Tag with PDF structure tag
     * 
     * @param string|null $frameId Current frame ID being rendered
     * @return TaggingDecision Decision with element, PDF tag, and mode flags
     */
    public function resolveTagging(?string $frameId): TaggingDecision
    {
        // STEP 1: Try to get direct semantic element
        $semantic = $this->getCurrentElement($frameId);
        
        if ($semantic !== null) {
            // We have a direct semantic element - process it normally
            return $this->processSemanticElement($semantic);
        }
        
        // STEP 2: No semantic element - this is a text fragment or reflow frame
        // Use simple inheritance from immediate parent
        $parentElement = $this->findImmediateParent($frameId);
        
        if ($parentElement !== null) {
            $pdfTag = $parentElement->getPdfStructureTag();
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Frame %s inherits from parent <%s> /%s (frame %s)", 
                    $frameId, $parentElement->tag, $pdfTag, $parentElement->id)
            );
            return TaggingDecision::inherit($parentElement, $pdfTag);
        }
        
        // STEP 3: No parent found → Artifact (e.g., TCPDF footer)
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            "No semantic element or parent for frame {$frameId} → Artifact"
        );
        return TaggingDecision::artifact('No semantic element (e.g., TCPDF footer)');
    }
    
    /**
     * Process a semantic node that exists (simplified logic)
     * 
     * @param SemanticNode $semantic The semantic node to process
     * @return TaggingDecision The tagging decision
     */
    private function processSemanticElement(SemanticNode $semantic): TaggingDecision
    {
        // Check if decorative
        if ($semantic->isDecorative()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Element %s is decorative → Artifact", $semantic->id)
            );
            return TaggingDecision::artifact(sprintf('Decorative <%s>', $semantic->tag));
        }
        
        // Check if transparent inline tag (BEFORE resolving parent!)
        if ($semantic->isTransparentInlineTag()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Element %s is transparent <%s> → Transparent (styling only)", 
                    $semantic->id, $semantic->tag)
            );
            return TaggingDecision::transparent(sprintf('Transparent <%s>', $semantic->tag));
        }
        
        // For #text nodes, resolve to parent element
        if ($semantic->tag === '#text') {
            $parent = $this->findImmediateParent($semantic->id);
            
            if ($parent === null) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Could not resolve #text parent for %s → Artifact", $semantic->id)
                );
                return TaggingDecision::artifact('Could not resolve #text parent');
            }
            
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Using parent <%s> for #text node", $parent->tag)
            );
            
            $semantic = $parent;
        }
        
        // Regular semantic element
        $pdfTag = $semantic->getPdfStructureTag();
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Resolved frame %s: <%s> → /%s", $semantic->id, $semantic->tag, $pdfTag)
        );
        return TaggingDecision::tagged($semantic, $pdfTag);
    }
    
    /**
     * Find immediate parent semantic node
     * 
     * ARCHITECTURE: Tree contains ONLY semantic containers (div, p, h1, td, etc.)
     * Text frames created during rendering are NOT in tree → need numeric fallback
     * 
     * STRATEGY:
     * 1. Fast path: Direct tree lookup O(1) - works for semantic containers
     * 2. Slow path: Numeric fallback O(n) - for text/line-break frames
     * 
     * OPTIMIZATIONS:
     * - Simplified regex (one pattern vs three)
     * - Reduced search range (50 vs 100 frames)
     * - Fewer format attempts (2 vs 3 per iteration)
     * 
     * @param string $frameId The frame ID to find parent for
     * @return SemanticNode|null The immediate parent node
     */
    private function findImmediateParent(string $frameId): ?SemanticNode
    {
        // FAST PATH: Direct tree lookup (O(1))
        $node = $this->tree->getNodeById($frameId);
        
        if ($node !== null) {
            // Found in tree → walk up parent chain
            $parent = $node->findParentWhere(fn($p) => $p->isContentContainer());
            
            if ($parent !== null) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Tree: frame %s → parent <%s> (frame %s)", 
                        $frameId, $parent->tag, $parent->id)
                );
            }
            
            return $parent;
        }
        
        // SLOW PATH: Numeric fallback for text/line-break frames
        // Extract numeric ID (simplified regex - matches end digits)
        if (!preg_match('/(\d+)$/', $frameId, $matches)) {
            return null;  // No numeric ID → can't search
        }
        
        $currentId = (int)$matches[1];
        
        // Search backwards up to 50 frames (reduced from 100)
        for ($i = $currentId - 1; $i > 0 && $i > $currentId - 50; $i--) {
            // Try common formats (reduced from 3 to 2)
            foreach ([(string)$i, "frame_{$i}"] as $candidateId) {
                $candidate = $this->tree->getNodeById($candidateId);
                
                if ($candidate !== null && $candidate->isContentContainer()) {
                    SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                        sprintf("Fallback: frame %s → parent <%s> (frame %s)", 
                            $frameId, $candidate->tag, $candidate->id)
                    );
                    return $candidate;
                }
            }
        }
        
        return null;  // No parent found
    }
}
