<?php
/**
 * TaggingState - Enum for PDF/UA BDC State
 * 
 * Replaces two boolean flags (inSemanticState, inArtifactState) 
 * with a single enum value for better type-safety and clarity.
 * 
 * The three states are mutually exclusive:
 * - NONE: No BDC is currently open
 * - SEMANTIC: Tagged content with structure (P, Div, H1, etc.)
 * - ARTIFACT: Decorative content (borders, backgrounds, etc.)
 * 
 * Benefits over bool flags:
 * - Type-safe: Cannot have invalid combinations (both true)
 * - Clear: Single source of truth for state
 * - Maintainable: Add new states without adding more bools
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

enum TaggingState
{
    /**
     * No BDC block is currently open
     * This is the idle state between content blocks
     */
    case NONE;
    
    /**
     * Semantic BDC block is currently open
     * Has Frame ID and MCID for Structure Tree
     */
    case SEMANTIC;
    
    /**
     * Artifact BDC block is currently open
     * No Frame ID or MCID (not in Structure Tree)
     */
    case ARTIFACT;
}
