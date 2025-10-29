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
 * Meaning: Dynamically generated content WITHOUT frame context, Renderer never calls setCurrentFrameId()
 * 
 * Trigger:
 * -> TCPDF auto-generated content (page headers/footers, page numbers)
 * -> Direct TCPDF API calls bypassing dompdf renderer
 * -> CSS backgrounds/borders rendered without frame context
 * -> Decorative graphics added by rendering code
 * -> Any content rendered without prior setCurrentFrameId() call
 * 
 * ────────────────────────────────────────────────────────────────────────────
 * 
 * CASE 2: frameId !== null BUT getNodeById(frameId) === null
 * ───────────────────────────────────────────────────────────
 * Meaning: Frame object exists, but was created DURING reflow (not in tree)
 * 
 * Trigger:
 * -> Text splits
 * -> Inline Element Splits
 * -> Font Substitution Splits
 * -> Table Cell Splits (Page Break)
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
