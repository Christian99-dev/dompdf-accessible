<?php
/**
 * TagOps - Static PDF Operator Generator for PDF/UA Tagging
 * 
 * Provides clean, reusable methods for generating PDF operators
 * related to marked content (BDC/EMC) and artifacts.
 * 
 * This class eliminates hardcoded PDF operator strings throughout
 * the codebase and provides a single source of truth for PDF/UA compliance.
 * 
 * @package dompdf-accessible
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
class TagOps
{
    /**
     * Generate BDC (Begin Marked Content) operator with properties
     * 
     * Creates a tagged content block opener with MCID and optional properties.
     * 
     * Example output:
     *   /P <</MCID 5>> BDC
     *   /H1 <</MCID 3 /Lang (en-US)>> BDC
     * 
     * @param string $tag PDF structure tag (P, H1, Table, etc.)
     * @param int $mcid Marked Content ID (unique per page)
     * @param array $props Optional properties (Lang, ActualText, Alt, etc.)
     * @return string PDF BDC operator with newline
     */
    public static function bdcOpen(string $tag, int $mcid, array $props = []): string
    {
        // Start with required MCID
        $properties = sprintf('/MCID %d', $mcid);
        
        // Add optional properties if provided
        if (isset($props['Lang']) && $props['Lang'] !== '') {
            $properties .= sprintf(' /Lang (%s)', self::escape($props['Lang']));
        }
        
        if (isset($props['ActualText']) && $props['ActualText'] !== '') {
            $properties .= sprintf(' /ActualText (%s)', self::escape($props['ActualText']));
        }
        
        if (isset($props['Alt']) && $props['Alt'] !== '') {
            $properties .= sprintf(' /Alt (%s)', self::escape($props['Alt']));
        }
        
        return sprintf("/%s <<%s>> BDC\n", $tag, $properties);
    }
    
    /**
     * Generate EMC (End Marked Content) operator
     * 
     * Closes a BDC block (tagged content or artifact).
     * 
     * Example output:
     *   EMC
     * 
     * @return string PDF EMC operator with newline
     */
    public static function emc(): string
    {
        return "EMC\n";
    }
    
    /**
     * Generate Artifact BMC (Begin Marked Content) operator
     * 
     * Marks content as artifact (decorative, not part of logical structure).
     * 
     * Example output:
     *   /Artifact BMC
     * 
     * @return string PDF Artifact BMC operator with newline
     */
    public static function artifactOpen(): string
    {
        return "/Artifact BMC\n";
    }
    
    /**
     * Generate Artifact close operator (alias for emc)
     * 
     * Semantically identical to emc(), but provides clearer intent
     * when closing artifact blocks.
     * 
     * @return string PDF EMC operator with newline
     */
    public static function artifactClose(): string
    {
        return self::emc();
    }
    
    /**
     * Wrap content with Artifact markers
     * 
     * Convenience method for wrapping PDF operators as artifacts.
     * 
     * Example:
     *   TagOps::wrapAsArtifact('/GS1 gs')
     *   // Returns: "/Artifact BMC\n/GS1 gs\nEMC\n"
     * 
     * @param string $content PDF operators to wrap
     * @return string Wrapped content with Artifact BMC...EMC
     */
    public static function wrapAsArtifact(string $content): string
    {
        return self::artifactOpen() . $content . self::artifactClose();
    }
    
    /**
     * Escape special characters for PDF strings
     * 
     * Escapes characters that have special meaning in PDF strings:
     * - Backslash (\)
     * - Parentheses (( and ))
     * - Carriage return, line feed, tab, backspace, form feed
     * 
     * @param string $text Text to escape
     * @return string Escaped text safe for PDF string literals
     */
    private static function escape(string $text): string
    {
        // Replace special PDF characters
        $text = str_replace('\\', '\\\\', $text);  // Backslash first!
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace("\r", '\\r', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\t", '\\t', $text);
        $text = str_replace("\b", '\\b', $text);
        $text = str_replace("\f", '\\f', $text);
        
        return $text;
    }
}
