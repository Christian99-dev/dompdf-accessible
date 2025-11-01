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
use Dompdf\SimpleLogger;

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/DrawingDecision.php';
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/TreeLogger.php';
require_once __DIR__ . '/../../../src/SimpleLogger.php';

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
        // PHASE 1: Analyze - What should we do? (also renders content to detect phantoms)
        [$decision, $renderedContent] = $this->analyze($frameId, $stateManager, $semanticTree, $contentRenderer);
        
        // PHASE 2: Execute - Do it!
        return $this->execute($frameId, $decision, $renderedContent, $stateManager, $semanticTree, $onBDCOpened);
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
     * @param callable $contentRenderer Content rendering callback
     * @return array [DrawingDecision, string] Tuple of decision and rendered content
     */
    public function analyze(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer
    ): array {
        // Render content
        $renderedContent = $contentRenderer();
        
        // Make decision based on current state
        $currentState = $stateManager->getState();
        
        // Semantic BDC open? → Need interruption
        if ($currentState === TaggingState::SEMANTIC) {
            // CRITICAL: Check if drawing is for SAME frame!
            $activeFrameId = $stateManager->getActiveSemanticFrameId();
            
            if ($frameId === $activeFrameId) {
                // SAME frame → Reopen with SAME MCID (text interrupted by drawing)
                return [DrawingDecision::CLOSE_BDC_ARTIFACT_REOPEN_SAME, $renderedContent];
            } else {
                // DIFFERENT frame → Just close, draw as artifact, DON'T reopen!
                // Example: H1 is active, H2 background draws → H2 bg should be artifact
                return [DrawingDecision::CLOSE_BDC_ARTIFACT, $renderedContent];
            }
        }
        
        // Artifact BDC open? → Just draw
        if ($currentState === TaggingState::ARTIFACT) {
            return [DrawingDecision::CONTINUE, $renderedContent];
        }
        
        // Nothing open → Wrap as artifact
        return [DrawingDecision::ARTIFACT, $renderedContent];
    }
    
    /**
     * PHASE 2: Execute drawing with proper wrapping
     * 
     * Actions based on decision:
     * - INTERRUPT: Close semantic → Open artifact → Draw → Close artifact → Re-open semantic (same MCID!)
     * - CONTINUE: Just draw (already in artifact)
     * - ARTIFACT: Open artifact → Draw → Close artifact
     * 
     * @param string|null $frameId Current frame ID
     * @param DrawingDecision $decision The decision from analyze()
     * @param string $renderedContent Already rendered content from analyze()
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @param callable|null $onBDCOpened Callback when BDC is (re-)opened: fn(string $frameId, int $mcid, string $pdfTag, int $pageNumber): void
     * @return string PDF operators
     */
    public function execute(
        ?string $frameId,
        DrawingDecision $decision,
        string $renderedContent,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        ?callable $onBDCOpened = null
    ): string {
        $output = '';
        $pdfTag = null;
        $mcid = null;
        $nodeId = $frameId ? $semanticTree->getNodeById($frameId)->id : null;  // For logging
        
        // CRITICAL: Capture state BEFORE operation for accurate logging
        $stateBeforeOperation = $stateManager->getState();
        
        // Execute based on decision
        switch ($decision) {
            case DrawingDecision::CLOSE_BDC_ARTIFACT_REOPEN_SAME:
                // Save active state (Single Source of Truth!)
                $savedFrameId = $stateManager->getActiveSemanticFrameId();
                $savedMcid = $stateManager->getActiveSemanticMCID();
                $node = $semanticTree->getNodeById($savedFrameId);
                $savedPdfTag = $node ? $node->getPdfStructureTag() : 'P';
                
                // 1. Close semantic BDC
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // 2. Open artifact BDC
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                // 3. Draw
                $output .= $renderedContent;
                
                // 4. Close artifact BDC
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                
                // 5. Reopen with SAME MCID (text interrupted by drawing)
                $output .= TagOps::bdcOpen($savedPdfTag, $savedMcid);
                $stateManager->openSemanticBDC($savedFrameId, $savedMcid);
                
                // For logging
                $frameId = $savedFrameId;
                $mcid = $savedMcid;
                $pdfTag = $savedPdfTag;
                break;
                
            case DrawingDecision::CLOSE_BDC_ARTIFACT:
                // 1. Close semantic BDC
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // 2. Open artifact BDC
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                // 3. Draw (stay in artifact, no reopen!)
                $output .= $renderedContent;
                
                // Note: Artifact stays open! Next text rendering will close it.
                // No MCID, no callback - this is purely decorative content.
                break;
                
            case DrawingDecision::CONTINUE:
                // Just draw (already in artifact) - content already rendered above
                $output .= $renderedContent;
                break;
                
            case DrawingDecision::ARTIFACT:
                // Wrap as artifact
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $renderedContent;
                
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;
        }
        
        // Single tree log at the end
        TreeLogger::logDrawingOperation(
            $decision->name,
            $frameId,
            $nodeId,
            $pdfTag,
            $mcid,
            $stateManager->getCurrentPage(),
            $output,
            $stateBeforeOperation  // State BEFORE operation
        );
        
        return $output;
    }
}
