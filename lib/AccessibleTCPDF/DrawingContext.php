<?php
/**
 * Drawing Context - Decision for how to handle drawing operations
 * 
 * Represents the context in which a drawing operation occurs
 * and how it should be wrapped for PDF/UA compliance.
 * 
 * @package dompdf-accessible
 */

/**
 * Drawing Context Types
 * 
 * Defines the three cases for drawing operations:
 */
class DrawingContext
{
    /**
     * Drawing is part of semantic content (e.g., text-decoration: underline)
     * ACTION: Keep inside current BDC, do NOT wrap
     */
    const CONTENT = 'content';
    
    /**
     * Drawing is decorative and should be isolated (e.g., table borders)
     * ACTION: Close-Artifact-Continue pattern
     */
    const DECORATIVE_INSIDE_TAG = 'decorative_inside_tag';
    
    /**
     * Drawing is outside any tagged content (e.g., page decorations)
     * ACTION: Wrap as Artifact
     */
    const ARTIFACT = 'artifact';
    
    /**
     * Context type
     * @var string
     */
    public string $type;
    
    /**
     * Optional reason/explanation
     * @var string
     */
    public string $reason;
    
    /**
     * Constructor
     * 
     * @param string $type Context type (use class constants)
     * @param string $reason Optional reason
     */
    public function __construct(string $type, string $reason = '')
    {
        $this->type = $type;
        $this->reason = $reason;
    }
    
    /**
     * Create CONTENT context
     * 
     * @param string $reason Reason why it's content
     * @return self
     */
    public static function content(string $reason = ''): self
    {
        return new self(self::CONTENT, $reason);
    }
    
    /**
     * Create DECORATIVE_INSIDE_TAG context
     * 
     * @param string $reason Reason why it's decorative
     * @return self
     */
    public static function decorativeInsideTag(string $reason = ''): self
    {
        return new self(self::DECORATIVE_INSIDE_TAG, $reason);
    }
    
    /**
     * Create ARTIFACT context
     * 
     * @return self
     */
    public static function artifact(): self
    {
        return new self(self::ARTIFACT, 'Outside any tagged content');
    }
    
    /**
     * Check if context is CONTENT
     * 
     * @return bool
     */
    public function isContent(): bool
    {
        return $this->type === self::CONTENT;
    }
    
    /**
     * Check if context is DECORATIVE_INSIDE_TAG
     * 
     * @return bool
     */
    public function isDecorativeInsideTag(): bool
    {
        return $this->type === self::DECORATIVE_INSIDE_TAG;
    }
    
    /**
     * Check if context is ARTIFACT
     * 
     * @return bool
     */
    public function isArtifact(): bool
    {
        return $this->type === self::ARTIFACT;
    }
}
