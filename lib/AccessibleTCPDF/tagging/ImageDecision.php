<?php

/**
 * ImageDecision - Simplified enum for image rendering
 * 
 * For testing: Only wraps images in /Artifact for now
 */
enum ImageDecision
{

    /**
     * Close current BDC and open new semantic BDC
     */
    case CLOSE_AND_OPEN_SEMANTIC;
    
    /**
     * Open new semantic BDC
     */
    case OPEN_SEMANTIC;

    /**
     * Close current BDC and open new /Artifact
     */
    case CLOSE_AND_OPEN_ARTEFACT;

    /**
     * Wrap image in /Artifact tag
     * Open /Artifact 
     */
    case OPEN_ARTEFACT;
    
    /**
     * Continue in current context without wrapping (fallback)
     */
    case CONTINUE;
}
