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
 * Tagging Manager - Resolves semantic elements to PDF tagging decisions
 * 
 * This class provides a single static method to resolve frame IDs to tagging decisions.
 * No state, no constructor, just pure logic.
 * 
 * **Single Responsibility:** Frame ID + Tree → Tagging Decision
 * 
 * Usage:
 * ```php
 * $decision = TaggingManager::resolveTagging($tree, $frameId);
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
     * Resolve tagging decision for current frame
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
    public static function resolveTagging(SemanticTree $tree, ?string $frameId): TaggingDecision
    {
        // EARLY RETURN 1: No frame ID
        if ($frameId === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, "No frame ID → Artifact");
            return TaggingDecision::artifact('No frame ID', $frameId);
        }
        
        // EARLY RETURN 2: No semantic element → Try parent (text fragment)
        $semantic = $tree->getNodeById($frameId);
        if ($semantic === null) {
            return self::handleMissingNode($tree, $frameId);
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
            return self::handleTextNode($tree, $semantic);
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
    private static function handleMissingNode(SemanticTree $tree, string $frameId): TaggingDecision
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
    private static function handleTextNode(SemanticTree $tree, SemanticNode $textNode): TaggingDecision
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
}
