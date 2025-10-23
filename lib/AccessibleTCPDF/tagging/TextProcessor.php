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

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/TextDecision.php';
require_once __DIR__ . '/../TagOps.php';

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
        // No frame ID → Artifact
        if ($frameId === null) {
            return TextDecision::ARTIFACT;
        }
        
        // Get semantic node
        $node = $semanticTree->getNodeById($frameId);
        
        // Node not found → Artifact
        if ($node === null) {
            return TextDecision::ARTIFACT;
        }
        
        // Same frame as active? → Continue (Transparent)
        $activeFrameId = $stateManager->getActiveSemanticFrameId();
        if ($activeFrameId === $frameId) {
            return TextDecision::CONTINUE;
        }
        
        // Different frame → Open new semantic BDC
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
        $output = '';
        
        switch ($decision) {
            case TextDecision::OPEN_NEW:
                // Get node (we know it exists from analyze)
                $node = $semanticTree->getNodeById($frameId);
                $pdfTag = $node->getPdfStructureTag();
                
                // Close existing BDC if open
                if ($stateManager->hasSemanticState()) {
                    $output .= TagOps::emc();
                    $stateManager->closeSemanticBDC();
                }
                
                // Open new semantic BDC
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId);
                
                // Render content
                $output .= $contentRenderer();
                
                // Keep BDC open for next call (important!)
                break;
                
            case TextDecision::CONTINUE:
                // Just render (BDC already open)
                $output .= $contentRenderer();
                break;
                
            case TextDecision::ARTIFACT:
                // Wrap as artifact
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                break;
        }
        
        return $output;
    }
}
