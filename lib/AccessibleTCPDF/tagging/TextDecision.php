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
     * Open new semantic BDC (no BDC currently open)
     * 
     * Used when:
     * - New frame ID
     * - No semantic BDC is currently open
     * - Just open new BDC without closing
     */
    case OPEN_NEW;
    
    /**
     * Close current BDC and open new one
     * 
     * Used when:
     * - New frame ID (different from active)
     * - A semantic BDC is currently open
     * - Need to close old BDC before opening new one
     */
    case CLOSE_AND_OPEN_NEW;
    
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

    /**
     * No operation (nothing to do)
     * 
     * Used when:
     * - Edge case where no action is needed
     */
    case OPEN_WITH_PARENT_INFO;
}
