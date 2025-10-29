<?php
/**
 * TreeLogger - Compact tree-structured logging for tagging operations
 * 
 * Creates compact tree representations with depth:
 * 
 * Example output:
 * 📄 Page 2
 * ├─ 🟢 TEXT [OPEN_NEW] frame=text_123 → /P (MCID=5)
 * │  └─ 🟢 BDC: /P <</MCID 5>> BDC
 * ├─ 🟠 DRAW [INTERRUPT] border → /P (MCID=5)
 * │  ├─ 🔴 EMC → Close semantic
 * │  ├─ 🟡 BMC → Artifact
 * │  ├─    ... drawing ...
 * │  ├─ 🔴 EMC → Close artifact
 * │  └─ 🟢 BDC → /P (re-open)
 * └─ 🔵 TEXT [CONTINUE] frame=text_123
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

use Dompdf\SimpleLogger;

require_once __DIR__ . '/../../../src/SimpleLogger.php';

class TreeLogger
{
    /** Tree characters */
    private const BRANCH = '├─';
    private const LAST = '└─';
    private const VERTICAL = '│';
    private const SPACE = '  ';
    
    /** Current page number for grouping */
    private static int $currentPage = 0;
    
    /** Operation counter for current page */
    private static int $operationCounter = 0;
    
    /**
     * Log a text operation with compact tree structure
     * 
     * @param string $decision TextDecision name (OPEN_NEW, CONTINUE, ARTIFACT)
     * @param string|null $frameId Frame ID being processed (can be null for edge cases)
     * @param string|null $nodeId Node ID from SemanticTree (can differ from frameId)
     * @param string|null $pdfTag PDF tag (e.g., /P, /H1) if semantic
     * @param int|null $mcid MCID if semantic BDC
     * @param int $page Current page number
     * @param string $output The actual PDF operators generated
     * @param TaggingState $stateBeforeOperation State BEFORE the operation (for accurate logging)
     * @return void
     */
    public static function logTextOperation(
        string $decision,
        ?string $frameId,
        ?string $nodeId,
        ?string $pdfTag,
        ?int $mcid,
        int $page,
        string $output,
        TaggingState $stateBeforeOperation
    ): void {
        self::checkPageChange($page);
        
        $tree = self::buildCompactTextTree($decision, $frameId, $nodeId, $pdfTag, $mcid, $page, $output, $stateBeforeOperation);
        SimpleLogger::log("pdf_backend_tagging_logs", "\nTEXT", "\n$tree");
        
        self::$operationCounter++;
    }
    
    /**
     * Log a drawing operation with compact tree structure
     * 
     * @param string $decision DrawingDecision name (INTERRUPT, CONTINUE, ARTIFACT)
     * @param string|null $frameId Frame ID being processed (can be null for edge cases)
     * @param string|null $nodeId Node ID from SemanticTree (can differ from frameId)
     * @param string|null $pdfTag PDF tag if re-opening
     * @param int|null $mcid MCID if re-opening
     * @param int $page Current page number
     * @param string $output The actual PDF operators generated
     * @param TaggingState $stateBeforeOperation State BEFORE the operation (for accurate logging)
     * @return void
     */
    public static function logDrawingOperation(
        string $decision,
        ?string $frameId,
        ?string $nodeId,
        ?string $pdfTag,
        ?int $mcid,
        int $page,
        string $output,
        TaggingState $stateBeforeOperation
    ): void {
        self::checkPageChange($page);
        
        $tree = self::buildCompactDrawingTree($decision, $frameId, $nodeId, $pdfTag, $mcid, $page, $output, $stateBeforeOperation);
        SimpleLogger::log("pdf_backend_tagging_logs", "\nDRAW", "\n$tree");
        
        self::$operationCounter++;
    }
    
    /**
     * Check if page changed and log page header
     */
    private static function checkPageChange(int $page): void
    {
        if ($page !== self::$currentPage) {
            self::$currentPage = $page;
            self::$operationCounter = 0;
            SimpleLogger::log("pdf_backend_tagging_logs", "PAGE", "\n📄 Page {$page}");
        }
    }
    
    /**
     * Build compact tree for text operation
     */
    private static function buildCompactTextTree(
        string $decision,
        ?string $frameId,
        ?string $nodeId,
        ?string $pdfTag,
        ?int $mcid,
        int $page,
        string $output,
        TaggingState $stateBeforeOperation
    ): string {
        $icon = self::getDecisionIcon($decision);
        
        // Build info line with all context (skip tag for ARTIFACT decision)
        $info = self::buildContextInfo($frameId, $nodeId, $pdfTag, $mcid, $stateBeforeOperation, $decision);
        
        // Main line: 🟢 TEXT [OPEN_NEW] frame=6 node=6 state=NONE → P (MCID=0) | page 1
        $mainLine = "{$icon} TEXT [{$decision}] {$info} | page {$page}";
        
        $lines = [self::BRANCH . ' ' . $mainLine];
        
        // Add output lines if present
        if (!empty(trim($output))) {
            $outputLines = self::formatOutput($output);
            foreach ($outputLines as $i => $line) {
                $isLast = ($i === count($outputLines) - 1);
                $prefix = self::VERTICAL . self::SPACE . ($isLast ? self::LAST : self::BRANCH) . ' ';
                $lines[] = $prefix . $line;
            }
        } else {
            $lines[] = self::VERTICAL . self::SPACE . self::LAST . ' (no output)';
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Build compact tree for drawing operation
     */
    private static function buildCompactDrawingTree(
        string $decision,
        ?string $frameId,
        ?string $nodeId,
        ?string $pdfTag,
        ?int $mcid,
        int $page,
        string $output,
        TaggingState $stateBeforeOperation
    ): string {
        $icon = self::getDecisionIcon($decision);
        
        // Build info line with all context (skip tag for ARTIFACT decision)
        $info = self::buildContextInfo($frameId, $nodeId, $pdfTag, $mcid, $stateBeforeOperation, $decision);
        
        // Main line: 🟠 DRAW [INTERRUPT] frame=12 node=12 state=SEMANTIC → Div (MCID=0) | page 1
        $mainLine = "{$icon} DRAW [{$decision}] {$info} | page {$page}";
        
        $lines = [self::BRANCH . ' ' . $mainLine];
        
        // Add output lines if present
        if (!empty(trim($output))) {
            $outputLines = self::formatOutput($output, $decision === 'INTERRUPT');
            foreach ($outputLines as $i => $line) {
                $isLast = ($i === count($outputLines) - 1);
                $prefix = self::VERTICAL . self::SPACE . ($isLast ? self::LAST : self::BRANCH) . ' ';
                $lines[] = $prefix . $line;
            }
        } else {
            $lines[] = self::VERTICAL . self::SPACE . self::LAST . ' (no output)';
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Build context info string with all relevant information
     * 
     * Format: frame=6 node=6 state=SEMANTIC → Div (MCID=1)
     * For ARTIFACT decision: frame=6 node=6 state=SEMANTIC (skips redundant "→ Artifact")
     * 
     * @param string|null $frameId Current frame ID
     * @param string|null $nodeId Current node ID from tree
     * @param string|null $pdfTag PDF tag for this operation
     * @param int|null $mcid MCID for this operation
     * @param TaggingState $stateBeforeOperation State BEFORE the operation
     * @param string $decision Decision name (to avoid redundancy with ARTIFACT)
     * @return string Formatted context string
     */
    private static function buildContextInfo(
        ?string $frameId,
        ?string $nodeId,
        ?string $pdfTag,
        ?int $mcid,
        TaggingState $stateBeforeOperation,
        string $decision
    ): string {
        $parts = [];
        $edgeCase = 0;

        // Check for edge cases (e.g., empty frame ID)
        if ($frameId === null) {
            $edgeCase = 1;
        }
        if ($frameId !== null && $nodeId === null) {
            $edgeCase = 2;
        }
        // Frame ID (always show, even if null)
        $parts[] = 'frame=' . ($frameId ?? 'null');
        
        // Node ID (always show, even if null or same as frame)
        $parts[] = 'node=' . ($nodeId ?? 'null');
        
        // State BEFORE operation (critical for understanding what's happening)
        $parts[] = 'state=' . $stateBeforeOperation->name;

        // Edge case handling
        if ($edgeCase === 1) {
            $parts[] = '(EDGE CASE 1: no frame ID)';
        } elseif ($edgeCase === 2) {
            $parts[] = '(EDGE CASE 2: node ID missing)';
        }
        
        // PDF tag and MCID for this operation (if present)
        // Skip tag if decision is ARTIFACT (redundant: [ARTIFACT] → Artifact)
        if ($pdfTag !== null && $decision !== 'ARTIFACT') {
            $tagInfo = "→ {$pdfTag}";
            if ($mcid !== null) {
                $tagInfo .= " (MCID={$mcid})";
            }
            $parts[] = $tagInfo;
        }
        
        return implode(' ', $parts);
    }
    
    /**
     * Format PDF output operators in a readable way
     * 
     * @param string $output Raw PDF operators
     * @param bool $annotate Whether to annotate INTERRUPT operations (not used anymore)
     * @return array Formatted lines (single line with full output)
     */
    private static function formatOutput(string $output, bool $annotate = false): array
    {
        // Simply return the output as-is, all whitespace collapsed to single space
        $cleaned = trim(preg_replace('/\s+/', ' ', $output));
        return [$cleaned];
    }
    
    /**
     * Get icon for decision type
     */
    private static function getDecisionIcon(string $decision): string
    {
        return match($decision) {
            'OPEN_NEW' => '🟢',
            'CLOSE_AND_OPEN_NEW' => '🟢',
            'CONTINUE' => '🔵',
            'ARTIFACT' => '🟡',
            'CLOSE_BDC_ARTIFACT_REOPEN' => '🟠',
            default => '⚪'
        };
    }
    
    /**
     * Shorten frame ID for compact display
     * 
     * Examples:
     * - text_frame_p_123 → text_p_123
     * - img_frame_figure_456 → img_figure_456
     */
    private static function shortenFrameId(string $frameId): string
    {
        // Remove _frame_ for compactness
        $short = str_replace('_frame_', '_', $frameId);
        
        // If still too long, truncate
        if (strlen($short) > 20) {
            return substr($short, 0, 17) . '...';
        }
        
        return $short;
    }
    
    /**
     * Truncate long strings
     */
    private static function truncate(string $str, int $max): string
    {
        if (strlen($str) <= $max) {
            return $str;
        }
        return substr($str, 0, $max - 3) . '...';
    }
}
