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
        callable $contentRenderer
    ): string {
        // PHASE 1: Analyze - What should we do?
        $decision = $this->analyze($frameId, $stateManager, $semanticTree);
        
        // PHASE 2: Execute - Do it!
        return $this->execute($decision, $stateManager, $semanticTree, $contentRenderer);
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
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
            sprintf("Analyzing: hasSemanticState=%s, hasArtifactState=%s", 
                $stateManager->hasSemanticState() ? 'true' : 'false',
                $stateManager->hasArtifactState() ? 'true' : 'false'));
        
        // Semantic BDC open? → Need interruption
        if ($stateManager->hasSemanticState()) {
            SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                sprintf("Decision: INTERRUPT (semantic open, frameId=%s)", 
                    $stateManager->getActiveSemanticFrameId()));
            return DrawingDecision::INTERRUPT;
        }
        
        // Artifact BDC open? → Just draw
        if ($stateManager->hasArtifactState()) {
            SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, "Decision: CONTINUE (artifact already open)");
            return DrawingDecision::CONTINUE;
        }
        
        // Nothing open → Wrap as artifact
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, "Decision: ARTIFACT (nothing open)");
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
     * @return string PDF operators
     */
    public function execute(
        DrawingDecision $decision,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer
    ): string {
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
            sprintf("Executing: decision=%s", $decision->name));
        
        $output = '';
        
        switch ($decision) {
            case DrawingDecision::INTERRUPT:
                // Save state from StateManager (Single Source of Truth!)
                $savedFrameId = $stateManager->getActiveSemanticFrameId();
                $savedMcid = $stateManager->getActiveSemanticMCID();
                
                // Get PDF tag for re-open
                $node = $semanticTree->getNodeById($savedFrameId);
                $savedPdfTag = $node ? $node->getPdfStructureTag() : 'P';
                
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    sprintf("Interrupting Semantic: frameId=%s, mcid=%d, tag=%s", 
                        $savedFrameId, $savedMcid ?? -1, $savedPdfTag));
                
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
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    sprintf("Re-opening Semantic with SAME MCID: frameId=%s, mcid=%d, tag=%s", 
                        $savedFrameId, $savedMcid ?? -1, $savedPdfTag));
                $output .= TagOps::bdcOpen($savedPdfTag, $savedMcid);
                $stateManager->openSemanticBDC($savedFrameId, $savedMcid);
                break;
                
            case DrawingDecision::CONTINUE:
                // Just draw (already in artifact)
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    "Continuing in existing Artifact");
                $output .= $contentRenderer();
                break;
                
            case DrawingDecision::ARTIFACT:
                // Wrap as artifact
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    "Wrapping as new Artifact");
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;
        }
        
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
            sprintf("Execution complete, output length=%d", strlen($output)));
        
        return $output;
    }
}
