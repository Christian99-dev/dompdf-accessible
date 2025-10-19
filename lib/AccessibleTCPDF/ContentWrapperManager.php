<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;

/**
 * Content Wrapper Manager - Handles content wrapping and font injection
 * 
 * This class encapsulates ALL content wrapping operations:
 * - Font operator (Tf) injection into BT...ET blocks
 * - Artifact wrapping (BMC...EMC) for decorative content
 * - Graphics operation wrapping for PDF/UA compliance
 * - Font family mapping for PDF/UA (Base 14 → DejaVu)
 * 
 * **Single Responsibility:** Content Wrapping & Font Management
 * 
 * Usage:
 * ```php
 * $manager = new ContentWrapperManager();
 * 
 * // Inject font into text operations
 * $cellCode = $manager->injectFontOperator($cellCode, $fontIndex, $fontSize);
 * 
 * // Wrap decorative content
 * $pdfCode = $manager->wrapAsArtifact($pdfCode);
 * 
 * // Wrap graphics operations
 * $graphicsCode = $manager->wrapGraphicsOperation($graphicsCode, $insideBDC);
 * ```
 * 
 * @package dompdf-accessible
 */
class ContentWrapperManager
{
    /**
     * Font family mapping for PDF/UA compliance
     * Maps TCPDF Base 14 fonts to embedded DejaVu equivalents
     * @var array
     */
    private const PDFUA_FONT_MAP = [
        'helvetica' => 'dejavusans',
        'times' => 'dejavuserif',
        'courier' => 'dejavusansmono',
        'symbol' => 'dejavusans',
        'zapfdingbats' => 'dejavusans'
    ];
    
    /**
     * Inject font operator (Tf) into BT...ET text blocks
     * 
     * CRITICAL: TCPDF's q...Q operators isolate graphics state.
     * Without Tf in EVERY BT block, PDF viewers don't know which font to use!
     * 
     * Pattern: BT → BT /F1 12.00 Tf
     * 
     * @param string $cellCode PDF code from TCPDF's getCellCode()
     * @param int $fontIndex Font index (e.g., 1 for /F1)
     * @param float $fontSize Font size in points
     * @return string Modified PDF code with Tf operators
     */
    public function injectFontOperator(string $cellCode, int $fontIndex, float $fontSize): string
    {
        // Generate Tf operator
        $tfOperator = sprintf('/F%d %F Tf ', $fontIndex, $fontSize);
        
        // Inject after every BT operator
        $modifiedCode = preg_replace(
            '/\bBT\s+/',           // Match: BT followed by whitespace
            'BT ' . $tfOperator,    // Replace with: BT /F1 12.00 Tf
            $cellCode
        );
        
        // Log if injection happened
        if ($modifiedCode !== $cellCode) {
            $count = substr_count($modifiedCode, $tfOperator);
            SimpleLogger::log("content_wrapper_manager", __METHOD__, 
                sprintf("Injected Tf operator %d times: %s", $count, $tfOperator)
            );
        }
        
        return $modifiedCode;
    }
    
    /**
     * Wrap content as PDF Artifact
     * 
     * Artifacts are decorative content that screenreaders should ignore.
     * Format: /Artifact BMC\n{content}\nEMC\n
     * 
     * @param string $pdfCode PDF code to wrap
     * @return string Wrapped PDF code
     */
    public function wrapAsArtifact(string $pdfCode): string
    {
        SimpleLogger::log("content_wrapper_manager", __METHOD__, 
            sprintf("Wrapping as Artifact (%d bytes)", strlen($pdfCode))
        );
        
        return "/Artifact BMC\n" . $pdfCode . "EMC\n";
    }
    
    
    /**
     * Map TCPDF font family to PDF/UA-compliant embedded font
     * 
     * PDF/UA Rule 7.21.4.1: ALL fonts must be embedded.
     * TCPDF's Base 14 fonts (Helvetica, Times, etc.) are NOT embedded.
     * We redirect them to DejaVu equivalents.
     * 
     * @param string $family Original font family
     * @return string Mapped font family (or original if no mapping)
     */
    public function mapPDFUAFont(string $family): string
    {
        $familyLower = strtolower($family);
        
        if (isset(self::PDFUA_FONT_MAP[$familyLower])) {
            $mapped = self::PDFUA_FONT_MAP[$familyLower];
            
            SimpleLogger::log("content_wrapper_manager", __METHOD__, 
                sprintf("PDF/UA: Redirected font '%s' → '%s' for embedding", 
                    $family, $mapped)
            );
            
            return $mapped;
        }
        
        return $family;
    }
    
    /**
     * Generate complete cell code with proper wrapping
     * 
     * This is a convenience method that combines:
     * 1. Artifact wrapping (if needed)
     * 2. Font injection (if inside tagged content)
     * 
     * @param string $cellCode Original cell code
     * @param bool $isArtifact True if should wrap as Artifact
     * @param int|null $fontIndex Font index (null = no injection)
     * @param float|null $fontSize Font size (null = no injection)
     * @return string Wrapped and modified cell code
     */
    public function processCell(
        string $cellCode, 
        bool $isArtifact, 
        ?int $fontIndex = null, 
        ?float $fontSize = null
    ): string
    {
        // CASE 1: Artifact wrapping
        if ($isArtifact) {
            return $this->wrapAsArtifact($cellCode);
        }
        
        // CASE 2: Tagged content with font injection
        if ($fontIndex !== null && $fontSize !== null) {
            return $this->injectFontOperator($cellCode, $fontIndex, $fontSize);
        }
        
        // CASE 3: Tagged content without font injection
        return $cellCode;
    }
    
    /**
     * Check if operation needs wrapping
     * 
     * Helper method to determine if a graphics operation needs Artifact wrapping.
     * 
     * @param bool $pdfuaMode True if PDF/UA mode is enabled
     * @param bool $insideBDC True if inside tagged content
     * @param int $state TCPDF state (2 = rendering page)
     * @param int $page Current page number
     * @return bool True if operation needs wrapping
     */
    public function shouldWrapOperation(
        bool $pdfuaMode, 
        bool $insideBDC, 
        int $state, 
        int $page
    ): bool
    {
        // Not PDF/UA mode → no wrapping needed
        if (!$pdfuaMode) {
            return false;
        }
        
        // Not rendering a page → no wrapping needed
        if ($state != 2 || $page <= 0) {
            return false;
        }
        
        // Inside BDC → suppress (handled by caller)
        // Outside BDC → wrap as Artifact
        return !$insideBDC;
    }
}
