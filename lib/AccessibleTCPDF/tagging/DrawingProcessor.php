<?php
/**
 * DrawingProcessor - Processes drawing operations with PDF/UA tagging
 * 
 * Handles the complete drawing lifecycle:
 * 1. Analyze: Determine if we need to interrupt semantic BDC, continue, or wrap as artifact
 * 2. Execute: Output appropriate PDF operators (with re-open logic for interruptions)
 * 
 * MINIMALIST DESIGN:
 * - analyze() returns just DrawingDecision enum
 * - execute() gets all data from StateManager/SemanticTree
 * - Handles semantic BDC interruption with re-open (same MCID!)
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SemanticTree;

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/DrawingDecision.php';
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/TreeLogger.php';

class DrawingProcessor implements ContentProcessor
{
    /**
     * Process drawing operation
     * 
     * Simple orchestration: analyze → execute
     */
    public function process(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        // PHASE 1: Analyze - What should we do?
        $decision = $this->analyze($frameId, $stateManager, $semanticTree);
        
        // PHASE 2: Execute - Do it!
        return $this->execute($decision, $stateManager, $semanticTree, $contentRenderer, $onBDCOpened);
    }
    
    /**
     * PHASE 1: Analyze drawing decision
     * 
     * Determines what action to take based on current state:
     * - Semantic BDC open? → Need interruption (close → artifact → re-open)
     * - Artifact BDC open? → Just draw (continue)
     * - Nothing open? → Wrap as artifact
     * 
     * @param string|null $frameId Current frame ID (not used for drawings, but part of interface)
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @return DrawingDecision Decision type (enum)
     */
    public function analyze(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree
    ): DrawingDecision {
        // Semantic BDC open? → Need interruption
        if ($stateManager->getState() === TaggingState::SEMANTIC) {
            return DrawingDecision::INTERRUPT;
        }
        
        // Artifact BDC open? → Just draw
        if ($stateManager->getState() === TaggingState::ARTIFACT) {
            return DrawingDecision::CONTINUE;
        }
        
        // Nothing open → Wrap as artifact
        return DrawingDecision::ARTIFACT;
    }
    
    /**
     * PHASE 2: Execute drawing with proper wrapping
     * 
     * Actions based on decision:
     * - INTERRUPT: Close semantic → Open artifact → Draw → Close artifact → Re-open semantic (same MCID!)
     * - CONTINUE: Just draw (already in artifact)
     * - ARTIFACT: Open artifact → Draw → Close artifact
     * 
     * @param DrawingDecision $decision The decision from analyze()
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @param callable $contentRenderer Content rendering callback
     * @param callable|null $onBDCOpened Callback when BDC is (re-)opened: fn(string $frameId, int $mcid, string $pdfTag, int $pageNumber): void
     * @return string PDF operators
     */
    public function execute(
        DrawingDecision $decision,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        $output = '';
        $pdfTag = null;
        $mcid = null;
        $frameId = null;
        
        switch ($decision) {
            case DrawingDecision::INTERRUPT:
                // Save state from StateManager (Single Source of Truth!)
                $savedFrameId = $stateManager->getActiveSemanticFrameId();
                $savedMcid = $stateManager->getActiveSemanticMCID();
                
                // Get PDF tag for re-open
                $node = $semanticTree->getNodeById($savedFrameId);
                $savedPdfTag = $node ? $node->getPdfStructureTag() : 'P';
                
                // 1. Close semantic BDC
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // 2. Open artifact BDC
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                // 3. Draw
                $output .= $contentRenderer();
                
                // 4. Close artifact BDC
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                
                // 5. Re-open semantic BDC with SAME MCID (critical!)
                $output .= TagOps::bdcOpen($savedPdfTag, $savedMcid);
                $stateManager->openSemanticBDC($savedFrameId, $savedMcid);
                
                // CALLBACK: Notify that BDC was re-opened (important for StructTree!)
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($savedFrameId, $savedMcid, $savedPdfTag, $pageNumber);
                }
                
                // Capture for tree log
                $frameId = $savedFrameId;
                $mcid = $savedMcid;
                $pdfTag = $savedPdfTag;
                break;
                
            case DrawingDecision::CONTINUE:
                // Just draw (already in artifact)
                $output .= $contentRenderer();
                break;
                
            case DrawingDecision::ARTIFACT:
                // Wrap as artifact
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;
        }
        
        // Single tree log at the end
        TreeLogger::logDrawingOperation(
            $decision->name,
            $frameId,
            $pdfTag,
            $mcid,
            $stateManager->getCurrentPage(),
            $output
        );
        
        return $output;
    }
}
