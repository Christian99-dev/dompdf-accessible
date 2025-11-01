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

        // ========================================================================
        // STEP 1: EDGE CASES (frameId/node validation)
        // ========================================================================

        // EDGE CASE 1: frameId === null
        // Dynamically generated content WITHOUT frame context
        // this happens if you manually draw text with the canvas
        if ($frameId === null) {
            return TextDecision::ARTIFACT;
        }
        
        // Get semantic node
        $node = $semanticTree->getNodeById($frameId);
        
        // EDGE CASE 2: node === null
        // Dynamically generated content WITH frame (text splits, font substitution, etc.)
        // CRITICAL: CONTINUE only works if we're already inside a BDC tag!
        // If we're in NONE state, we MUST open a tag first.
        if ($node === null) {
            $currentState = $stateManager->getState();
            
            // If no BDC is open, we need to open one as ARTIFACT (no semantic info available)
            if ($currentState === TaggingState::NONE) {
                return TextDecision::OPEN_ARTEFACT;
            }
            
            // If BDC is already open (SEMANTIC or ARTIFACT), continue in that context
            return TextDecision::CONTINUE;
        }

        // ========================================================================
        // STEP 2: Check current state and node properties
        // ========================================================================
        
        // CRITICAL: Non-semantic wrappers (html, body) should be transparent (no tagging)
        if ($node->isNonSemanticWrapper()) {
            return TextDecision::CONTINUE;
        }
        
        $currentState = $stateManager->getState();
        $hasDecorativeParent = $node->hasDecorativeParent();
        $isTextNode = $node->isTextNode();
        $isTransparentInlineTag = $node->isTransparentInlineTag();
        $isAfterLineBreakNode = $semanticTree->isAfterLinebreakNode($node->id);
        
        // ========================================================================
        // STEP 3: STATE-BASED DECISION TREE
        // ========================================================================
        
        switch ($currentState) {
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::NONE:
            // ────────────────────────────────────────────────────────────────
                if ($hasDecorativeParent) {
                    return TextDecision::OPEN_ARTEFACT;
                }

                if ($isAfterLineBreakNode) { 
                    // Line break should not open a tag - it's invisible!
                    // If we're in NONE state, open parent's tag first
                    return TextDecision::OPEN_WITH_PARENT_INFO;
                }

                if ($isTextNode || $isTransparentInlineTag) {
                    // Text nodes and transparent inline tags use parent's tag
                    return TextDecision::OPEN_WITH_PARENT_INFO;
                }
                
                // Normal semantic element
                return TextDecision::OPEN_NEW;
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::SEMANTIC:
            // ────────────────────────────────────────────────────────────────
                if ($hasDecorativeParent) {
                    return TextDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT;
                }

                if ($isAfterLineBreakNode) { 
                    return TextDecision::CONTINUE;
                }
                
                if ($isTextNode || $isTransparentInlineTag) {
                    // Text nodes and transparent inline tags use parent's tag
                    return TextDecision::CLOSE_AND_OPEN_WITH_PARENT_INFO;
                }
                
                // Normal semantic element
                return TextDecision::CLOSE_AND_OPEN_NEW;
            
            // ────────────────────────────────────────────────────────────────
            case TaggingState::ARTIFACT:
            // ────────────────────────────────────────────────────────────────
                if ($hasDecorativeParent) {
                    // Stay in artifact
                    return TextDecision::CONTINUE;
                }
                
                if ($isAfterLineBreakNode) {
                    // Line break inside artifact tag: just continue!
                    return TextDecision::CONTINUE;
                }

                if ($isTextNode || $isTransparentInlineTag) {
                    // Text nodes and transparent inline tags use parent's tag
                    return TextDecision::CLOSE_ARTEFACT_AND_OPEN_WITH_PARENT_INFO;
                }
                
                // Normal semantic element
                return TextDecision::CLOSE_ARTEFACT_AND_OPEN_NEW;
        }
        
        // Fallback (should never reach here)
        return TextDecision::CONTINUE;
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

        // declare here for tagging info
        $pdfTag = null;
        $mcid = null;

        // Get current node
        $node = $semanticTree->getNodeById($frameId);
        $nodeId = $node->id ?? null;  // For logging: actual semantic node ID

        // CRITICAL: Capture state BEFORE operation for accurate logging
        $stateBeforeOperation = $stateManager->getState();
        
        switch ($decision) {

            case TextDecision::CLOSE_AND_OPEN_NEW:                
                // Close existing BDC first
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
            case TextDecision::OPEN_NEW:
                // Get node (we know it exists from analyze)
                $pdfTag = $node->getPdfStructureTag();
                
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
            
            case TextDecision::CLOSE_AND_OPEN_WITH_PARENT_INFO:
                // Close existing BDC first
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();

            case TextDecision::OPEN_WITH_PARENT_INFO:
                // Get node (we know it exists from analyze)
                // For transparent inline tags and #text nodes, skip to nearest block parent
                $parentNode = $node->isTextNode() || $node->isTransparentInlineTag()
                    ? $node->getNearestBlockParent()
                    : $node->getParent();
                
                // Skip non-semantic wrappers (html, body) when finding parent for tagging
                while ($parentNode !== null && $parentNode->isNonSemanticWrapper()) {
                    $parentNode = $parentNode->getParent();
                }
                
                // Fallback: If no semantic parent exists, wrap as artifact
                if ($parentNode === null) {
                    $output .= TagOps::artifactOpen();
                    $stateManager->openArtifactBDC();
                    $output .= $contentRenderer();
                    $output .= TagOps::artifactClose();
                    $stateManager->closeArtifactBDC();
                    break;
                }
                
                $pdfTag = $parentNode->getPdfStructureTag();

                // Open new semantic BDC (no closing needed)
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                // CALLBACK: Notify that BDC was opened
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($parentNode->id, $mcid, $pdfTag, $pageNumber);
                }
                
                // Render content
                $output .= $contentRenderer();
                break;
                
            case TextDecision::CONTINUE:
                // Just render (BDC already open)
                $output .= $contentRenderer();
                $mcid = $stateManager->getActiveSemanticMCID();
                break;
                
            case TextDecision::OPEN_ARTEFACT:
                // Open artifact BDC (when parent is decorative)
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                // Note: Don't close here - stays open for siblings
                break;
                
            case TextDecision::CLOSE_SEMANTIC_AND_OPEN_ARTEFACT:
                // Close semantic BDC and open artifact
                $output .= TagOps::emc();
                $stateManager->closeSemanticBDC();
                
                $output .= TagOps::artifactOpen();
                $stateManager->openArtifactBDC();
                
                $output .= $contentRenderer();
                break;
                
            case TextDecision::CLOSE_ARTEFACT_AND_OPEN_NEW:
                // Close artifact and open semantic BDC
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                
                $node = $semanticTree->getNodeById($frameId);
                $pdfTag = $node->getPdfStructureTag();
                $nodeId = $node->id;
                
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($frameId, $mcid, $pdfTag, $pageNumber);
                }
                
                $output .= $contentRenderer();
                break;
                
            case TextDecision::CLOSE_ARTEFACT_AND_OPEN_WITH_PARENT_INFO:
                // Close artifact and open semantic BDC with parent info
                $output .= TagOps::artifactClose();
                $stateManager->closeArtifactBDC();
                
                $node = $semanticTree->getNodeById($frameId);
                // For transparent inline tags and #text nodes, skip to nearest block parent
                $parentNode = $node->isTextNode() || $node->isTransparentInlineTag()
                    ? $node->getNearestBlockParent()
                    : $node->getParent();
                $pdfTag = $parentNode->getPdfStructureTag();
                $nodeId = $parentNode->id;
                
                $mcid = $stateManager->getNextMCID();
                $output .= TagOps::bdcOpen($pdfTag, $mcid);
                $stateManager->openSemanticBDC($frameId, $mcid);
                
                if ($onBDCOpened !== null) {
                    $pageNumber = $stateManager->getCurrentPage();
                    $onBDCOpened($parentNode->id, $mcid, $pdfTag, $pageNumber);
                }
                
                $output .= $contentRenderer();
                break;
                
            case TextDecision::ARTIFACT:
                // Wrap as artifact (self-contained)
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
