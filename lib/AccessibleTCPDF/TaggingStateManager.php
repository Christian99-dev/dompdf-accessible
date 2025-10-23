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
class TaggingStateManager
{
    /**
     * Currently active semantic BDC frame ID
     * null = No semantic BDC is open
     * @var string|null
     */
    private ?string $activeSemanticFrameId = null;
    
    /**
     * Are we currently inside an Artifact BDC?
     * @var bool
     */
    private bool $isInArtifact = false;
    
    /**
     * Current MCID (Marked Content ID) counter
     * Increments for each semantic BDC block opened
     * @var int
     */
    private int $mcidCounter = 0;
    
    // ========================================================================
    // SEMANTIC STATE METHODS
    // ========================================================================
    
    /**
     * Check if we are currently inside a semantic tagged content block
     * 
     * Use this to decide:
     * - Should we open a new semantic BDC? (if false)
     * - Do we need to close semantic BDC before Artifact? (if true)
     * - Should we re-open semantic BDC after Artifact? (if true)
     * 
     * @return bool True if semantic BDC is currently open
     */
    public function hasSemanticState(): bool
    {
        return $this->activeSemanticFrameId !== null;
    }
    
    /**
     * Get the currently active semantic BDC frame ID
     * 
     * Used for re-opening semantic BDC after Artifact interruptions.
     * 
     * @return string|null Frame ID or null if no semantic BDC is open
     */
    public function getActiveSemanticFrameId(): ?string
    {
        return $this->activeSemanticFrameId;
    }
    
    /**
     * Open a semantic BDC block
     * 
     * CRITICAL: Automatically closes any open Artifact BDC!
     * This enforces mutual exclusion (no nested BDCs).
     * 
     * @param string $frameId The frame ID being tagged
     * @return void
     */
    public function openSemanticBDC(string $frameId): void
    {
        // Enforce mutual exclusion
        if ($this->isInArtifact) {
            $this->isInArtifact = false;
        }
        
        $this->activeSemanticFrameId = $frameId;
    }
    
    /**
     * Close the active semantic BDC block
     * 
     * @return void
     */
    public function closeSemanticBDC(): void
    {
        $this->activeSemanticFrameId = null;
    }
    
    // ========================================================================
    // ARTIFACT STATE METHODS
    // ========================================================================
    
    /**
     * Check if we are currently inside an Artifact BDC block
     * 
     * Use this to prevent nested Artifacts:
     * - Don't open Artifact if already in one
     * - Don't open Artifact if semantic BDC is open (close it first)
     * 
     * @return bool True if Artifact BDC is currently open
     */
    public function hasArtifactState(): bool
    {
        return $this->isInArtifact;
    }
    
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
        // Enforce mutual exclusion
        if ($this->activeSemanticFrameId !== null) {
            $this->activeSemanticFrameId = null;
        }
        
        $this->isInArtifact = true;
    }
    
    /**
     * Close the active Artifact BDC block
     * 
     * @return void
     */
    public function closeArtifactBDC(): void
    {
        $this->isInArtifact = false;
    }
    
    // ========================================================================
    // COMBINED STATE QUERIES
    // ========================================================================
    
    /**
     * Check if ANY BDC block is currently open (semantic OR artifact)
     * 
     * Use this for:
     * - Transform operations (q/Q suppression if ANY BDC is open)
     * - Font operations (suppression if ANY BDC is open)
     * 
     * @return bool True if any BDC is currently open
     */
    public function hasAnyTaggingState(): bool
    {
        return $this->hasSemanticState() || $this->hasArtifactState();
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
        return $this->mcidCounter++;
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
            'hasSemanticState' => $this->hasSemanticState(),
            'hasArtifactState' => $this->hasArtifactState(),
            'hasAnyTaggingState' => $this->hasAnyTaggingState(),
            'activeSemanticFrameId' => $this->activeSemanticFrameId,
            'isInArtifact' => $this->isInArtifact,
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
        if ($this->hasSemanticState() && $this->hasArtifactState()) {
            throw new \LogicException(
                'INVALID STATE: Both semantic and artifact BDC are open! ' .
                'Frame ID: ' . $this->activeSemanticFrameId
            );
        }
        
        return true;
    }
}
