<?php
/**
 * TextProcessor - Processes text rendering with PDF/UA tagging
 * 
 * Handles the complete text rendering lifecycle:
 * 1. Analyze: Determine if we need to open new BDC, continue, or wrap as artifact
 * 2. Execute: Output appropriate PDF operators
 * 
 * MINIMALIST DESIGN:
 * - analyze() returns just TextDecision enum
 * - execute() gets all data from StateManager/SemanticTree
 * - No data duplication
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SemanticTree;
use Dompdf\SimpleLogger;

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/TextDecision.php';
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/../../../src/SimpleLogger.php';
require_once __DIR__ . '/ContentProcessor.php';

class TextProcessor implements ContentProcessor
{
    /**
     * Process text rendering
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
        return $this->execute($decision, $frameId, $stateManager, $semanticTree, $contentRenderer);
    }
    
    /**
     * PHASE 1: Analyze text rendering decision
     * 
     * Determines what action to take based on:
     * - Frame ID presence
     * - Semantic node existence
     * - Current state (is same frame active?)
     * 
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @return TextDecision Decision type (enum)
     */
    public function analyze(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree
    ): TextDecision {
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
            sprintf("Analyzing: frameId=%s, hasSemanticState=%s, activeFrameId=%s", 
                $frameId ?? 'null',
                $stateManager->hasSemanticState() ? 'true' : 'false',
                $stateManager->getActiveSemanticFrameId() ?? 'null'));
        
        // No frame ID → Artifact
        if ($frameId === null) {
            SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, "Decision: ARTIFACT (no frameId)");
            return TextDecision::ARTIFACT;
        }
        
        // Get semantic node
        $node = $semanticTree->getNodeById($frameId);
        
        // Node not found → Artifact
        if ($node === null) {
            SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                sprintf("Decision: ARTIFACT (node not found for frameId=%s)", $frameId));
            return TextDecision::ARTIFACT;
        }
        
        // Same frame as active? → Continue (Transparent)
        $activeFrameId = $stateManager->getActiveSemanticFrameId();
        if ($activeFrameId === $frameId) {
            SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, "Decision: CONTINUE (same frameId)");
            return TextDecision::CONTINUE;
        }
        
        // Different frame → Open new semantic BDC
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
            sprintf("Decision: OPEN_NEW (different frameId, was=%s, now=%s)", 
                $activeFrameId ?? 'null', $frameId));
        return TextDecision::OPEN_NEW;
    }
    
    /**
     * PHASE 2: Execute text rendering with tagging
     * 
     * Actions based on decision:
     * - OPEN_NEW: Close old BDC (if any) → Open new BDC → Render → Keep open
     * - CONTINUE: Just render (BDC already open)
     * - ARTIFACT: Wrap in /Artifact BMC ... EMC
     * 
     * @param TextDecision $decision The decision from analyze()
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @param callable $contentRenderer Content rendering callback
     * @return string PDF operators
     */
    public function execute(
        TextDecision $decision,
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer
    ): string {
        SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
            sprintf("Executing: decision=%s, frameId=%s", $decision->name, $frameId ?? 'null'));
        
        $output = '';
        
        switch ($decision) {
            case TextDecision::OPEN_NEW:
                // Get node (we know it exists from analyze)
                $node = $semanticTree->getNodeById($frameId);
                $pdfTag = $node->getPdfStructureTag();
                
                // Close existing BDC if open
                if ($stateManager->hasSemanticState()) {
                    SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                        "Closing previous Semantic BDC before opening new one");
                    $output .= TagOps::emc();
                    $stateManager->closeSemanticBDC();
                }
                
                // Open new semantic BDC
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId);
                
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    sprintf("Opened new Semantic BDC: tag=%s, mcid=%d, frameId=%s", 
                        $pdfTag, $mcid, $frameId));
                
                // Render content
                $output .= $contentRenderer();
                
                // Keep BDC open for next call (important!)
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    "Content rendered, keeping BDC open");
                break;
                
            case TextDecision::CONTINUE:
                // Just render (BDC already open)
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    "Continuing in existing BDC");
                $output .= $contentRenderer();
                break;
                
            case TextDecision::ARTIFACT:
                // Wrap as artifact
                SimpleLogger::log("pdf_backend_tagging_logs", __METHOD__, 
                    "Wrapping as Artifact");
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
