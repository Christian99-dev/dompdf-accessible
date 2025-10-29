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
     * Open Artifact BDC
     * 
     * Used when:
     * - Node or parent has decorative role (role="presentation" or role="none")
     * - Node or parent has aria-hidden="true"
     * - Content should be marked as artifact but needs BDC opened first
     */
    case OPEN_ARTEFACT;

    /**
     * No operation (nothing to do)
     * 
     * Used when:
     * - Edge case where no action is needed
     */
    case OPEN_WITH_PARENT_INFO;
    /**
     * Close current BDC and open new one with parent info
     * 
     * Used when:
     * - New frame ID (different from active)
     * - A semantic BDC is currently open
     * - Need to close old BDC before opening new one
     * - Need to use PARENT's info for structure tree
     */
    case CLOSE_AND_OPEN_WITH_PARENT_INFO;
    
    /**
     * Close semantic BDC and open artifact
     * 
     * Used when:
     * - Coming from SEMANTIC state
     * - Node has decorative parent
     * - Need to close semantic BDC and open artifact
     */
    case CLOSE_SEMANTIC_AND_OPEN_ARTEFACT;
    
    /**
     * Close artifact and open semantic BDC
     * 
     * Used when:
     * - Coming from ARTIFACT state
     * - Node has NO decorative parent
     * - Need to close artifact and open semantic BDC
     */
    case CLOSE_ARTEFACT_AND_OPEN_NEW;
    
    /**
     * Close artifact and open semantic BDC with parent info
     * 
     * Used when:
     * - Coming from ARTIFACT state
     * - Node is text node
     * - Node has NO decorative parent
     * - Need to close artifact and open semantic BDC with parent info
     */
    case CLOSE_ARTEFACT_AND_OPEN_WITH_PARENT_INFO;
}
