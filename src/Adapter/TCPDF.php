<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Dompdf\Adapter;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\SimpleLogger;
use TCPDF as TCPDFLibrary;

/**
 * TCPDF rendering interface
 *
 * This is a simple TCPDF adapter for dompdf that implements the Canvas interface.
 * It provides basic PDF generation functionality using the TCPDF library.
 *
 * @package dompdf
 */
class TCPDF implements Canvas
{
    /**
     * Dimensions of paper sizes in points
     *
     * @var array
     */
    static $PAPER_SIZES = [
        "4a0" => [0.0, 0.0, 4767.87, 6740.79],
        "2a0" => [0.0, 0.0, 3370.39, 4767.87],
        "a0" => [0.0, 0.0, 2383.94, 3370.39],
        "a1" => [0.0, 0.0, 1683.78, 2383.94],
        "a2" => [0.0, 0.0, 1190.55, 1683.78],
        "a3" => [0.0, 0.0, 841.89, 1190.55],
        "a4" => [0.0, 0.0, 595.28, 841.89],
        "a5" => [0.0, 0.0, 419.53, 595.28],
        "a6" => [0.0, 0.0, 297.64, 419.53],
        "a7" => [0.0, 0.0, 209.76, 297.64],
        "a8" => [0.0, 0.0, 147.40, 209.76],
        "a9" => [0.0, 0.0, 104.88, 147.40],
        "a10" => [0.0, 0.0, 73.70, 104.88],
        "b0" => [0.0, 0.0, 2834.65, 4008.19],
        "b1" => [0.0, 0.0, 2004.09, 2834.65],
        "b2" => [0.0, 0.0, 1417.32, 2004.09],
        "b3" => [0.0, 0.0, 1000.63, 1417.32],
        "b4" => [0.0, 0.0, 708.66, 1000.63],
        "b5" => [0.0, 0.0, 498.90, 708.66],
        "b6" => [0.0, 0.0, 354.33, 498.90],
        "b7" => [0.0, 0.0, 249.45, 354.33],
        "b8" => [0.0, 0.0, 175.75, 249.45],
        "b9" => [0.0, 0.0, 124.72, 175.75],
        "b10" => [0.0, 0.0, 87.87, 124.72],
        "c0" => [0.0, 0.0, 2599.37, 3676.54],
        "c1" => [0.0, 0.0, 1836.85, 2599.37],
        "c2" => [0.0, 0.0, 1298.27, 1836.85],
        "c3" => [0.0, 0.0, 918.43, 1298.27],
        "c4" => [0.0, 0.0, 649.13, 918.43],
        "c5" => [0.0, 0.0, 459.21, 649.13],
        "c6" => [0.0, 0.0, 323.15, 459.21],
        "c7" => [0.0, 0.0, 229.61, 323.15],
        "c8" => [0.0, 0.0, 161.57, 229.61],
        "c9" => [0.0, 0.0, 113.39, 161.57],
        "c10" => [0.0, 0.0, 79.37, 113.39],
        "ra0" => [0.0, 0.0, 2437.80, 3458.27],
        "ra1" => [0.0, 0.0, 1729.13, 2437.80],
        "ra2" => [0.0, 0.0, 1218.90, 1729.13],
        "ra3" => [0.0, 0.0, 864.57, 1218.90],
        "ra4" => [0.0, 0.0, 609.45, 864.57],
        "sra0" => [0.0, 0.0, 2551.18, 3628.35],
        "sra1" => [0.0, 0.0, 1814.17, 2551.18],
        "sra2" => [0.0, 0.0, 1275.59, 1814.17],
        "sra3" => [0.0, 0.0, 907.09, 1275.59],
        "sra4" => [0.0, 0.0, 637.80, 907.09],
        "letter" => [0.0, 0.0, 612.00, 792.00],
        "half-letter" => [0.0, 0.0, 396.00, 612.00],
        "legal" => [0.0, 0.0, 612.00, 1008.00],
        "ledger" => [0.0, 0.0, 1224.00, 792.00],
        "tabloid" => [0.0, 0.0, 792.00, 1224.00],
        "executive" => [0.0, 0.0, 521.86, 756.00],
        "folio" => [0.0, 0.0, 612.00, 936.00],
        "commercial #10 envelope" => [0.0, 0.0, 684.00, 297.00],
        "catalog #10 1/2 envelope" => [0.0, 0.0, 648.00, 864.00],
        "8.5x11" => [0.0, 0.0, 612.00, 792.00],
        "8.5x14" => [0.0, 0.0, 612.00, 1008.00],
        "11x17" => [0.0, 0.0, 792.00, 1224.00],
    ];

    /**
     * @var TCPDFLibrary
     */
    protected $_pdf;

    /**
     * @var Dompdf
     */
    protected $_dompdf;

    /**
     * @var float
     */
    protected $_width;

    /**
     * @var float
     */
    protected $_height;

    /**
     * @var array
     */
    protected $_page_texts = [];

    /**
     * @param string|float[] $paper       The paper size to use as either a standard paper size (see {@link Dompdf\Adapter\CPDF::$PAPER_SIZES})
     *                                    or an array of the form `[x1, y1, x2, y2]` (typically `[0, 0, width, height]`).
     * @param string         $orientation The paper orientation, either `portrait` or `landscape`.
     * @param Dompdf|null    $dompdf      The Dompdf instance.
     */
    public function __construct($paper = "letter", string $orientation = "portrait", ?Dompdf $dompdf = null) {
        SimpleLogger::log('tcpdf_logs', '1. ' . __FUNCTION__, "Constructing TCPDF with paper: {$paper}, orientation: {$orientation}");
        
        if (is_array($paper)) {
            $size = array_map("floatval", $paper);
        } else {
            $paper = strtolower($paper);
            $size = self::$PAPER_SIZES[$paper] ?? self::$PAPER_SIZES["letter"];
        }

        if (strtolower($orientation) === "landscape") {
            [$size[2], $size[3]] = [$size[3], $size[2]];
        }

        if ($dompdf === null) {
            $this->_dompdf = new Dompdf();
        } else {
            $this->_dompdf = $dompdf;
        }

        // Convert to TCPDF format - TCPDF uses width and height in mm
        $width_mm = ($size[2] - $size[0]) * 0.352778; // Convert points to mm
        $height_mm = ($size[3] - $size[1]) * 0.352778; // Convert points to mm

        // Initialize TCPDF
        $tcpdf_orientation = strtolower($orientation) === "landscape" ? 'L' : 'P';
        $this->_pdf = new TCPDFLibrary($tcpdf_orientation, 'pt', [$size[2] - $size[0], $size[3] - $size[1]], true, 'UTF-8', false);

        // Set document information
        $this->_pdf->SetCreator(sprintf("%s + TCPDF", $this->_dompdf->version ?? 'dompdf'));
        $this->_pdf->SetAuthor('dompdf + TCPDF');
        $this->_pdf->SetTitle('');
        $this->_pdf->SetSubject('');
        $this->_pdf->SetKeywords('');

        // Remove default header/footer
        $this->_pdf->setPrintHeader(false);
        $this->_pdf->setPrintFooter(false);

        // Set margins to 0
        $this->_pdf->SetMargins(0, 0, 0);
        $this->_pdf->SetAutoPageBreak(false, 0);

        // Add first page
        $this->_pdf->AddPage();

        $this->_width = $size[2] - $size[0];
        $this->_height = $size[3] - $size[1];
    }

    /**
     * Maps dompdf font names to TCPDF font names
     *
     * @param string $font
     * @return string
     */
    protected function _mapFontToTCPDF($font) {
        // Basic font mapping - this can be expanded as needed
        $font_map = [
            'helvetica' => 'helvetica',
            'times' => 'times',
            'courier' => 'courier',
            'symbol' => 'symbol',
            'zapfdingbats' => 'zapfdingbats',
        ];
        
        // Extract base font name (remove path and extension)
        $base_font = basename($font, '.ttf');
        $base_font = basename($base_font, '.otf');
        $base_font = strtolower($base_font);
        
        // Return mapped font or default to helvetica
        return $font_map[$base_font] ?? 'helvetica';
    }

    /**
     * Applies page text with placeholder replacement
     *
     * @param string $text
     * @param float  $x
     * @param float  $y
     * @param string $font
     * @param float  $size
     * @param array  $color
     * @param float  $word_space
     * @param float  $char_space
     * @param float  $angle
     */
    protected function _applyPageText($text, $x, $y, $font, $size, $color, $word_space, $char_space, $angle) {
        // Replace placeholders
        $page_num = $this->_pdf->getPage();
        $page_count = $this->_pdf->getNumPages();
        
        $processed_text = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], [$page_num, $page_count], $text);
        
        // Use the regular text method to render
        $this->text($x, $y, $processed_text, $font, $size, $color, $word_space, $char_space, $angle);
    }

    /**
     * @return Dompdf
     */
    function get_dompdf() {
        SimpleLogger::log('tcpdf_logs', '2. ' . __FUNCTION__, "Returning dompdf instance");
        return $this->_dompdf;
    }

    /**
     * Returns the current page number
     *
     * @return int
     */
    function get_page_number() {
        SimpleLogger::log('tcpdf_logs', '3. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Returns the total number of pages in the document
     *
     * @return int
     */
    function get_page_count() {
        SimpleLogger::log('tcpdf_logs', '4. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Sets the total number of pages
     *
     * @param int $count
     */
    function set_page_count($count) {
        SimpleLogger::log('tcpdf_logs', '5. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Draws a line from x1,y1 to x2,y2
     *
     * See {@link Cpdf::setLineStyle()} for a description of the format of the
     * $style and $cap parameters (aka dash and cap).
     *
     * @param float  $x1
     * @param float  $y1
     * @param float  $x2
     * @param float  $y2
     * @param array  $color Color array in the format `[r, g, b, "alpha" => alpha]`
     *                      where r, g, b, and alpha are float values between 0 and 1
     * @param float  $width
     * @param array  $style
     * @param string $cap   `butt`, `round`, or `square`
     */
    function line($x1, $y1, $x2, $y2, $color, $width, $style = [], $cap = "butt") {
        SimpleLogger::log('tcpdf_logs', '7. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Draws an arc
     *
     * See {@link Cpdf::setLineStyle()} for a description of the format of the
     * $style and $cap parameters (aka dash and cap).
     *
     * @param float  $x      X coordinate of the arc
     * @param float  $y      Y coordinate of the arc
     * @param float  $r1     Radius 1
     * @param float  $r2     Radius 2
     * @param float  $astart Start angle in degrees
     * @param float  $aend   End angle in degrees
     * @param array  $color  Color array in the format `[r, g, b, "alpha" => alpha]`
     *                       where r, g, b, and alpha are float values between 0 and 1
     * @param float  $width
     * @param array  $style
     * @param string $cap   `butt`, `round`, or `square`
     */
    function arc($x, $y, $r1, $r2, $astart, $aend, $color, $width, $style = [], $cap = "butt") {
        SimpleLogger::log('tcpdf_logs', '8. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Draws a rectangle at x1,y1 with width w and height h
     *
     * See {@link Cpdf::setLineStyle()} for a description of the format of the
     * $style and $cap parameters (aka dash and cap).
     *
     * @param float  $x1
     * @param float  $y1
     * @param float  $w
     * @param float  $h
     * @param array  $color  Color array in the format `[r, g, b, "alpha" => alpha]`
     *                       where r, g, b, and alpha are float values between 0 and 1
     * @param float  $width
     * @param array  $style
     * @param string $cap   `butt`, `round`, or `square`
     */
    function rectangle($x1, $y1, $w, $h, $color, $width, $style = [], $cap = "butt") {
        SimpleLogger::log('tcpdf_logs', '9. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Draws a filled rectangle at x1,y1 with width w and height h
     *
     * @param float $x1
     * @param float $y1
     * @param float $w
     * @param float $h
     * @param array $color Color array in the format `[r, g, b, "alpha" => alpha]`
     *                     where r, g, b, and alpha are float values between 0 and 1
     */
    function filled_rectangle($x1, $y1, $w, $h, $color) {
        SimpleLogger::log('tcpdf_logs', '10. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Starts a clipping rectangle at x1,y1 with width w and height h
     *
     * @param float $x1
     * @param float $y1
     * @param float $w
     * @param float $h
     */
    function clipping_rectangle($x1, $y1, $w, $h) {
        SimpleLogger::log('tcpdf_logs', '14. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Starts a rounded clipping rectangle at x1,y1 with width w and height h
     *
     * @param float $x1
     * @param float $y1
     * @param float $w
     * @param float $h
     * @param float $tl
     * @param float $tr
     * @param float $br
     * @param float $bl
     */
    function clipping_roundrectangle($x1, $y1, $w, $h, $tl, $tr, $br, $bl) {
        SimpleLogger::log('tcpdf_logs', '15. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Starts a clipping polygon
     *
     * @param float[] $points
     */
    public function clipping_polygon(array $points): void {
        SimpleLogger::log('tcpdf_logs', '16. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Ends the last clipping shape
     */
    function clipping_end() {
        SimpleLogger::log('tcpdf_logs', '17. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Processes a callback on every page.
     *
     * The callback function receives the four parameters `int $pageNumber`,
     * `int $pageCount`, `Canvas $canvas`, and `FontMetrics $fontMetrics`, in
     * that order.
     *
     * This function can be used to add page numbers to all pages after the
     * first one, for example.
     *
     * @param callable $callback The callback function to process on every page
     */
    public function page_script($callback): void {
        SimpleLogger::log('tcpdf_logs', '31. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Writes text at the specified x and y coordinates on every page.
     *
     * The strings '{PAGE_NUM}' and '{PAGE_COUNT}' are automatically replaced
     * with their current values.
     *
     * @param float  $x
     * @param float  $y
     * @param string $text       The text to write
     * @param string $font       The font file to use
     * @param float  $size       The font size, in points
     * @param array  $color      Color array in the format `[r, g, b, "alpha" => alpha]`
     *                           where r, g, b, and alpha are float values between 0 and 1
     * @param float  $word_space Word spacing adjustment
     * @param float  $char_space Char spacing adjustment
     * @param float  $angle      Angle to write the text at, measured clockwise starting from the x-axis
     */
    public function page_text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0) {
        SimpleLogger::log('tcpdf_logs', '26. ' . __FUNCTION__, "Setting up page text: '{$text}' at ({$x}, {$y})");
        
        // Store page text configuration for later use
        if (!isset($this->_page_texts)) {
            $this->_page_texts = [];
        }
        
        $this->_page_texts[] = [
            'x' => $x,
            'y' => $y,
            'text' => $text,
            'font' => $font,
            'size' => $size,
            'color' => $color,
            'word_space' => $word_space,
            'char_space' => $char_space,
            'angle' => $angle
        ];
        
        // For TCPDF, we need to set up a header/footer or use the page script functionality
        // This is a simplified implementation - in practice, you might want to use TCPDF's
        // header/footer callbacks for better integration
        
        // Apply page text to current page immediately
        $this->_applyPageText($text, $x, $y, $font, $size, $color, $word_space, $char_space, $angle);
    }

    /**
     * Draws a line at the specified coordinates on every page.
     *
     * @param float $x1
     * @param float $y1
     * @param float $x2
     * @param float $y2
     * @param array $color Color array in the format `[r, g, b, "alpha" => alpha]`
     *                     where r, g, b, and alpha are float values between 0 and 1
     * @param float $width
     * @param array $style
     */
    public function page_line($x1, $y1, $x2, $y2, $color, $width, $style = []) {
        SimpleLogger::log('tcpdf_logs', '13. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Save current state
     */
    function save() {
        SimpleLogger::log('tcpdf_logs', '18. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Restore last state
     */
    function restore() {
        SimpleLogger::log('tcpdf_logs', '19. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Rotate
     *
     * @param float $angle angle in degrees for counter-clockwise rotation
     * @param float $x     Origin abscissa
     * @param float $y     Origin ordinate
     */
    function rotate($angle, $x, $y) {
        SimpleLogger::log('tcpdf_logs', '20. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Skew
     *
     * @param float $angle_x
     * @param float $angle_y
     * @param float $x       Origin abscissa
     * @param float $y       Origin ordinate
     */
    function skew($angle_x, $angle_y, $x, $y) {
        SimpleLogger::log('tcpdf_logs', '21. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Scale
     *
     * @param float $s_x scaling factor for width as percent
     * @param float $s_y scaling factor for height as percent
     * @param float $x   Origin abscissa
     * @param float $y   Origin ordinate
     */
    function scale($s_x, $s_y, $x, $y) {
        SimpleLogger::log('tcpdf_logs', '22. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Translate
     *
     * @param float $t_x movement to the right
     * @param float $t_y movement to the bottom
     */
    function translate($t_x, $t_y) {
        SimpleLogger::log('tcpdf_logs', '23. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Transform
     *
     * @param float $a
     * @param float $b
     * @param float $c
     * @param float $d
     * @param float $e
     * @param float $f
     */
    function transform($a, $b, $c, $d, $e, $f) {
        SimpleLogger::log('tcpdf_logs', '24. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Draws a polygon
     *
     * The polygon is formed by joining all the points stored in the $points
     * array.  $points has the following structure:
     * ```
     * array(0 => x1,
     *       1 => y1,
     *       2 => x2,
     *       3 => y2,
     *       ...
     *       )
     * ```
     *
     * See {@link Cpdf::setLineStyle()} for a description of the format of the
     * $style parameter (aka dash).
     *
     * @param array $points
     * @param array $color  Color array in the format `[r, g, b, "alpha" => alpha]`
     *                      where r, g, b, and alpha are float values between 0 and 1
     * @param float $width
     * @param array $style
     * @param bool  $fill   Fills the polygon if true
     */
    function polygon($points, $color, $width = null, $style = [], $fill = false) {
        SimpleLogger::log('tcpdf_logs', '11. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Draws a circle at $x,$y with radius $r
     *
     * See {@link Cpdf::setLineStyle()} for a description of the format of the
     * $style parameter (aka dash).
     *
     * @param float $x
     * @param float $y
     * @param float $r
     * @param array $color Color array in the format `[r, g, b, "alpha" => alpha]`
     *                     where r, g, b, and alpha are float values between 0 and 1
     * @param float $width
     * @param array $style
     * @param bool  $fill  Fills the circle if true
     */
    function circle($x, $y, $r, $color, $width = null, $style = [], $fill = false) {
        SimpleLogger::log('tcpdf_logs', '12. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Add an image to the pdf.
     *
     * The image is placed at the specified x and y coordinates with the
     * given width and height.
     *
     * @param string $img        The path to the image
     * @param float  $x          X position
     * @param float  $y          Y position
     * @param float  $w          Width
     * @param float  $h          Height
     * @param string $resolution The resolution of the image
     */
    function image($img, $x, $y, $w, $h, $resolution = "normal") {
        SimpleLogger::log('tcpdf_logs', '33. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Writes text at the specified x and y coordinates
     *
     * @param float  $x
     * @param float  $y
     * @param string $text        The text to write
     * @param string $font        The font file to use
     * @param float  $size        The font size, in points
     * @param array  $color       Color array in the format `[r, g, b, "alpha" => alpha]`
     *                            where r, g, b, and alpha are float values between 0 and 1
     * @param float  $word_space  Word spacing adjustment
     * @param float  $char_space  Char spacing adjustment
     * @param float  $angle       Angle to write the text at, measured clockwise starting from the x-axis
     */
    function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0) {
        SimpleLogger::log('tcpdf_logs', '25. ' . __FUNCTION__, "Writing text: '{$text}' at ({$x}, {$y}) with size {$size}");
        
        // Convert color from 0-1 range to 0-255 range for TCPDF
        $r = isset($color[0]) ? (int)($color[0] * 255) : 0;
        $g = isset($color[1]) ? (int)($color[1] * 255) : 0;
        $b = isset($color[2]) ? (int)($color[2] * 255) : 0;
        
        // Set text color
        $this->_pdf->SetTextColor($r, $g, $b);
        
        // Set font - use a default font if $font is not a recognized TCPDF font
        // For now, we'll use helvetica as default, but this could be improved
        // to map font files to TCPDF font names
        $tcpdf_font = $this->_mapFontToTCPDF($font);
        $this->_pdf->SetFont($tcpdf_font, '', $size);
        
        // Apply character and word spacing if specified
        if ($char_space != 0.0) {
            // TCPDF doesn't have direct character spacing, we can simulate with tracking
            // This is an approximation
        }
        
        // Save current transformation matrix if we need to rotate
        if ($angle != 0.0) {
            $this->_pdf->StartTransform();
            // TCPDF expects angle in degrees, convert from radians if needed
            // Assuming angle is already in degrees as per documentation
            $this->_pdf->Rotate($angle, $x, $y);
        }
        
        // Convert coordinates - TCPDF uses different coordinate system
        // TCPDF's origin is top-left, dompdf uses bottom-left
        $tcpdf_y = $this->_height - $y;
        
        // Write the text
        $this->_pdf->SetXY($x, $tcpdf_y);
        $this->_pdf->Cell(0, 0, $text, 0, 0, 'L', false, '', 0, false, 'T', 'T');
        
        // Restore transformation matrix if we rotated
        if ($angle != 0.0) {
            $this->_pdf->StopTransform();
        }
    }

    /**
     * Add a named destination (similar to <a name="foo">...</a> in html)
     *
     * @param string $anchorname The name of the named destination
     */
    function add_named_dest($anchorname) {
        SimpleLogger::log('tcpdf_logs', '34. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Add a link to the pdf
     *
     * @param string $url    The url to link to
     * @param float  $x      The x position of the link
     * @param float  $y      The y position of the link
     * @param float  $width  The width of the link
     * @param float  $height The height of the link
     */
    function add_link($url, $x, $y, $width, $height) {
        SimpleLogger::log('tcpdf_logs', '35. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Add meta information to the PDF.
     *
     * @param string $label Label of the value (Creator, Producer, etc.)
     * @param string $value The text to set
     */
    public function add_info(string $label, string $value): void {
        SimpleLogger::log('tcpdf_logs', '36. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Determines if the font supports the given character
     *
     * @param string $font The font file to use
     * @param string $char The character to check
     *
     * @return bool
     */
    function font_supports_char(string $font, string $char): bool {        
        SimpleLogger::log('tcpdf_logs', '27. ' . __FUNCTION__, "Checking font '{$font}' for character '{$char}'");
        
        // Map font to TCPDF font name
        $tcpdf_font = $this->_mapFontToTCPDF($font);
        
        try {
            // Try to set the font
            $this->_pdf->SetFont($tcpdf_font, '', 12);
            
            // TCPDF doesn't have a direct method to check character support
            // We can try to get the character width - if it returns 0 or fails, 
            // the character might not be supported
            $width = $this->_pdf->GetStringWidth($char);
            
            // If width is 0, the character might not be supported
            // However, this is not foolproof as some valid characters might have 0 width
            // For a more robust implementation, you might need to check the font's character map
            
            return $width >= 0; // Return true if we can measure the character
            
        } catch (\Exception $e) {
            // If setting the font or measuring fails, assume character is not supported
            SimpleLogger::log('tcpdf_logs', '27. ' . __FUNCTION__, "Font check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculates text size, in points
     *
     * @param string $text         The text to be sized
     * @param string $font         The font file to use
     * @param float  $size         The font size, in points
     * @param float  $word_spacing Word spacing, if any
     * @param float  $char_spacing Char spacing, if any
     *
     * @return float
     */
    function get_text_width($text, $font, $size, $word_spacing = 0.0, $char_spacing = 0.0) {
        SimpleLogger::log('tcpdf_logs', '28. ' . __FUNCTION__, "Calculating text width for: '{$text}' with font '{$font}' size {$size}");
        
        // Map font to TCPDF font name
        $tcpdf_font = $this->_mapFontToTCPDF($font);
        
        try {
            // Save current font settings
            $current_font = $this->_pdf->getFontFamily();
            $current_style = $this->_pdf->getFontStyle();
            $current_size = $this->_pdf->getFontSizePt();
            
            // Set the font for measurement
            $this->_pdf->SetFont($tcpdf_font, '', $size);
            
            // Get base text width
            $width = $this->_pdf->GetStringWidth($text);
            
            // Add word spacing - count spaces in text
            if ($word_spacing != 0.0) {
                $space_count = substr_count($text, ' ');
                $width += $space_count * $word_spacing;
            }
            
            // Add character spacing - count characters minus one (no spacing after last char)
            if ($char_spacing != 0.0) {
                $char_count = mb_strlen($text, 'UTF-8') - 1; // -1 because no spacing after last character
                if ($char_count > 0) {
                    $width += $char_count * $char_spacing;
                }
            }
            
            // Restore previous font settings
            $this->_pdf->SetFont($current_font, $current_style, $current_size);
            
            return $width;
            
        } catch (\Exception $e) {
            SimpleLogger::log('tcpdf_logs', '28. ' . __FUNCTION__, "Error calculating text width: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Calculates font height, in points
     *
     * @param string $font The font file to use
     * @param float  $size The font size, in points
     *
     * @return float
     */
    function get_font_height($font, $size) {
        SimpleLogger::log('tcpdf_logs', '29. ' . __FUNCTION__, "Calculating font height for font '{$font}' size {$size}");
        
        // Map font to TCPDF font name
        $tcpdf_font = $this->_mapFontToTCPDF($font);
        
        try {
            // Save current font settings
            $current_font = $this->_pdf->getFontFamily();
            $current_style = $this->_pdf->getFontStyle();
            $current_size = $this->_pdf->getFontSizePt();
            
            // Set the font for measurement
            $this->_pdf->SetFont($tcpdf_font, '', $size);
            
            // Get font height - for most practical purposes, the font size is a good approximation
            // TCPDF doesn't provide direct access to detailed font metrics in a simple way
            $height = $size;
            
            // We can try to get the cell height which TCPDF calculates based on font metrics
            $cell_height = $this->_pdf->getCellHeight($size);
            if ($cell_height > 0) {
                $height = $cell_height;
            }
            
            // Restore previous font settings
            $this->_pdf->SetFont($current_font, $current_style, $current_size);
            
            return $height;
            
        } catch (\Exception $e) {
            SimpleLogger::log('tcpdf_logs', '29. ' . __FUNCTION__, "Error calculating font height: " . $e->getMessage());
            // Fallback to font size as approximation
            return $size;
        }
    }

    /**
     * Calculates font baseline, in points
     *
     * @param string $font The font file to use
     * @param float  $size The font size, in points
     *
     * @return float
     */
    function get_font_baseline($font, $size) {
        SimpleLogger::log('tcpdf_logs', '30. ' . __FUNCTION__, "Calculating font baseline for font '{$font}' size {$size}");
        
        // Map font to TCPDF font name
        $tcpdf_font = $this->_mapFontToTCPDF($font);
        
        try {
            // Save current font settings
            $current_font = $this->_pdf->getFontFamily();
            $current_style = $this->_pdf->getFontStyle();
            $current_size = $this->_pdf->getFontSizePt();
            
            // Set the font for measurement
            $this->_pdf->SetFont($tcpdf_font, '', $size);
            
            // Calculate baseline - this is typically about 75-80% of the font height from the top
            // For most fonts, the baseline is approximately 0.8 * font_size from the top
            $baseline = $size * 0.8;
            
            // Alternative approach: try to calculate based on font metrics if available
            // The baseline is the distance from the top of the font to the baseline
            $font_height = $this->get_font_height($font, $size);
            
            // Typical ratio for baseline position (this varies by font but 0.8 is a good average)
            $baseline = $font_height * 0.8;
            
            // Restore previous font settings
            $this->_pdf->SetFont($current_font, $current_style, $current_size);
            
            return $baseline;
            
        } catch (\Exception $e) {
            SimpleLogger::log('tcpdf_logs', '30. ' . __FUNCTION__, "Error calculating font baseline: " . $e->getMessage());
            // Fallback calculation
            return $size * 0.8;
        }
    }

    /**
     * Returns the PDF's width in points
     *
     * @return float
     */
    function get_width() {
        SimpleLogger::log('tcpdf_logs', '38. ' . __FUNCTION__, "Returning width: {$this->_width}");
        return $this->_width;
    }

    /**
     * Returns the PDF's height in points
     *
     * @return float
     */
    function get_height() {
        SimpleLogger::log('tcpdf_logs', '39. ' . __FUNCTION__, "Returning height: {$this->_height}");
        return $this->_height;
    }

    /**
     * Sets the opacity
     *
     * @param float  $opacity
     * @param string $mode
     */
    public function set_opacity(float $opacity, string $mode = "Normal"): void {
        SimpleLogger::log('tcpdf_logs', '40. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Sets the default view
     *
     * @param string $view
     * 'XYZ'  left, top, zoom
     * 'Fit'
     * 'FitH' top
     * 'FitV' left
     * 'FitR' left,bottom,right
     * 'FitB'
     * 'FitBH' top
     * 'FitBV' left
     * @param array $options
     */
    function set_default_view($view, $options = []) {
        SimpleLogger::log('tcpdf_logs', '37. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * @param string $code
     */
    function javascript($code) {
        SimpleLogger::log('tcpdf_logs', '32. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Starts a new page
     *
     * Subsequent drawing operations will appear on the new page.
     */
    function new_page() {
        SimpleLogger::log('tcpdf_logs', '6. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Streams the PDF to the client.
     *
     * @param string $filename The filename to present to the client.
     * @param array  $options  Associative array: 'compress' => 1 or 0 (default 1); 'Attachment' => 1 or 0 (default 1).
     */
    function stream($filename, $options = []) {
        SimpleLogger::log('tcpdf_logs', '41. ' . __FUNCTION__, "Not Implemented yet.");
    }

    /**
     * Returns the PDF as a string.
     *
     * @param array $options Associative array: 'compress' => 1 or 0 (default 1).
     *
     * @return string
     */
    function output($options = []) {
        SimpleLogger::log('tcpdf_logs', '42. ' . __FUNCTION__, "Generating PDF output");
        
        // Set compression option (default is enabled)
        $compress = $options['compress'] ?? 1;
        if ($compress) {
            $this->_pdf->setCompression(true);
        } else {
            $this->_pdf->setCompression(false);
        }
        
        return $this->_pdf->Output('', 'S');
    }
}