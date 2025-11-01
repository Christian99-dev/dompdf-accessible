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
     * Interrupt semantic BDC - SAME frame (reuse MCID)
     * 
     * Used when:
     * - Semantic BDC is open for frameId X
     * - Drawing is for SAME frameId X
     * - Example: Text underline/strikethrough
     * - Action: Close → Artifact → Draw → Close → Reopen with SAME MCID
     */
    case CLOSE_BDC_ARTIFACT_REOPEN_SAME;
    
    /**
     * Close semantic BDC and draw as artifact (no reopen)
     * 
     * Used when:
     * - Semantic BDC is open for frameId X
     * - Drawing is for DIFFERENT frameId Y
     * - Example: H1 active, H2 background draws
     * - Action: Close → Artifact → Draw → Stay in artifact (NO reopen!)
     */
    case CLOSE_BDC_ARTIFACT;
    
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
