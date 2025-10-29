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
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/TreeLogger.php';

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
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        // PHASE 1: Analyze - What should we do?
        $decision = $this->analyze($frameId, $stateManager, $semanticTree);
        
        // PHASE 2: Execute - Do it!
        return $this->execute($decision, $frameId, $stateManager, $semanticTree, $contentRenderer, $onBDCOpened);
    }
    
    /**
     * PHASE 1: Analyze text rendering decision
     * 
     * Determines what action to take based on:
     * - Frame ID presence
     * - Semantic node existence
     * - Current state (is BDC open?)
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

        // EDGE CASE 1: (Doc in ContentProcessor.php)
        // Meaning: Dynamically generated Frame WITHOUT setting a frame context
        if ($frameId === null) {
            return TextDecision::ARTIFACT;
        }
        
        // Get semantic node
        $node = $semanticTree->getNodeById($frameId);

        // EDGE CASE 2: (Doc in ContentProcessor.php)
        // Meaning: Dynamically generated content WITH a Frame object
        if ($node === null) {
            return TextDecision::CONTINUE;
        }
        
        /* --  Node found **/

        // Here we can ask anything now
        // Are we inside an artefact or a tag ?
        // Are we we an artefact/inlinetag or tag itself
        // node->isArtifact
        // node->isInlineTag
        // stateManger == TaggingState::Semantic or none or whatevery 
        // everything is possible ~ ~ ~ ~ 

        // New Frame but theres still an open BDC
        if($stateManager->getState() === TaggingState::SEMANTIC) {
            return TextDecision::CLOSE_AND_OPEN_NEW;  // BDC offen → schließen + öffnen
        }

        // New Frame and no BDC open, Problem the first frame in document
        return TextDecision::OPEN_NEW;
    }
    
    /**
     * PHASE 2: Execute text rendering with tagging
     * 
     * Actions based on decision:
     * - OPEN_NEW: Open new BDC → Render → Keep open
     * - CLOSE_AND_OPEN_NEW: Close old BDC → Open new BDC → Render → Keep open
     * - CONTINUE: Just render (BDC already open)
     * - ARTIFACT: Wrap in /Artifact BMC ... EMC
     * 
     * @param TextDecision $decision The decision from analyze()
     * @param string|null $frameId Current frame ID
     * @param TaggingStateManager $stateManager State manager
     * @param SemanticTree $semanticTree Semantic tree
     * @param callable $contentRenderer Content rendering callback
     * @param callable|null $onBDCOpened Callback when BDC is opened: fn(string $frameId, int $mcid, string $pdfTag, int $pageNumber): void
     * @return string PDF operators
     */
    public function execute(
        TextDecision $decision,
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $contentRenderer,
        ?callable $onBDCOpened = null
    ): string {
        $output = '';
        $pdfTag = null;
        $mcid = null;
        $nodeId = null;  // For logging: actual semantic node ID
        
        switch ($decision) {
            case TextDecision::OPEN_NEW:
                // Get node (we know it exists from analyze)
                $node = $semanticTree->getNodeById($frameId);
                $pdfTag = $node->getPdfStructureTag();
                $nodeId = $node->id;  // Store for logging
                
                // Open new semantic BDC (no closing needed)
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // CALLBACK: Notify that BDC was opened
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($frameId, $mcid, $pdfTag, $pageNumber);
                }
                
                // Render content
                $output .= $contentRenderer();
                break;
            
            case TextDecision::CLOSE_AND_OPEN_NEW:
                // Get node (we know it exists from analyze)
                $node = $semanticTree->getNodeById($frameId);
                $pdfTag = $node->getPdfStructureTag();
                $nodeId = $node->id;  // Store for logging
                
                // Close existing BDC first
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                // Open new semantic BDC
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // CALLBACK: Notify that BDC was opened
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($frameId, $mcid, $pdfTag, $pageNumber);
                }
                
                // Render content
                $output .= $contentRenderer();
                break;
                
            case TextDecision::CONTINUE:
                // Just render (BDC already open)
                $output .= $contentRenderer();
                $mcid = $stateManager->getActiveSemanticMCID();
                $nodeId = $frameId;  // Current node
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
        
        // Single tree log at the end
        TreeLogger::logTextOperation(
            $decision->name,
            $frameId,
            $pdfTag,
            $mcid,
            $stateManager->getCurrentPage(),
            $output,
            ['nodeId' => $nodeId]  // Add actual node ID to context
        );

        return $output;
    }
}
