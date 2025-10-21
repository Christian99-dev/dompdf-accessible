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

/**
 * DrawingManager - Static helper methods for PDF/UA drawing decisions
 */
class DrawingManager
{
    /**
     * Analyze drawing context and return decision data
     * 
     * This is the CORE helper method that provides decision logic for drawing operations.
     * 
     * @param string $operationName Name for logging (Line, Rect, etc.)
     * @param string|null $currentFrameId Current frame being processed
     * @param array|null $activeBDC Reference to active BDC frame
     * @param bool $isInsideTaggedContent Whether we are currently inside tagged content
     * @param array|null $semanticElementsRef Reference to semantic elements
     * @return array ['should_close_bdc' => bool, 'wrap_as_artifact' => bool]
     */
    public function analyzeDrawingContext(
        string $operationName, 
        ?string $currentFrameId, 
        bool $isInsideTaggedContent,
        ?array $activeBDC,
        ?array $semanticElementsRef
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
        if ($currentFrameId !== null && $semanticElementsRef !== null) {
            $activeBDCElement = $semanticElementsRef[$activeBDC['semanticId']] ?? null;
            $currentElement = $semanticElementsRef[$currentFrameId] ?? null;
            
            if ($activeBDCElement && $currentElement) {
                // Check if current element is semantic child of active BDC element
                $parent = $currentElement->findParent($semanticElementsRef, true);
                
                if ($parent && $parent->id === $activeBDCElement->id) {
                    SimpleLogger::log("accessible_tcpdf_logs", $operationName, 
                        "→ Drawing belongs to active BDC element, keeping in BDC");
                    return ['should_close_bdc' => false, 'wrap_as_artifact' => false];
                }
                
                // REFINED LOGIC: Check if this is a table-related drawing
                if (self::isTableRelatedDrawing($currentElement)) {
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
     * Helper: Check if drawing operation is table-related (borders, etc.)
     * 
     * Table-related drawings should be Artifacts but NOT close active BDCs
     * because table content (TD elements) still needs to render after borders.
     * 
     * @param SemanticElement|null $element The element being drawn
     * @return bool True if this is table-related drawing
     */
    public function isTableRelatedDrawing($element): bool 
    {
        if (!$element) return false;
        
        $tableElements = ['table', 'tr', 'th', 'td', 'thead', 'tbody', 'tfoot'];
        return in_array($element->tag, $tableElements, true);
    }
    
    /**
     * Generate PDF operators for wrapping drawing as Artifact
     *
     * @param array $activeBDC The currently active BDC frame data 
     */
    public function getArtifactWrapOperators(?array $activeBDC): array
    {
        if ($activeBDC !== null) {
            // UNIVERSAL PATTERN: Close-Draw-Reopen
            // Works for: table borders, text-decoration underlines, any graphics
            return [
                'before' => "EMC\n/Artifact BMC",
                'after' => "EMC\n/" . $activeBDC['pdfTag'] . ' << /MCID ' . $activeBDC['mcid'] . ' >> BDC'
            ];
        } else {
            // No active BDC: Simple Artifact wrap
            return [
                'before' => '/Artifact BMC',
                'after' => "\nEMC"
            ];
        }
    }
}