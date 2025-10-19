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
 * @package dompdf-accessible
 */
class TaggingDecision
{
    public function __construct(
        public readonly ?SemanticElement $element,
        public readonly string $pdfTag,
        public readonly bool $isArtifact,
        public readonly string $reason = ''
    ) {}
    
    /**
     * Create Artifact decision
     */
    public static function artifact(string $reason = ''): self
    {
        return new self(null, '', true, $reason);
    }
    
    /**
     * Create Tagged Content decision
     */
    public static function tagged(SemanticElement $element, string $pdfTag): self
    {
        return new self($element, $pdfTag, false, '');
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
     * 1. No semantic element → Artifact (e.g., TCPDF footer)
     * 2. Decorative element → Artifact (aria-hidden, role=presentation)
     * 3. #text node → Use parent element
     * 4. Transparent inline tag → Use semantic parent (skip strong, em, span)
     * 5. Regular element → Tag with PDF structure tag
     * 
     * @param string|null $frameId Current frame ID being rendered
     * @return TaggingDecision Decision with element, PDF tag, and artifact flag
     */
    public function resolveTagging(?string $frameId): TaggingDecision
    {
        // STEP 1: Get semantic element
        $semantic = $this->getCurrentElement($frameId);
        
        if ($semantic === null) {
            SimpleLogger::log("tagging_manager", __METHOD__, 
                "No semantic element for frame {$frameId} → Artifact"
            );
            return TaggingDecision::artifact('No semantic element (e.g., TCPDF footer)');
        }
        
        // STEP 2: Check if decorative
        if ($semantic->isDecorative()) {
            SimpleLogger::log("tagging_manager", __METHOD__, 
                sprintf("Element %s is decorative → Artifact", $semantic->id)
            );
            return TaggingDecision::artifact(sprintf('Decorative <%s>', $semantic->tag));
        }
        
        // STEP 3: Resolve actual tagging element
        $tagElement = $this->resolveTaggingElement($semantic);
        
        if ($tagElement === null) {
            SimpleLogger::log("tagging_manager", __METHOD__, 
                sprintf("Could not resolve tagging element for %s → Artifact", $semantic->id)
            );
            return TaggingDecision::artifact('No tagging element resolved');
        }
        
        // STEP 4: Get PDF tag and create decision
        $pdfTag = $tagElement->getPdfStructureTag();
        
        SimpleLogger::log("tagging_manager", __METHOD__, 
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
     * - Transparent inline tags (strong, em, span) → use semantic parent
     * - Regular elements → use as-is
     * 
     * @param SemanticElement $semantic The semantic element
     * @return SemanticElement|null The element to use for tagging
     */
    private function resolveTaggingElement(SemanticElement $semantic): ?SemanticElement
    {
        // CASE 1: #text node → use parent
        if ($semantic->tag === '#text') {
            $parent = $this->findParentElement($semantic->id, false);
            
            if ($parent === null) {
                SimpleLogger::log("tagging_manager", __METHOD__, 
                    "No parent found for #text node {$semantic->id}"
                );
                return null;
            }
            
            SimpleLogger::log("tagging_manager", __METHOD__, 
                sprintf("Using parent <%s> for #text node", $parent->tag)
            );
            
            return $parent;
        }
        
        // CASE 2: Transparent inline styling tag → use semantic parent
        if ($semantic->isTransparentInlineTag()) {
            $semanticParent = $this->findParentElement($semantic->id, true);
            
            if ($semanticParent === null) {
                SimpleLogger::log("tagging_manager", __METHOD__, 
                    sprintf("Warning: No semantic parent for transparent <%s>, using anyway", 
                        $semantic->tag)
                );
                return $semantic; // Fallback to using the tag itself
            }
            
            SimpleLogger::log("tagging_manager", __METHOD__, 
                sprintf("Skipping transparent <%s> → using parent <%s>", 
                    $semantic->tag, $semanticParent->tag)
            );
            
            return $semanticParent;
        }
        
        // CASE 3: Regular element → use as-is
        return $semantic;
    }
    
    /**
     * Find parent semantic element
     * 
     * Searches backwards through frame IDs to find parent element.
     * 
     * @param string $frameId The starting frame ID
     * @param bool $skipTransparentTags If true, skip transparent inline styling tags
     * @return SemanticElement|null Parent element or null
     */
    private function findParentElement(string $frameId, bool $skipTransparentTags = false): ?SemanticElement
    {
        // Search backwards from current frame ID
        // Parent frames have lower IDs in Dompdf's frame tree
        for ($parentId = (int)$frameId - 1; $parentId >= 0; $parentId--) {
            if (isset($this->semanticElementsRef[(string)$parentId])) {
                $parent = $this->semanticElementsRef[(string)$parentId];
                
                // Always skip #text parents (they don't define structure)
                if ($parent->tag === '#text') {
                    continue;
                }
                
                // Optionally skip transparent inline styling tags
                if ($skipTransparentTags && $parent->isTransparentInlineTag()) {
                    continue;
                }
                
                return $parent;
            }
        }
        
        return null;
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
}
