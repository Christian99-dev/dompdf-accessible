<?php
/**
 * TaggingStateManager - Manages PDF/UA Tagging State
 * 
 * Tracks TWO separate BDC states during PDF rendering:
 * 
 * 1. SEMANTIC STATE: Tagged content with structure (P, Div, H1, etc.)
 *    - Has Frame ID reference
 *    - Has MCID for Structure Tree
 *    - Must be re-opened after Artifact interruptions
 * 
 * 2. ARTIFACT STATE: Decorative content (borders, backgrounds, etc.)
 *    - No Frame ID (stateless)
 *    - No MCID (not in Structure Tree)
 *    - Prevents nested Artifacts
 * 
 * CRITICAL: Both states are mutually exclusive! 
 * Only ONE can be TRUE at a time (prevents nested BDC).
 * Both can be FALSE (between content blocks).
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;

require_once __DIR__ . '/TagOps.php';
require_once __DIR__ . '/TaggingState.php';
require_once __DIR__ . '/../../../src/SimpleLogger.php';

class TaggingStateManager
{
    /**
     * Current tagging state (NONE, SEMANTIC, or ARTIFACT)
     * Replaces two boolean flags for better type-safety
     * @var TaggingState
     */
    private TaggingState $state = TaggingState::NONE;
    
    /**
     * MCID of the currently open semantic BDC
     * null = No semantic BDC is open
     * @var int|null
     */
    private ?int $activeSemanticMCID = null;
    
    /**
     * Current MCID (Marked Content ID) counter
     * Increments for each semantic BDC block opened
     * @var int
     */
    private int $mcidCounter = 0;
    
    /**
     * Current page number (1-based)
     * Used for Structure Tree entries and callbacks
     * @var int
     */
    private int $currentPage = 0;
    
    // ========================================================================
    // STATE ACCESS
    // ========================================================================
    
    /**
     * Get current tagging state
     * 
     * @return TaggingState Current state (NONE, SEMANTIC, or ARTIFACT)
     */
    public function getState(): TaggingState
    {
        return $this->state;
    }
    
    // ========================================================================
    // SEMANTIC STATE METHODS
    // ========================================================================
    
    /**
     * Get the MCID of the currently active semantic BDC
     * 
     * CRITICAL: This is used for re-opening BDC with the SAME MCID after interruptions.
     * 
     * @return int|null MCID or null if no semantic BDC is open
     */
    public function getActiveSemanticMCID(): ?int
    {
        return $this->activeSemanticMCID;
    }
    
    /**
     * Open a semantic BDC block
     * 
     * CRITICAL: Automatically closes any open Artifact BDC!
     * This enforces mutual exclusion (no nested BDCs).
     * 
     * @param string $frameId The frame ID being tagged
     * @param int $mcid The MCID for this BDC
     * @return void
     */
    public function openSemanticBDC(string $frameId, int $mcid): void
    {
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Opening Semantic BDC: frameId=%s, mcid=%d, previousState=%s", 
                $frameId, $mcid, $this->state->name));
        
        $this->state = TaggingState::SEMANTIC;
        $this->activeSemanticMCID = $mcid;
    }
    
    /**
     * Close the active semantic BDC block
     * 
     * @return void
     */
    public function closeSemanticBDC(): void
    {
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Closing Semantic BDC: mcid=%s", 
                $this->activeSemanticMCID !== null ? (string)$this->activeSemanticMCID : 'null'));
        
        $this->state = TaggingState::NONE;
        $this->activeSemanticMCID = null;
    }
    
    // ========================================================================
    // ARTIFACT STATE METHODS
    // ========================================================================
    
    /**
     * Open an Artifact BDC block
     * 
     * CRITICAL: Automatically closes any open semantic BDC!
     * This enforces mutual exclusion (no nested BDCs).
     * 
     * @return void
     */
    public function openArtifactBDC(): void
    {
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Opening Artifact BDC: previousState=%s (frameId=%s)", 
                $this->state->name,
                $this->activeSemanticFrameId ?? 'null'));
        
        $this->state = TaggingState::ARTIFACT;
    }
    
    /**
     * Close the active Artifact BDC block
     * 
     * @return void
     */
    public function closeArtifactBDC(): void
    {
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, "Closing Artifact BDC");
        $this->state = TaggingState::NONE;
    }
    
    // ========================================================================
    // COMBINED STATE QUERIES
    // ========================================================================
    
    /**
     * Close all open tagging states and return EMC operators
     * 
     * CRITICAL: Called at end of page to ensure no unclosed BDC blocks.
     * Must close in correct order and return all EMC operators as string.
     * 
     * Use case: _endpage() needs to close any open BDC before wrapping final content as Artifact
     * 
     * @return string EMC operators (can be empty string if nothing open)
     */
    public function closeCurrentTag(): string
    {
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Closing all states: state=%s", $this->state->name));
        
        $output = '';
        
        // Close semantic state if open
        if ($this->state === TaggingState::SEMANTIC) {
            $output .= TagOps::emc();
            $this->closeSemanticBDC();
        }
        
        // Close artifact state if open
        if ($this->state === TaggingState::ARTIFACT) {
            $output .= TagOps::artifactClose();
            $this->closeArtifactBDC();
        }
        
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Closed all states, output length=%d", strlen($output)));
        
        return $output;
    }
    
    // ========================================================================
    // MCID MANAGEMENT
    // ========================================================================
    
    /**
     * Get the current MCID counter value
     * 
     * @return int Current MCID
     */
    public function getCurrentMCID(): int
    {
        return $this->mcidCounter;
    }
    
    /**
     * Increment and return the next MCID
     * 
     * Call this when opening a new semantic BDC block.
     * NOT used for Artifacts (they don't have MCIDs).
     * 
     * @return int Next MCID value
     */
    public function getNextMCID(): int
    {
        $mcid = $this->mcidCounter++;
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Allocated MCID: %d", $mcid));
        return $mcid;
    }
    
    /**
     * Reset MCID counter (typically called at start of new page)
     * 
     * @return void
     */
    public function resetMCID(): void
    {
        $this->mcidCounter = 0;
    }
    
    // ========================================================================
    // PAGE MANAGEMENT
    // ========================================================================
    
    /**
     * Set current page number
     * 
     * Called by AccessibleTCPDF when starting a new page.
     * Should also reset MCID counter for new page.
     * 
     * @param int $pageNumber Current page number (1-based)
     * @return void
     */
    public function setCurrentPage(int $pageNumber): void
    {
        $this->currentPage = $pageNumber;
        
        // Auto-reset MCID counter for new page
        if ($pageNumber > 0) {
            $this->resetMCID();
        }
        
        SimpleLogger::log("pdf_backend_state_manager_logs", __METHOD__, 
            sprintf("Page set to %d, MCID counter reset", $pageNumber));
    }
    
    /**
     * Get current page number
     * 
     * Used by callbacks to pass page number to StructureTreeBuilder.
     * 
     * @return int Current page number (0 if not set)
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }
    
    // ========================================================================
    // DEBUGGING
    // ========================================================================
    
    /**
     * Get current state for debugging
     * 
     * @return array State information
     */
    public function getDebugInfo(): array
    {
        return [
            'state' => $this->state->name,
            'mcidCounter' => $this->mcidCounter
        ];
    }
    
    /**
     * Validate state consistency (for debugging/testing)
     * 
     * INVARIANT: Semantic and Artifact states are mutually exclusive
     * 
     * @return bool True if state is consistent
     * @throws \LogicException If state is inconsistent
     */
    public function validateState(): bool
    {
        if ($this->state === TaggingState::SEMANTIC && $this->state === TaggingState::ARTIFACT) {
            throw new \LogicException(
                'INVALID STATE: Both semantic and artifact BDC are open! '
            );
        }
        
        return true;
    }
}
