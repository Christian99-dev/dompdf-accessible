<?php
/**
 * ContentProcessor - Interface for PDF/UA content processing
 * 
 * Processors handle the complete lifecycle of content rendering with PDF/UA tagging:
 * 1. analyze()  - Determine what action to take (returns enum)
 * 2. execute()  - Execute the action (gets data from StateManager)
 * 
 * ============================================================================
 * EDGE CASES: Dynamic Frame Generation vs SemanticTree
 * ============================================================================
 * 
 * The SemanticTree is filled BEFORE reflow (via registerAllSemanticElements()),
 * but frames can be created DURING reflow. This creates two edge cases where
 * frameId (FrameTree) doesn't match a node in SemanticTree:
 * 
 * CASE 1: frameId === null
 * ────────────────────────
 * Meaning: Dynamically generated content WITHOUT frame context
 * 
 * Trigger:
 * - Renderer never calls setCurrentFrameId()
 * - Content is not linked to any DOM node
 * - Content is purely synthetic/visual
 * 
 * Examples:
 * 1. List bullets ("•", "1.", "a)") – Renderer/ListBullet.php line 162-202
 *    → canvas->circle() / canvas->text() without setCurrentFrameId()
 *    → HTML: <li>Item</li> renders bullet, but bullet has frameId=null
 * 
 * 2. Image alt-text fallback when image fails to load
 *    → Fallback text rendered directly without own frame
 *    → HTML: <img src="missing.png" alt="Text"> shows "Text" with frameId=null
 * 
 * 3. Other synthetic renderer output (rare)
 * 
 * Semantic Decision: ARTIFACT
 * - NOT part of original DOM structure
 * - Should NOT appear in PDF Structure Tree
 * - Screen readers IGNORE this content
 * - PDF/UA: /Artifact BDC ... EMC
 * 
 * Why: These are visual presentation elements, not document content.
 * 
 * ────────────────────────────────────────────────────────────────────────────
 * 
 * CASE 2: frameId !== null BUT getNodeById(frameId) === null
 * ───────────────────────────────────────────────────────────
 * Meaning: Frame object exists, but was created DURING reflow (not in tree)
 * 
 * Trigger:
 * - Frame created AFTER registerAllSemanticElements() finished
 * - Frame has ID and DOM node, but not registered in SemanticTree
 * - Frame created through split() operations during layout
 * 
 * Examples:
 * 1. Text split by word wrapping (MOST COMMON)
 *    → FrameReflower/Text.php line 355: split_text($split)
 *    → FrameDecorator/Text.php line 167: DOM splitText() + copy frame
 *    → HTML: <p>Long text...</p> creates Frame 5 (in tree), Frame 6-16 (NOT in tree)
 * 
 * 2. Inline element split across line breaks
 *    → FrameReflower/Text.php line 343: $p->split($child)
 *    → HTML: <span>Long inline...</span> splits into Frame 5 (in tree) + Frame 6 (NOT in tree)
 * 
 * 3. Font substitution splits
 *    → FrameDecorator/Text.php line 236: split for different fonts
 *    → HTML: <p>English 中文 العربية</p> splits into 3 frames for different scripts
 * 
 * 4. Table cell splits at page breaks
 *    → FrameDecorator/AbstractFrameDecorator.php line 674: split() for pagination
 * 
 * 5. Anonymous box wrappers (rare, usually happens before reflow)
 * 
 * Semantic Decision: CONTINUE
 * - Frame created DURING parent's active rendering
 * - Parent's BDC already open (guaranteed by render timing)
 * - All split parts belong to SAME semantic element
 * - NO parent lookup needed (timing ensures correct context)
 * 
 * Why: The split frame renders WHILE its parent is still active. The StateManager
 * already has the correct BDC open, so we just continue in that context.
 * 
 * ────────────────────────────────────────────────────────────────────────────
 * 
 * NOTE: CSS Generated Content (::before/::after) is NOT an edge case!
 * - Generated content creates real DOM nodes BEFORE reflow
 * - These nodes ARE registered in SemanticTree
 * - They have the 'dompdf_generated' attribute
 * - See: FrameReflower/AbstractFrameReflower.php line 592
 * 
 * ============================================================================
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SemanticTree;

interface ContentProcessor
{
    /**
     * Main entry point - orchestrates analyze + execute
     * 
     * @param string|null $frameId Current frame ID being rendered
     * @param TaggingStateManager $stateManager State manager instance
     * @param SemanticTree $semanticTree Semantic tree for node lookup
     * @param callable $contentRenderer Callback that renders the actual content
     * @param callable|null $onBDCOpened Callback when semantic BDC is opened: fn(string $frameId, int $mcid, string $pdfTag, int $pageNumber): void
     * @return string PDF operators (BDC/EMC/Artifact + content)
     */
    public function process(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string;
}
