<?php
/**
 * DrawingManager - Helper Methods for PDF Drawing Operations
 * 
 * ARCHITECTURAL RESPONSIBILITY:
 * ============================
 * This manager provides STATIC HELPER METHODS for PDF/UA compliant drawing operations.
 * It analyzes drawing context and provides decision data, but does NOT execute operations.
 * 
 * DECISION CRITERIA:
 * 1. Text decorations (underlines, etc.) → Keep in BDC (semantic)
 * 2. Table borders, structural lines → DON'T close BDC, just wrap as Artifact  
 * 3. Background graphics → Always Artifact
 * 
 * @package dompdf-accessible
 */

use Dompdf\SimpleLogger;
use Dompdf\SemanticTree;
use Dompdf\SemanticNode;

/**
 * DrawingManager - Static helper methods for PDF/UA drawing decisions
 */
class DrawingManager
{
    /**
     * @var SemanticTree The semantic tree
     */
    private SemanticTree $tree;
    
    /**
     * PDF/UA Artifact wrapping operators (constants for reusability)
     */
    private const ARTIFACT_OPEN = '/Artifact BMC';
    private const ARTIFACT_CLOSE = "\nEMC";
    private const EMC = "EMC\n";
    
    /**
     * Constructor
     * 
     * @param SemanticTree $tree The semantic tree
     */
    public function __construct(SemanticTree $tree)
    {
        $this->tree = $tree;
    }
    
    /**
     * Analyze drawing context and return decision data
     * 
     * This is the CORE helper method that provides decision logic for drawing operations.
     * 
     * @param string $operationName Name for logging (Line, Rect, etc.)
     * @param string|null $currentFrameId Current frame being processed
     * @param bool $isInsideTaggedContent Whether we are currently inside tagged content
     * @param array|null $activeBDC Reference to active BDC frame
     * @return array ['should_close_bdc' => bool, 'wrap_as_artifact' => bool]
     */
    public function analyzeDrawingContext(
        string $operationName, 
        ?string $currentFrameId, 
        bool $isInsideTaggedContent,
        ?array $activeBDC
    ): array {
        SimpleLogger::log("accessible_tcpdf_logs", $operationName, sprintf(
            "FRAME_ID: %s | ACTIVE_BDC: %s | INSIDE_BDC: %s",
            $currentFrameId ?? 'NULL',
            $activeBDC['semanticId'] ?? 'NONE', 
            $isInsideTaggedContent ? 'true' : 'false'
        ));
        
        // No BDC active → Always wrap as Artifact
        if ($activeBDC === null) {
            SimpleLogger::log("accessible_tcpdf_logs", $operationName, 
                "→ No active BDC, wrapping as Artifact");
            return ['should_close_bdc' => false, 'wrap_as_artifact' => true];
        }
        
        // Same frame as BDC → Text decoration, keep in BDC
        if ($currentFrameId === $activeBDC['semanticId']) {
            SimpleLogger::log("accessible_tcpdf_logs", $operationName, 
                "→ Same frame as BDC, drawing inside BDC");
            return ['should_close_bdc' => false, 'wrap_as_artifact' => false];
        }
        
        // Different frame → Analyze semantic relationship
        if ($currentFrameId !== null) {
            $activeBDCElement = $this->tree->getNodeById($activeBDC['semanticId']);
            $currentElement = $this->tree->getNodeById($currentFrameId);
            
            if ($activeBDCElement && $currentElement) {
                // Check if current element is semantic child of active BDC element
                $parent = $currentElement->getParent();
                
                if ($parent && $parent->id === $activeBDCElement->id) {
                    SimpleLogger::log("accessible_tcpdf_logs", $operationName, 
                        "→ Drawing belongs to active BDC element, keeping in BDC");
                    return ['should_close_bdc' => false, 'wrap_as_artifact' => false];
                }
                
                // REFINED LOGIC: Check if this is a table-related drawing
                if ($currentElement->isTableRelated()) {
                    SimpleLogger::log("accessible_tcpdf_logs", $operationName, 
                        "→ Table-related drawing: DON'T close BDC, just wrap as Artifact");
                    return ['should_close_bdc' => false, 'wrap_as_artifact' => true];
                }
            }
        }
        
        // Default: Close BDC and wrap as Artifact (other decorative elements)
        SimpleLogger::log("accessible_tcpdf_logs", $operationName, 
            "→ Non-table decorative drawing, closing BDC and wrapping as Artifact");
        return ['should_close_bdc' => true, 'wrap_as_artifact' => true];
    }
    
    /**
     * Generate PDF operators for wrapping drawing as Artifact
     * 
     * This method returns operators for the Close-Draw-Reopen pattern:
     * 1. Close current BDC (EMC)
     * 2. Open Artifact context (/Artifact BMC)
     * 3. Draw graphics
     * 4. Close Artifact (EMC)
     * 5. Reopen original BDC (/Tag BDC)
     * 
     * This pattern preserves the semantic context while marking graphics as decorative.
     *
     * @param array|null $activeBDC The currently active BDC frame data 
     * @return array ['before' => string, 'after' => string]
     */
    public function getArtifactWrapOperators(?array $activeBDC): array
    {
        if ($activeBDC !== null) {
            // UNIVERSAL PATTERN: Close-Draw-Reopen
            // Works for: table borders, text-decoration underlines, any graphics
            $bdcReopenString = sprintf(
                "/%s << /MCID %d >> BDC", 
                $activeBDC['pdfTag'], 
                $activeBDC['mcid']
            );
            
            return [
                'before' => self::EMC . self::ARTIFACT_OPEN,
                'after' => self::EMC . $bdcReopenString
            ];
        } else {
            // No active BDC: Simple Artifact wrap
            return [
                'before' => self::ARTIFACT_OPEN,
                'after' => self::ARTIFACT_CLOSE
            ];
        }
    }
}