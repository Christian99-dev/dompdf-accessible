<?php

require_once __DIR__ . '/ContentProcessor.php';
require_once __DIR__ . '/ImageDecision.php';
require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingStateManager.php';
require_once __DIR__ . '/TaggingState.php';
require_once __DIR__ . '/../../../src/SemanticTree.php';

use Dompdf\FrameDecorator\Image;
use Dompdf\SemanticTree;

/**
 * ImageProcessor - Simplified version for testing
 * 
 * Simply wraps ALL images in /Artifact tags
 */
class ImageProcessor implements ContentProcessor
{
    /**
     * Process image rendering - orchestrates analyze → execute
     */
    public function process(
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree,
        callable $renderCallback,
        ?callable $onBDCOpenedCallback = null
    ): string {
        // PHASE 1: Analyze (simplified: always returns ARTIFACT)
        $decision = $this->analyze($frameId, $stateManager, $semanticTree);

        
        // PHASE 2: Execute
        return $this->execute($decision, $frameId, $stateManager, $semanticTree, $renderCallback, $onBDCOpenedCallback);
    }
    
    /**
     * Analyze - Simplified: Always wrap in /Artifact for testing
     */
    private function analyze(        
        ?string $frameId,
        TaggingStateManager $stateManager,
        SemanticTree $semanticTree
    ): ImageDecision
    {
        $node = $semanticTree->getNodeById($frameId);
        
        $isArtefact = $node->isDecorative();

        if(TaggingState::NONE == $stateManager->getState()){
            if($isArtefact){
                // Not tagged yet, but should be an artefact - close and open artefact
                return ImageDecision::OPEN_ARTEFACT;
            } else {
                // Not tagged yet, normal image - open artefact
                return ImageDecision::OPEN_SEMANTIC;
            }
        }

        if(TaggingState::ARTIFACT == $stateManager->getState()){
            if($isArtefact) {
                return ImageDecision::CONTINUE;
            } else {
                return ImageDecision::CLOSE_AND_OPEN_SEMANTIC;
            }
        }

        if(TaggingState::SEMANTIC == $stateManager->getState()){
            if($isArtefact){
                return ImageDecision::CLOSE_AND_OPEN_ARTEFACT;
            } else {
                return ImageDecision::CLOSE_AND_OPEN_SEMANTIC;
            }
        }

        return ImageDecision::CONTINUE;
    }
    
    /**
     * Execute - Wrap image in /Artifact
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

        // declare here for tagging info
        $mcid = null;
        
        // Get current node
        $node = $semanticTree->getNodeById($frameId);
        $nodeId = $node->id ?? null;  // For logging: actual semantic node ID
        
        $pdfTag = $node->getPdfStructureTag() ?? null; // For logging: PDF tag associated with node
        
        // CRITICAL: Capture state BEFORE operation for accurate logging
        $stateBeforeOperation = $stateManager->getState();

        // Get current node
        $node = $semanticTree->getNodeById($frameId);
        $nodeId = $node->id ?? null;  
        
        switch ($decision) {
            case ImageDecision::CONTINUE:
                // Already in artifact BDC - just render
                $output .= $contentRenderer();
                break;

            case ImageDecision::CLOSE_AND_OPEN_ARTEFACT:
                // Open artifact BDC 
                $output .= TagOps::emc();
                $stateManager->closeArtifactBDC();

            case ImageDecision::OPEN_ARTEFACT:
                
                // Open artifact BDC 
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
            
                $output .= $contentRenderer();
                break;

            case ImageDecision::CLOSE_AND_OPEN_SEMANTIC:
                // Close artifact BDC
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();  

            case ImageDecision::OPEN_SEMANTIC:
                // Open semantic BDC
                $pdfTag = $node->getPdfStructureTag() ?? 'P';
                $mcid = $stateManager->getNextMCID();
                
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // Callback
                if ($onBDCOpened !== null) {
                    $onBDCOpened($frameId, $mcid, $pdfTag, $stateManager->getCurrentPage());
                }
                
                $output .= $contentRenderer();
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
            $stateBeforeOperation  // State BEFORE operation
        );
        return $output;
    }
}
