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
        SimpleLogger::log('tcpdf_logs', '26. ' . __FUNCTION__, "Not Implemented yet.");
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
        SimpleLogger::log('tcpdf_logs', '25. ' . __FUNCTION__, "Not Implemented yet.");
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
        SimpleLogger::log('tcpdf_logs', '27. ' . __FUNCTION__, "Not Implemented yet.");
        return true;
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
        SimpleLogger::log('tcpdf_logs', '28. ' . __FUNCTION__, "Not Implemented yet.");
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
        SimpleLogger::log('tcpdf_logs', '29. ' . __FUNCTION__, "Not Implemented yet.");
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
        SimpleLogger::log('tcpdf_logs', '30. ' . __FUNCTION__, "Not Implemented yet.");
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