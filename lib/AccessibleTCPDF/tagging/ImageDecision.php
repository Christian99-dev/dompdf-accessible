<?php

/**
 * ImageDecision - Decision types for image rendering
 * 
 * Images are ATOMIC elements: always open → render → close
 * Each decision explicitly handles state transition
 * 
 * ARCHITECTURE:
 * - 3 possible states: NONE, SEMANTIC, ARTIFACT
 * - 2 image types: Semantic (with alt) or Artifact (without alt)
 * - Result: 6 atomic decisions (3 × 2)
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
enum ImageDecision
{
    // ========================================================================
    // FROM STATE: NONE
    // ========================================================================
    
    /**
     * Open semantic BDC → Render → Close
     * 
     * Used when:
     * - No BDC currently open (state = NONE)
     * - Image has alt attribute (semantic content)
     */
    case OPEN_SEMANTIC_AND_CLOSE;
    
    /**
     * Open artifact BDC → Render → Close
     * 
     * Used when:
     * - No BDC currently open (state = NONE)
     * - Image has no alt attribute (decorative)
     */
    case OPEN_ARTEFACT_AND_CLOSE;
    
    // ========================================================================
    // FROM STATE: SEMANTIC
    // ========================================================================
    
    /**
     * Close semantic BDC → Open semantic BDC → Render → Close
     * 
     * Used when:
     * - Semantic BDC currently open (state = SEMANTIC)
     * - Image has alt attribute (semantic content)
     */
    case CLOSE_SEMANTIC_AND_OPEN_SEMANTIC_AND_CLOSE;
    
    /**
     * Close semantic BDC → Open artifact BDC → Render → Close
     * 
     * Used when:
     * - Semantic BDC currently open (state = SEMANTIC)
     * - Image has no alt attribute (decorative)
     */
    case CLOSE_SEMANTIC_AND_OPEN_ARTEFACT_AND_CLOSE;
    
    // ========================================================================
    // FROM STATE: ARTIFACT
    // ========================================================================
    
    /**
     * Close artifact BDC → Open semantic BDC → Render → Close
     * 
     * Used when:
     * - Artifact BDC currently open (state = ARTIFACT)
     * - Image has alt attribute (semantic content)
     */
    case CLOSE_ARTEFACT_AND_OPEN_SEMANTIC_AND_CLOSE;
    
    /**
     * Close artifact BDC → Open artifact BDC → Render → Close
     * 
     * Used when:
     * - Artifact BDC currently open (state = ARTIFACT)
     * - Image has no alt attribute (decorative)
     */
    case CLOSE_ARTEFACT_AND_OPEN_ARTEFACT_AND_CLOSE;
}
