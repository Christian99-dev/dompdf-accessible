<?php
/**
 * DrawingDecision - Decision types for drawing operations
 * 
 * Simple enum representing what action to take when processing drawings.
 * No data stored - all data comes from StateManager!
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
enum DrawingDecision
{
    /**
     * Interrupt semantic BDC
     * 
     * Used when:
     * - Semantic BDC is currently open
     * - Need to: Close semantic → Open artifact → Draw → Close artifact → Re-open semantic
     */
    case CLOSE_BDC_ARTIFACT_REOPEN;
    
    /**
     * Continue in existing Artifact
     * 
     * Used when:
     * - Artifact BDC is already open
     * - Just draw without opening new BDC
     */
    case CONTINUE;
    
    /**
     * Wrap as new Artifact
     * 
     * Used when:
     * - No BDC is currently open
     * - Wrap drawing in /Artifact BMC ... EMC
     */
    case ARTIFACT;
}
