<?php
/**
 * TextDecision - Decision types for text rendering
 * 
 * Simple enum representing what action to take when processing text.
 * No data stored - all data comes from StateManager!
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
enum TextDecision
{
    /**
     * Open new semantic BDC
     * 
     * Used when:
     * - New frame ID (different from active)
     * - Need to close old BDC and open new one
     */
    case OPEN_NEW;
    
    /**
     * Continue existing BDC (transparent)
     * 
     * Used when:
     * - Same frame ID as active
     * - Just render content without opening/closing
     */
    case CONTINUE;
    
    /**
     * Wrap as Artifact
     * 
     * Used when:
     * - No frame ID
     * - Node not found in semantic tree
     */
    case ARTIFACT;
}
