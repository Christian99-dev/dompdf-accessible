<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticElement;

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
        public readonly ?SemanticElement $element,
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
    public static function tagged(SemanticElement $element, string $pdfTag): self
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
     * Create Line-Break decision (frame continues parent's BDC)
     * 
     * Used for: Line-break frames created during text reflow
     * These frames have no semantic element but should continue parent's BDC.
     * 
     * @param SemanticElement $parentElement The parent element to continue
     * @param string $pdfTag The PDF tag from parent
     */
    public static function lineBreak(SemanticElement $parentElement, string $pdfTag): self
    {
        return new self($parentElement, $pdfTag, false, false, true, 'Line-break frame');
    }
    
    /**
     * Create Inherit decision (frame inherits from immediate parent)
     * 
     * Used for: Text fragments and reflow frames that have no direct semantic element
     * These frames inherit the semantic context from their immediate parent container.
     * 
     * @param SemanticElement $parentElement The parent element to inherit from
     * @param string $pdfTag The PDF tag from parent
     */
    public static function inherit(SemanticElement $parentElement, string $pdfTag): self
    {
        return new self($parentElement, $pdfTag, false, false, true, 'Inherit from parent');
    }
}

/**
 * Tagging Manager - Resolves semantic elements to PDF tagging decisions
 * 
 * This class encapsulates ALL semantic resolution logic:
 * - Semantic element lookup from registry
 * - Decorative element detection (aria-hidden, role=presentation)
 * - Transparent inline tag handling (strong, em, span)
 * - Parent element resolution for #text nodes
 * - PDF structure tag mapping (HTML → PDF/UA)
 * 
 * **Single Responsibility:** Semantic → PDF Tag Resolution
 * 
 * Usage:
 * ```php
 * $manager = new TaggingManager($semanticElementsRef);
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
     * Reference to semantic elements registry from Canvas
     * @var SemanticElement[]
     */
    private array $semanticElementsRef;
    
    /**
     * Constructor
     * 
     * @param array $semanticElementsRef Reference to Canvas semantic elements array
     */
    public function __construct(array &$semanticElementsRef)
    {
        $this->semanticElementsRef = &$semanticElementsRef;
    }
    
    /**
     * Get current semantic element by frame ID
     * 
     * @param string|null $frameId Frame ID being rendered
     * @return SemanticElement|null Element or null if not found
     */
    public function getCurrentElement(?string $frameId): ?SemanticElement
    {
        if ($frameId === null) {
            return null;
        }
        
        return $this->semanticElementsRef[$frameId] ?? null;
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
        // SIMPLIFIED APPROACH: Much cleaner logic with inheritance
        
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
        
        // STEP 2: Check if decorative
        if ($semantic->isDecorative()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Element %s is decorative → Artifact", $semantic->id)
            );
            return TaggingDecision::artifact(sprintf('Decorative <%s>', $semantic->tag));
        }
        
        // STEP 3: Check if transparent inline tag (BEFORE resolving parent!)
        // These tags (strong, em, span) should NOT create BDC blocks
        if ($semantic->isTransparentInlineTag()) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Element %s is transparent <%s> → Transparent (styling only)", 
                    $semantic->id, $semantic->tag)
            );
            return TaggingDecision::transparent(sprintf('Transparent <%s>', $semantic->tag));
        }
        
        // STEP 4: Resolve actual tagging element (for #text nodes)
        $tagElement = $this->resolveTaggingElement($semantic);
        
        if ($tagElement === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Could not resolve tagging element for %s → Artifact", $semantic->id)
            );
            return TaggingDecision::artifact('No tagging element resolved');
        }
        
        // STEP 5: Get PDF tag and create decision
        $pdfTag = $tagElement->getPdfStructureTag();
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Resolved frame %s: <%s> → /%s", 
                $frameId, $tagElement->tag, $pdfTag)
        );
        
        return TaggingDecision::tagged($tagElement, $pdfTag);
    }
    
    /**
     * Resolve the actual element to use for tagging
     * 
     * Handles special cases:
     * - #text nodes → use parent
     * - Regular elements → use as-is
     * 
     * Note: Transparent inline tags are handled in resolveTagging() 
     * and never reach this method.
     * 
     * @param SemanticElement $semantic The semantic element
     * @return SemanticElement|null The element to use for tagging
     */
    private function resolveTaggingElement(SemanticElement $semantic): ?SemanticElement
    {
        // CASE 1: #text node → use parent
        if ($semantic->tag === '#text') {
            $parent = $this->findImmediateParent($semantic->id);
            
            if ($parent === null) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    "No parent found for #text node {$semantic->id}"
                );
                return null;
            }
            
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                sprintf("Using parent <%s> for #text node", $parent->tag)
            );
            
            return $parent;
        }
        
        // CASE 2: Regular element → use as-is
        return $semantic;
    }
    
    /**
     * Check if an element should be wrapped as Artifact
     * 
     * Convenience method for quick artifact checks.
     * 
     * @param SemanticElement|null $semantic Semantic element (or null)
     * @return bool True if should be wrapped as Artifact
     */
    public function shouldWrapAsArtifact(?SemanticElement $semantic): bool
    {
        if ($semantic === null) {
            return true;
        }
        
        return $semantic->isDecorative();
    }
    
    /**
     * Process a semantic element that exists (simplified logic)
     * 
     * @param SemanticElement $semantic The semantic element to process
     * @return TaggingDecision The tagging decision
     */
    private function processSemanticElement(SemanticElement $semantic): TaggingDecision
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
            $tagElement = $this->resolveTaggingElement($semantic);
            if ($tagElement === null) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Could not resolve tagging element for %s → Artifact", $semantic->id)
                );
                return TaggingDecision::artifact('Could not resolve #text parent');
            }
            $semantic = $tagElement;
        }
        
        // Regular semantic element
        $pdfTag = $semantic->getPdfStructureTag();
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("Resolved frame %s: <%s> → /%s", $semantic->id, $semantic->tag, $pdfTag)
        );
        return TaggingDecision::tagged($semantic, $pdfTag);
    }
    
    /**
     * Find immediate parent semantic element (simplified search)
     * 
     * @param string $frameId The frame ID to find parent for
     * @return SemanticElement|null The immediate parent element
     */
    private function findImmediateParent(string $frameId): ?SemanticElement
    {
        $frameNumber = (int)$frameId;
        
        // Simple search backwards for the nearest semantic container (limited scope)
        for ($i = $frameNumber - 1; $i >= max(0, $frameNumber - 20); $i--) {
            $element = $this->semanticElementsRef[(string)$i] ?? null;
            if ($element && $this->isContentContainer($element)) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Found immediate parent for frame %s: <%s> (frame %s)", 
                        $frameId, $element->tag, $element->id)
                );
                return $element;
            }
        }
        
        return null;
    }
    
    /**
     * Check if element is a content container (likely to contain text)
     * 
     * @param SemanticElement $element The element to check
     * @return bool True if this is a content container
     */
    private function isContentContainer(SemanticElement $element): bool
    {
        $contentTags = ['div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'li', 'span'];
        return in_array($element->tag, $contentTags) && !$element->isDecorative();
    }
}
