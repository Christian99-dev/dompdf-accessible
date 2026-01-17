<?php
/**
 * ImageProcessor - Processes image rendering with PDF/UA tagging
 * 
 * Handles the complete image rendering lifecycle:
 * 1. Analyze: Determine image type (semantic vs artifact) and current state
 * 2. Execute: Open → Render → Close (atomic pattern)
 * 
 * ATOMIC DESIGN:
 * - analyze() returns ImageDecision enum based on state + isDecorative()
 * - execute() performs action WITHOUT additional logic/IF checks
 * - Images ALWAYS close themselves (unlike text containers)
 * 
 * ARCHITECTURE PATTERN (same as DrawingProcessor):
 * - Each image is self-contained unit
 * - Leaves clean state (NONE) for next content
 * - No interference with text flow MCIDs
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/ImageDecision.php';
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/TaggingState.php';
require_once __DIR__ . '/TreeLogger.php';
require_once __DIR__ . '/../../../src/SemanticTree.php';

use Dompdf\SemanticTree;

class ImageProcessor implements ContentProcessor
{
    /**
     * Process image rendering - orchestrates analyze → execute
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
        return $this->execute($decision, $frameId, $stateManager, $semanticTree, $contentRenderer, $onBDCOpened);
    }
    
    /**
     * PHASE 1: Analyze image rendering decision
     * 
     * Determines what action to take based on:
     * - Current tagging state (NONE, SEMANTIC, ARTIFACT)
     * - Image type: isDecorative() → artifact vs semantic
     * 
     * LOGIC:
     * - 3 states × 2 types = 6 possible decisions
     * - Each decision is atomic (always includes close)
     * 
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @return ImageDecision Decision enum
     */
    private function analyze(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree
    ): ImageDecision
    {
        // Get node and determine if decorative
        $node = $semanticTree->getNodeById($frameId);
        $isDecorative = $node->isDecorative();
        
        // Get current state
        $currentState = $stateManager->getState();
        
        // Decision matrix: State × Type → Decision
        switch ($currentState) {
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::NONE:
            // ────────────────────────────────────────────────────────────────
                return $isDecorative
                    ? ImageDecision::OPEN_ARTEFACT_AND_CLOSE
                    : ImageDecision::OPEN_SEMANTIC_AND_CLOSE;
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::SEMANTIC:
            // ────────────────────────────────────────────────────────────────
                return $isDecorative
                    ? ImageDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT_AND_CLOSE
                    : ImageDecision::CLOSE_SEMANTIC_AND_OPEN_SEMANTIC_AND_CLOSE;
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::ARTIFACT:
            // ────────────────────────────────────────────────────────────────
                return $isDecorative
                    ? ImageDecision::CLOSE_ARTEFACT_AND_OPEN_ARTEFACT_AND_CLOSE
                    : ImageDecision::CLOSE_ARTEFACT_AND_OPEN_SEMANTIC_AND_CLOSE;
        }

        return ImageDecision::OPEN_SEMANTIC_AND_CLOSE; // Fallback (should not reach here)
    }
    
    /**
     * PHASE 2: Execute image rendering with atomic wrapping
     * 
     * Actions based on decision:
     * - OPEN_*_AND_CLOSE: Open → Render → Close
     * - CLOSE_*_AND_OPEN_*_AND_CLOSE: Close previous → Open → Render → Close
     * 
     * CRITICAL: NO IF-CHECKS!
     * - All logic decisions made in analyze()
     * - Execute only performs actions
     * - Clean separation of concerns
     * 
     * @param ImageDecision $decision The decision from analyze()
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @param callable $contentRenderer Content rendering callback
     * @param callable|null $onBDCOpened Callback when BDC is opened: fn(string $frameId, int $mcid, string $pdfTag, int $pageNumber): void
     * @return string PDF operators
     */
    private function execute(
        ImageDecision $decision,
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        $output = '';
        $pdfTag = null;
        $mcid = null;
        
        // Get node for metadata
        $node = $semanticTree->getNodeById($frameId);
        $nodeId = $node->id ?? null;
        
        // CRITICAL: Capture state BEFORE operation for accurate logging
        $stateBeforeOperation = $stateManager->getState();
        
        // Execute based on decision (NO IF-CHECKS!)
        switch ($decision) {
            
            // ════════════════════════════════════════════════════════════════
            // FROM STATE: NONE
            // ════════════════════════════════════════════════════════════════
            
            case ImageDecision::OPEN_SEMANTIC_AND_CLOSE:
                // ATOMIC: Open semantic → Render → Close
                $pdfTag = $node->getPdfStructureTag();
                $mcid = $stateManager->getNextMCID();
                
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // Callback: Notify structure tree builder
                if ($onBDCOpened !== null) {
                    $onBDCOpened($frameId, $mcid, $pdfTag, $stateManager->getCurrentPage());
                }
                
                $output .= $contentRenderer();
                
                // ATOMIC: Close after rendering
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                break;
            
            case ImageDecision::OPEN_ARTEFACT_AND_CLOSE:
                // ATOMIC: Open artifact → Render → Close
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                
                // ATOMIC: Close after rendering
                $output .= TagOps::emc();
                $stateManager->closeArtifactBDC();
                break;
            
            // ════════════════════════════════════════════════════════════════
            // FROM STATE: SEMANTIC
            // ════════════════════════════════════════════════════════════════
            
            case ImageDecision::CLOSE_SEMANTIC_AND_OPEN_SEMANTIC_AND_CLOSE:
                // Close previous semantic
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // ATOMIC: Open semantic → Render → Close
                $pdfTag = $node->getPdfStructureTag();
                $mcid = $stateManager->getNextMCID();
                
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                if ($onBDCOpened !== null) {
                    $onBDCOpened($frameId, $mcid, $pdfTag, $stateManager->getCurrentPage());
                }
                
                $output .= $contentRenderer();
                
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                break;
            
            case ImageDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT_AND_CLOSE:
                // Close previous semantic
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // ATOMIC: Open artifact → Render → Close
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                
                $output .= TagOps::emc();
                $stateManager->closeArtifactBDC();
                break;
            
            // ════════════════════════════════════════════════════════════════
            // FROM STATE: ARTIFACT
            // ════════════════════════════════════════════════════════════════
            
            case ImageDecision::CLOSE_ARTEFACT_AND_OPEN_SEMANTIC_AND_CLOSE:
                // Close previous artifact
                $output .= TagOps::emc();
                $stateManager->closeArtifactBDC();
                
                // ATOMIC: Open semantic → Render → Close
                $pdfTag = $node->getPdfStructureTag();
                $mcid = $stateManager->getNextMCID();
                
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                if ($onBDCOpened !== null) {
                    $onBDCOpened($frameId, $mcid, $pdfTag, $stateManager->getCurrentPage());
                }
                
                $output .= $contentRenderer();
                
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                break;
            
            case ImageDecision::CLOSE_ARTEFACT_AND_OPEN_ARTEFACT_AND_CLOSE:
                // Close previous artifact
                $output .= TagOps::emc();
                $stateManager->closeArtifactBDC();
                
                // ATOMIC: Open artifact → Render → Close
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                
                $output .= TagOps::emc();
                $stateManager->closeArtifactBDC();
                break;
        }
        
        // Single tree log at the end
        TreeLogger::logImageOperation(
            $decision->name,
            $frameId,
            $nodeId,
            $pdfTag,
            $mcid,
            $stateManager->getCurrentPage(),
            $output,
            $stateBeforeOperation
        );
        
        return $output;
    }
}
