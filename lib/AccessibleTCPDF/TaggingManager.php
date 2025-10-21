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
        // STEP 1: Try to get direct semantic element from tree
        $semantic = $frameId !== null ? $this->tree->getNodeById($frameId) : null;
        
        if ($semantic !== null) {
            // We have a direct semantic element - process it normally
            return $this->processSemanticElement($semantic);
        }
        
        // STEP 2: No semantic element - this is a text fragment or reflow frame
        // Use simple inheritance from immediate parent
        $parentElement = $this->tree->findContentContainerParent($frameId);
        
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
            $parent = $this->tree->findContentContainerParent($semantic->id);
            
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
}
