<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticElement;

require_once __DIR__ . '/DrawingContext.php';

/**
 * Drawing Context Manager - Determines how to handle drawing operations
 * 
 * This class analyzes the current rendering context to decide whether
 * drawing operations (Line, Rect, Curve, etc.) should be:
 * 1. Kept as content (text-decoration, part of semantic meaning)
 * 2. Isolated as decorative (table borders, backgrounds)
 * 3. Wrapped as artifact (outside any tagged content)
 * 
 * **Single Responsibility:** Drawing Operation Context Resolution
 * 
 * THREE CASES:
 * ============
 * 
 * CASE 1: CONTENT (Keep in BDC)
 * - Element has text-decoration: underline/line-through
 * - Drawing is semantically part of the text
 * - Example: <p style="text-decoration: underline">text</p>
 * - Action: Keep drawing inside BDC, do NOT wrap
 * 
 * CASE 2: DECORATIVE INSIDE TAG (Close-Artifact-Continue)
 * - Drawing occurs while BDC is open BUT is not part of semantic content
 * - Example: Table borders rendered while TD BDC is still open
 * - Action: Close BDC, wrap as Artifact, reopen BDC
 * 
 * CASE 3: ARTIFACT (Wrap as Artifact)
 * - No active BDC, drawing is purely decorative
 * - Example: Page decorations, standalone graphics
 * - Action: Wrap as /Artifact BMC ... EMC
 * 
 * @package dompdf-accessible
 */
class DrawingContextManager
{
    /**
     * Reference to semantic elements registry (normal elements)
     * @var SemanticElement[]
     */
    private array $semanticElementsRef;
    
    /**
     * Reference to transparent elements registry
     * @var SemanticElement[]
     */
    private array $transparentElementsRef;
    
    /**
     * Constructor
     * 
     * @param array $semanticElementsRef Reference to normal semantic elements array
     * @param array $transparentElementsRef Reference to transparent elements array
     */
    public function __construct(array &$semanticElementsRef, array &$transparentElementsRef)
    {
        $this->semanticElementsRef = &$semanticElementsRef;
        $this->transparentElementsRef = &$transparentElementsRef;
    }
    
    /**
     * Get semantic element by ID (checks both normal and transparent storage)
     * 
     * @param string|null $elementId Element ID to retrieve
     * @return SemanticElement|null Element if found, null otherwise
     */
    private function getSemanticElement(?string $elementId): ?\Dompdf\SemanticElement
    {
        if ($elementId === null) {
            return null;
        }
        
        // Check normal elements first
        if (isset($this->semanticElementsRef[$elementId])) {
            return $this->semanticElementsRef[$elementId];
        }
        
        // Check transparent elements
        if (isset($this->transparentElementsRef[$elementId])) {
            return $this->transparentElementsRef[$elementId];
        }
        
        return null;
    }
    
    /**
     * Determine drawing context
     * 
     * This is the CORE method that decides how to handle a drawing operation.
     * 
     * CRITICAL INSIGHT: Text-decoration detection must check the ENTIRE PARENT CHAIN
     * ================================================================================
     * When TCPDF draws an underline for `<p>text <u>underlined</u> after</p>`:
     * - The BDC points to <p>
     * - The currentFrameId points to the TEXT node "underlined", not the <u> tag!
     * - The <u> tag is transparent and doesn't have its own frame
     * 
     * SOLUTION: Walk up from currentFrame to BDC and check ALL elements for text-decoration
     * 
     * DECISION LOGIC:
     * 1. No active BDC → ARTIFACT
     * 2. Any element in parent chain (currentFrame → BDC) has text-decoration → CONTENT  
     * 3. Active BDC + no text-decoration in chain → DECORATIVE_INSIDE_TAG
     * 
     * @param array|null $activeBDCFrame Active BDC frame info from BDCStateManager
     *                                    Format: ['semanticId' => string, 'pdfTag' => string, 'mcid' => int]
     * @param string|null $currentFrameId Current frame ID being rendered (may be text node child of <u>)
     * @return DrawingContext Context decision
     */
    public function determineContext(?array $activeBDCFrame, ?string $currentFrameId = null): DrawingContext
    {
        // CASE 1: No active BDC → Drawing is outside tagged content
        if ($activeBDCFrame === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                "No active BDC → ARTIFACT"
            );
            return DrawingContext::artifact();
        }
        
        // Get BDC semantic element
        $bdcSemanticId = $activeBDCFrame['semanticId'];
        $bdcSemantic = $this->getSemanticElement($bdcSemanticId);
        
        if ($bdcSemantic === null) {
            SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                "Active BDC but no semantic element → DECORATIVE_INSIDE_TAG"
            );
            return DrawingContext::decorativeInsideTag('No semantic element found');
        }
        
        // CASE 2: Check ENTIRE PARENT CHAIN from currentFrame up to BDC
        // This catches transparent tags like <u>, <span style="text-decoration">
        $elementToCheck = null;
        if ($currentFrameId !== null) {
            $elementToCheck = $this->getSemanticElement($currentFrameId);
        }
        
        // Walk up parent chain checking for text-decoration
        $checked = [];
        while ($elementToCheck !== null) {
            $checked[] = $elementToCheck->id;
            
            if ($this->hasTextDecoration($elementToCheck)) {
                SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
                    sprintf("Found text-decoration in parent chain: %s (<%s>) → CONTENT", 
                        $elementToCheck->id, $elementToCheck->tag)
                );
                return DrawingContext::content(
                    sprintf('Parent <%s> has text-decoration', $elementToCheck->tag)
                );
            }
            
            // Stop at BDC element
            if ($elementToCheck->id === $bdcSemanticId) {
                break;
            }
            
            // Move to parent
            $parentId = $elementToCheck->parentId;
            if ($parentId === null) {
                break;
            }
            $elementToCheck = $this->getSemanticElement($parentId);
        }
        
        SimpleLogger::log("accessible_tcpdf_logs", __METHOD__, 
            sprintf("No text-decoration found in parent chain [%s] → DECORATIVE_INSIDE_TAG", 
                implode(' → ', $checked))
        );
        
        // CASE 3: No text-decoration found → Decorative (borders, backgrounds)
        return DrawingContext::decorativeInsideTag(
            sprintf('<%s> borders/backgrounds', $bdcSemantic->tag)
        );
    }
    
    /**
     * Check if element has text-decoration style
     * 
     * Checks both:
     * - Inline style attribute: style="text-decoration: underline"
     * - Semantic tag that implies decoration: <u>, <s>, <del>, <ins>
     * 
     * @param SemanticElement $semantic Semantic element
     * @return bool True if element has text decoration
     */
    private function hasTextDecoration(SemanticElement $semantic): bool
    {
        // Check 1: Semantic tags that represent text decoration
        $decorationTags = ['u', 's', 'del', 'ins', 'strike'];
        if (in_array($semantic->tag, $decorationTags, true)) {
            return true;
        }
        
        // Check 2: Inline style attribute
        $style = $semantic->getAttribute('style', '');
        if (empty($style)) {
            return false;
        }
        
        // Parse style for text-decoration property
        // Supports: "text-decoration: underline" or "text-decoration:line-through"
        if (preg_match('/text-decoration\s*:\s*(underline|line-through|overline)/i', $style)) {
            return true;
        }
        
        return false;
    }
}
