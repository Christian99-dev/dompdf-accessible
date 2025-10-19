<?php
/**
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;

/**
 * BDC State Manager - Manages BDC/EMC lifecycle and nesting
 * 
 * This class encapsulates ALL state management for PDF Tagged Content:
 * - Tracks active BDC (Marked Content) blocks
 * - Manages nesting depth for proper EMC closing
 * - Handles frame transitions and boundary detection
 * - Generates PDF operators (BDC/EMC strings)
 * 
 * **Single Responsibility:** BDC/EMC State Machine
 * 
 * Usage:
 * ```php
 * $manager = new BDCStateManager();
 * 
 * // Check if new BDC needed
 * if ($manager->shouldOpenNewBDC($semanticId)) {
 *     $pdf .= $manager->closePreviousBDC();
 *     $pdf .= $manager->openBDC($pdfTag, $mcid, $semanticId);
 * }
 * 
 * // Close at end
 * $pdf .= $manager->closeBDC();
 * ```
 * 
 * @package dompdf-accessible
 */
class BDCStateManager
{
    /**
     * Currently active BDC block
     * Format: ['semanticId' => string, 'pdfTag' => string, 'mcid' => int] or null
     * @var array|null
     */
    private ?array $activeBDCFrame = null;
    
    /**
     * Current BDC nesting depth
     * Used to prevent wrapping graphics operations as Artifacts inside tagged content
     * @var int
     */
    private int $bdcDepth = 0;
    
    /**
     * Check if a new BDC block should be opened
     * 
     * Decision Logic:
     * - No active BDC → Open new
     * - Different semantic ID → Close previous, open new
     * - Same semantic ID → Continue (no new BDC)
     * 
     * @param string $semanticId The semantic element ID (Frame ID)
     * @return bool True if new BDC should be opened
     */
    public function shouldOpenNewBDC(string $semanticId): bool
    {
        // No active BDC → need to open new one
        if ($this->activeBDCFrame === null) {
            return true;
        }
        
        // Different semantic ID → frame boundary crossed, need new BDC
        if ($this->activeBDCFrame['semanticId'] !== $semanticId) {
            return true;
        }
        
        // Same semantic ID → continue current BDC
        return false;
    }
    
    /**
     * Check if currently inside tagged content
     * 
     * This is used by graphics operations to determine if they should:
     * - Wrap as Artifact (outside BDC, depth = 0)
     * - Suppress completely (inside BDC, depth > 0)
     * 
     * @return bool True if inside tagged content (depth > 0)
     */
    public function isInsideTaggedContent(): bool
    {
        return $this->bdcDepth > 0;
    }
    
    /**
     * Get current nesting depth
     * 
     * @return int Current depth (0 = outside all BDC blocks)
     */
    public function getDepth(): int
    {
        return $this->bdcDepth;
    }
    
    /**
     * Get active BDC frame info
     * 
     * @return array|null ['semanticId', 'pdfTag', 'mcid'] or null if no active BDC
     */
    public function getActiveBDCFrame(): ?array
    {
        return $this->activeBDCFrame;
    }
    
    /**
     * Open a new BDC (Begin Marked Content) block
     * 
     * Generates PDF operator: /Tag << /MCID N >> BDC
     * 
     * @param string $pdfTag PDF structure tag (e.g., 'P', 'H1', 'Figure')
     * @param int $mcid Marked Content ID for ParentTree reference
     * @param string $semanticId Semantic element ID for frame tracking
     * @return string PDF code to output (BDC operator)
     */
    public function openBDC(string $pdfTag, int $mcid, string $semanticId): string
    {
        // Store active BDC state
        $this->activeBDCFrame = [
            'semanticId' => $semanticId,
            'pdfTag' => $pdfTag,
            'mcid' => $mcid
        ];
        
        // Increment nesting depth
        $this->bdcDepth++;
        
        // Log state change
        SimpleLogger::log("bdc_state_manager", __METHOD__, 
            sprintf("Opened BDC: /%s MCID=%d for frame %s (depth=%d)", 
                $pdfTag, $mcid, $semanticId, $this->bdcDepth)
        );
        
        // Generate PDF operator
        return sprintf("/%s << /MCID %d >> BDC\n", $pdfTag, $mcid);
    }
    
    /**
     * Close the current BDC block (if any)
     * 
     * Generates PDF operator: EMC
     * 
     * @return string PDF code to output (EMC operator or empty string)
     */
    public function closeBDC(): string
    {
        // No active BDC → nothing to close
        if ($this->activeBDCFrame === null) {
            return '';
        }
        
        // Decrement nesting depth
        $this->bdcDepth--;
        
        // Log state change
        SimpleLogger::log("bdc_state_manager", __METHOD__, 
            sprintf("Closed BDC for frame %s (depth=%d)", 
                $this->activeBDCFrame['semanticId'], $this->bdcDepth)
        );
        
        // Clear active BDC state
        $this->activeBDCFrame = null;
        
        // Generate PDF operator
        return "EMC\n";
    }
    
    /**
     * Close previous BDC and open new one (atomic operation)
     * 
     * This is a convenience method for the common pattern:
     * 1. Close previous BDC (if exists)
     * 2. Open new BDC immediately
     * 
     * @param string $pdfTag PDF structure tag for new BDC
     * @param int $mcid Marked Content ID for new BDC
     * @param string $semanticId Semantic element ID for new BDC
     * @return string PDF code with [EMC]\nBDC
     */
    public function closePreviousAndOpenNew(string $pdfTag, int $mcid, string $semanticId): string
    {
        $pdfCode = '';
        
        // Close previous (if exists)
        if ($this->activeBDCFrame !== null) {
            $pdfCode .= $this->closeBDC();
        }
        
        // Open new
        $pdfCode .= $this->openBDC($pdfTag, $mcid, $semanticId);
        
        return $pdfCode;
    }
    
    /**
     * Force close all open BDC blocks
     * 
     * Used in emergency situations (e.g., page end, document close)
     * where we need to ensure all tags are properly closed.
     * 
     * @return string PDF code with EMC operators
     */
    public function forceCloseAll(): string
    {
        $pdfCode = '';
        
        // Close all nested BDC blocks
        while ($this->bdcDepth > 0) {
            $pdfCode .= $this->closeBDC();
        }
        
        return $pdfCode;
    }
    
    /**
     * Reset state (for testing or emergency recovery)
     */
    public function reset(): void
    {
        $this->activeBDCFrame = null;
        $this->bdcDepth = 0;
        
        SimpleLogger::log("bdc_state_manager", __METHOD__, "State reset");
    }
}
