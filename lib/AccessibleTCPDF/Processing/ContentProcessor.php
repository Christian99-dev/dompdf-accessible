<?php
/**
 * ContentProcessor - Interface for PDF/UA content processing
 * 
 * Processors handle the complete lifecycle of content rendering with PDF/UA tagging:
 * 1. analyze()  - Determine what action to take (returns enum)
 * 2. execute()  - Execute the action (gets data from StateManager)
 * 
 * MINIMALIST DESIGN:
 * - Decisions are just enums (no data)
 * - All data comes from StateManager/SemanticTree
 * - Single Source of Truth principle
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
     * @return string PDF operators (BDC/EMC/Artifact + content)
     */
    public function process(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer
    ): string;
}
