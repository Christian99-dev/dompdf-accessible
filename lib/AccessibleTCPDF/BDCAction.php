<?php
/**
 * BDC Action Decision
 * 
 * Represents the action to take for BDC lifecycle management.
 * This decouples tagging decisions from BDC lifecycle decisions.
 * 
 * @package dompdf-accessible
 */

/**
 * BDC Action Types
 * 
 * Defines what action should be taken for BDC block management
 * independent of semantic tagging decisions.
 */
class BDCAction
{
    /**
     * Open a new BDC block (closing previous if needed)
     * Used when transitioning to a new structural element
     */
    const OPEN_NEW = 'open_new';
    
    /**
     * Continue in current BDC block without changes
     * Used for transparent inline tags that inherit parent's BDC
     */
    const CONTINUE = 'continue';
    
    /**
     * Close current BDC and wrap content as Artifact
     * Used for non-semantic content (headers, footers, decorations)
     */
    const CLOSE_AND_ARTIFACT = 'close_and_artifact';
    
    /**
     * Action type
     * @var string
     */
    public string $type;
    
    /**
     * Optional metadata for the action
     * @var array
     */
    public array $metadata;
    
    /**
     * Constructor
     * 
     * @param string $type Action type (use class constants)
     * @param array $metadata Optional metadata
     */
    public function __construct(string $type, array $metadata = [])
    {
        $this->type = $type;
        $this->metadata = $metadata;
    }
    
    /**
     * Create OPEN_NEW action
     * 
     * @return self
     */
    public static function openNew(): self
    {
        return new self(self::OPEN_NEW);
    }
    
    /**
     * Create CONTINUE action
     * 
     * @param string $reason Optional reason for continuing
     * @return self
     */
    public static function continue(string $reason = ''): self
    {
        return new self(self::CONTINUE, ['reason' => $reason]);
    }
    
    /**
     * Create CLOSE_AND_ARTIFACT action
     * 
     * @return self
     */
    public static function closeAndArtifact(): self
    {
        return new self(self::CLOSE_AND_ARTIFACT);
    }
    
    /**
     * Check if action is OPEN_NEW
     * 
     * @return bool
     */
    public function isOpenNew(): bool
    {
        return $this->type === self::OPEN_NEW;
    }
    
    /**
     * Check if action is CONTINUE
     * 
     * @return bool
     */
    public function isContinue(): bool
    {
        return $this->type === self::CONTINUE;
    }
    
    /**
     * Check if action is CLOSE_AND_ARTIFACT
     * 
     * @return bool
     */
    public function isCloseAndArtifact(): bool
    {
        return $this->type === self::CLOSE_AND_ARTIFACT;
    }
}
