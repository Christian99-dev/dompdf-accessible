<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Dompdf\Adapter;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
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
     * Will be set, if setPageCount() is called, and will override the actual page count
     *
     * @var int
     */
    protected $_page_count = null;

    /**
     * @var array
     */
    protected $_page_texts = [];

    /**
     * @var float
     */
    protected $_current_opacity = 1.0;

    /**
     * @param string|float[] $paper       The paper size to use as either a standard paper size (see {@link Dompdf\Adapter\TCPDF::$PAPER_SIZES})
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
        // $width_mm = ($size[2] - $size[0]) * 0.352778; // Convert points to mm
        // $height_mm = ($size[3] - $size[1]) * 0.352778; // Convert points to mm

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

        $this->_pdf->setCellPaddings(0,0,0,0);
        $this->_pdf->setCellMargins(0,0,0,0);
        $this->_pdf->setCellHeightRatio(1);

        // Add first page
        $this->_pdf->AddPage();

        $this->_width = $size[2] - $size[0];
        $this->_height = $size[3] - $size[1];
    }

    /**
     * @return Dompdf
     */
    function get_dompdf() {
        SimpleLogger::log('tcpdf_logs', '2. ' . __FUNCTION__, "Getting Dompdf instance");
        return $this->_dompdf;
    }

    /**
     * Returns the current page number
     *
     * @return int
     */
    function get_page_number() {
        SimpleLogger::log('tcpdf_logs', '3. ' . __FUNCTION__, "Getting current page number");
        return $this->_pdf->getPage();
    }

    /**
     * Returns the total number of pages in the document
     *
     * @return int
     */
    function get_page_count() {
        SimpleLogger::log('tcpdf_logs', '4. ' . __FUNCTION__, "Getting total page count");
        return $this->_page_count ?? $this->_pdf->getPage();
    }

    /**
     * Sets the total number of pages
     *
     * @param int $count
     */
    function set_page_count($count) {
        SimpleLogger::log('tcpdf_logs', '5. ' . __FUNCTION__, "Setting total page count");
        $this->_page_count = (int)$count;
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
        SimpleLogger::log('tcpdf_logs', '7. ' . __FUNCTION__, "Drawing line from ({$x1}, {$y1}) to ({$x2}, {$y2})");
        
        // Set stroke color
        $this->_set_stroke_color($color);
        
        // Set line style (width, cap, dash pattern)
        $this->_set_line_style($width, $cap, "", $style);
        
        // Draw the line (TCPDF uses different coordinate system - y is inverted)
        $this->_pdf->Line($x1, $y1, $x2, $y2);
        
        // Set line transparency
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    /**
     * Set stroke color for drawing operations
     *
     * @param array $color Color array in the format `[r, g, b, "alpha" => alpha]`
     *                     where r, g, b, and alpha are float values between 0 and 1
     */
    protected function _set_stroke_color($color) {
        SimpleLogger::log('tcpdf_logs', '66. ' . __FUNCTION__, "Setting stroke color {$color}");
        // Convert color values from 0-1 range to 0-255 range for TCPDF
        $r = (int)($color[0] * 255);
        $g = (int)($color[1] * 255);
        $b = (int)($color[2] * 255);
        
        $this->_pdf->SetDrawColor($r, $g, $b);
        
        // Handle alpha if present
        if (isset($color['alpha'])) {
            $this->_pdf->SetAlpha($color['alpha']);
        }
    }

    /**
     * Set line style for drawing operations
     *
     * @param float  $width Line width
     * @param string $cap   Line cap style: 'butt', 'round', or 'square'
     * @param string $join  Line join style (unused in this context)
     * @param array  $style Dash pattern array
     */
    protected function _set_line_style($width, $cap = "butt", $join = "", $style = []) {
        SimpleLogger::log('tcpdf_logs', '67. ' . __FUNCTION__, "Setting line style with width {$width}, cap {$cap}, style " . json_encode($style));
        // Set line width
        $this->_pdf->SetLineWidth($width);
        
        // Set line cap style using SetLineStyle array
        $capStyle = 0; // butt cap (default)
        switch (strtolower($cap)) {
            case 'round':
                $capStyle = 1;
                break;
            case 'square':
                $capStyle = 2;
                break;
        }
        
        // Create line style array for TCPDF
        $lineStyle = [
            'width' => $width,
            'cap' => $capStyle,
            'join' => 0, // miter join (default)
            'dash' => !empty($style) ? $style : 0,
            'color' => null // Use current draw color
        ];
        
        $this->_pdf->SetLineStyle($lineStyle);
    }

    /**
     * Set line transparency
     *
     * @param string $mode    Blend mode
     * @param float  $opacity Opacity value (0-1)
     */
    protected function _set_line_transparency($mode, $opacity) {
        SimpleLogger::log('tcpdf_logs', '68. ' . __FUNCTION__, "Setting line transparency to {$opacity} with mode {$mode}");
        // TCPDF handles transparency through SetAlpha
        $this->_pdf->SetAlpha($opacity);
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
        SimpleLogger::log('tcpdf_logs', '8. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '9. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '10. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '14. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '15. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * Starts a clipping polygon
     *
     * @param float[] $points
     */
    public function clipping_polygon(array $points): void {
        SimpleLogger::log('tcpdf_logs', '16. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * Ends the last clipping shape
     */
    function clipping_end() {
        SimpleLogger::log('tcpdf_logs', '17. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * Processes a callback or script on every page.
     *
     * The callback function receives the four parameters `int $pageNumber`,
     * `int $pageCount`, `Canvas $canvas`, and `FontMetrics $fontMetrics`, in
     * that order. If a script is passed as string, the variables `$PAGE_NUM`,
     * `$PAGE_COUNT`, `$pdf`, and `fontMetrics` are available instead. Passing
     * a script as string is deprecated and will be removed in a future version.
     *
     * This function can be used to add page numbers to all pages after the
     * first one, for example.
     *
     * @param callable|string $callback The callback function or PHP script to process on every page
     */
    public function page_script($callback): void {
        SimpleLogger::log('tcpdf_logs', '31. ' . __FUNCTION__, "Setting page script");
        if (is_string($callback)) {
            $this->processPageScript(function (
                int $PAGE_NUM,
                int $PAGE_COUNT,
                self $pdf,
                FontMetrics $fontMetrics
            ) use ($callback) {
                eval($callback);
            });
            return;
        }

        $this->processPageScript($callback);
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
        SimpleLogger::log('tcpdf_logs', '26. ' . __FUNCTION__, "Setting page text at ({$x}, {$y})");
        $this->processPageScript(function (int $pageNumber, int $pageCount) use ($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle) {
            $text = str_replace(
                ["{PAGE_NUM}", "{PAGE_COUNT}"],
                [$pageNumber, $pageCount],
                $text
            );
            $this->text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
        });
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
        SimpleLogger::log('tcpdf_logs', '13. ' . __FUNCTION__, "Setting page line from ({$x1}, {$y1}) to ({$x2}, {$y2})");
        $this->processPageScript(function () use ($x1, $y1, $x2, $y2, $color, $width, $style) {
            $this->line($x1, $y1, $x2, $y2, $color, $width, $style);
        });
    }

    /**
     * Save current state
     */
    function save() {
        SimpleLogger::log('tcpdf_logs', '18. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * Restore last state
     */
    function restore() {
        SimpleLogger::log('tcpdf_logs', '19. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * Rotate
     *
     * @param float $angle angle in degrees for counter-clockwise rotation
     * @param float $x     Origin abscissa
     * @param float $y     Origin ordinate
     */
    function rotate($angle, $x, $y) {
        SimpleLogger::log('tcpdf_logs', '20. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '21. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '22. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * Translate
     *
     * @param float $t_x movement to the right
     * @param float $t_y movement to the bottom
     */
    function translate($t_x, $t_y) {
        SimpleLogger::log('tcpdf_logs', '23. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '24. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '11. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '12. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '33. ' . __FUNCTION__, "Not Implemented");
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
        SimpleLogger::log('tcpdf_logs', '25. ' . __FUNCTION__, "Adding text at ({$x}, {$y}) with font: {$font}, text: " . substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''));        
        // Convert color values from 0-1 range to 0-255 range for TCPDF
        $r = (int)($color[0] * 255);
        $g = (int)($color[1] * 255);
        $b = (int)($color[2] * 255);
        
        // Set text color
        $this->_pdf->SetTextColor($r, $g, $b);
        
        // Set font with proper weight and style support
        $fontInfo = $this->_mapFontFamily($font);
        $this->_pdf->SetFont($fontInfo['family'], $fontInfo['style'], $size);
        
        // Apply character and word spacing if supported by TCPDF
        if ($char_space != 0.0) {
            $this->_pdf->setFontSpacing($char_space);
        }
        
        // Position and add text
        if ($angle != 0.0) {
            // For rotated text, we need to use transformation matrix
            $this->_pdf->StartTransform();
            $this->_pdf->Rotate($angle, $x, $y);
            $this->_pdf->Text($x, $y, $text);
            $this->_pdf->StopTransform();
        } else {
            $this->_pdf->Text($x, $y, $text);
        }
        
        // Reset font spacing if it was changed
        if ($char_space != 0.0) {
            $this->_pdf->setFontSpacing(0);
        }
    }
    
    /**
     * Maps font names to TCPDF font families and extracts style information
     * 
     * @param string $font Font name/path
     * @param string $weight Font weight (normal, bold, 100-900) - optional
     * @param string $style Font style (normal, italic, oblique) - optional
     * @return array Array with 'family' and 'style' keys
     */
    private function _mapFontFamily($font, $weight = 'normal', $style = 'normal') {
        SimpleLogger::log('tcpdf_logs', '64. ' . __FUNCTION__, "Mapping font family for font: {$font}");

        // Extract font family name from path or full name
        $fontName = strtolower(basename($font, '.ttf'));
        
        // Initialize result
        $result = [
            'family' => 'helvetica',
            'style' => ''
        ];
        
        // Map common fonts to TCPDF equivalents (all TCPDF core fonts)
        $fontMap = [
            // Helvetica family (sans-serif)
            'helvetica' => 'helvetica',
            'arial' => 'helvetica',
            'sans-serif' => 'helvetica',
            
            // Times family (serif)
            'times' => 'times',
            'timesnewroman' => 'times',
            'times-roman' => 'times',
            'serif' => 'times',
            
            // Courier family (monospace)
            'courier' => 'courier',
            'couriernew' => 'courier',
            'courier-new' => 'courier',
            'monospace' => 'courier',
            
            // Symbol fonts
            'symbol' => 'symbol',
            'zapfdingbats' => 'zapfdingbats',
            'zapf-dingbats' => 'zapfdingbats',
            'dingbats' => 'zapfdingbats'
        ];
        
        // Check if it's a standard font and extract base family
        foreach ($fontMap as $pattern => $tcpdfFont) {
            if (strpos($fontName, $pattern) !== false) {
                $result['family'] = $tcpdfFont;
                break;
            }
        }
        
        // Extract weight and style information from font name and CSS properties
        $tcpdfStyle = '';
        
        // Process CSS font-weight (prioritize explicit weight parameter)
        if ($weight !== 'normal') {
            // Handle numeric weights (CSS font-weight: 100-900)
            if (is_numeric($weight)) {
                $weightNum = (int)$weight;
                if ($weightNum >= 600) { // 600-900 are considered bold
                    $tcpdfStyle .= 'B';
                }
            }
            // Handle keyword weights
            elseif (in_array(strtolower($weight), ['bold', 'bolder', '700', '800', '900'])) {
                $tcpdfStyle .= 'B';
            }
        }
        
        // Process CSS font-style (prioritize explicit style parameter)
        if ($style !== 'normal') {
            if (in_array(strtolower($style), ['italic', 'oblique'])) {
                $tcpdfStyle .= 'I';
            }
        }
        
        // Fallback: Extract weight and style from font name if no explicit CSS properties
        if (empty($tcpdfStyle)) {
            // Check for bold variations in font name
            if (preg_match('/(?:^|[-_\s])(bold|b|heavy|black|extra[-_]?bold|semi[-_]?bold|demi[-_]?bold)(?:[-_\s]|$)/i', $fontName)) {
                $tcpdfStyle .= 'B';
            }
            
            // Check for italic variations in font name
            if (preg_match('/(?:^|[-_\s])(italic|i|oblique|slant)(?:[-_\s]|$)/i', $fontName)) {
                $tcpdfStyle .= 'I';
            }
        }
        
        $result['style'] = $tcpdfStyle;
        
        SimpleLogger::log('tcpdf_logs', '64a. ' . __FUNCTION__, "Mapped font '{$font}' (weight: {$weight}, style: {$style}) to family: {$result['family']}, TCPDF style: '{$result['style']}'");
        
        return $result;
    }

    /**
     * Add a named destination (similar to <a name="foo">...</a> in html)
     *
     * @param string $anchorname The name of the named destination
     */
    function add_named_dest($anchorname) {
        SimpleLogger::log('tcpdf_logs', '34. ' . __FUNCTION__, "Adding named destination: {$anchorname}");
        
        // Get current position
        $y = $this->_pdf->GetY();
        
        // Add bookmark/named destination at current position
        // TCPDF uses Bookmark method to create destinations
        $this->_pdf->Bookmark($anchorname, 0, $y, '', '', array(0,0,0));
        
        // Also create a destination that can be linked to
        $this->_pdf->setDestination($anchorname, $y, $this->_pdf->getPage());
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
        SimpleLogger::log('tcpdf_logs', '35. ' . __FUNCTION__, "Adding link to: {$url} at ({$x}, {$y}) size {$width}x{$height}");
        
        if (strpos($url, '#') === 0) {
            // Internal link to named destination
            $destination = substr($url, 1);
            if ($destination) {
                // For TCPDF internal links, we need to use the format: '#<destination>'
                // TCPDF's Link method can handle internal destinations with # prefix
                $this->_pdf->Link($x, $y, $width, $height, '#' . $destination);
            }
        } else {
            // External link
            $this->_pdf->Link($x, $y, $width, $height, $url);
        }
    }

    /**
     * Add meta information to the PDF.
     *
     * @param string $label Label of the value (Creator, Producer, etc.)
     * @param string $value The text to set
     */
    public function add_info(string $label, string $value): void {
        SimpleLogger::log('tcpdf_logs', '36. ' . __FUNCTION__, "Adding info: {$label} = {$value}");
        
        // Map common metadata labels to TCPDF methods
        switch (strtolower($label)) {
            case 'title':
                $this->_pdf->SetTitle($value);
                break;
            case 'author':
                $this->_pdf->SetAuthor($value);
                break;
            case 'subject':
                $this->_pdf->SetSubject($value);
                break;
            case 'keywords':
                $this->_pdf->SetKeywords($value);
                break;
            case 'creator':
                $this->_pdf->SetCreator($value);
                break;
            default:
                // For other metadata, we could use custom properties if TCPDF supports them
                // or just store them in a way that's accessible
                // TCPDF doesn't have a direct equivalent to CPDF's addInfo for arbitrary keys
                // So we'll just log it for now
                SimpleLogger::log('tcpdf_logs', '36. ' . __FUNCTION__, "Custom metadata not directly supported: {$label} = {$value}");
                break;
        }
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
        SimpleLogger::log('tcpdf_logs', '27. ' . __FUNCTION__, "Checking font support for character in font: {$font}");
        
        if ($char === "") {
            return true;
        }
        
        // Map font to TCPDF font family and style
        $fontInfo = $this->_mapFontFamily($font);
        
        // Set the font temporarily to check support
        // Note: TCPDF doesn't provide direct access to current font style, so we save the whole font info
        $currentFont = $this->_pdf->getFontFamily();
        $currentSize = $this->_pdf->getFontSizePt();
        $currentStyle = $this->_pdf->getFontStyle(); // This might return style info if available
        
        try {
            // Try to set the font - if it fails, font doesn't exist
            $this->_pdf->SetFont($fontInfo['family'], $fontInfo['style'], 12);
            
            // For standard fonts, TCPDF generally supports most common characters
            // More sophisticated checking could be implemented here if needed
            $charCode = ord($char);
            
            // Basic ASCII characters are always supported
            if ($charCode >= 32 && $charCode <= 126) {
                return true;
            }
            
            // Extended ASCII and Unicode support depends on the font
            // For now, we'll assume most characters are supported by standard fonts
            // This could be enhanced with more sophisticated font checking
            return true;
            
        } catch (\Exception $e) {
            return false;
        } finally {
            // Restore original font settings
            try {
                $this->_pdf->SetFont($currentFont, $currentStyle ?: '', $currentSize);
            } catch (\Exception $e) {
                // If we can't restore, at least try to set a default font
                $this->_pdf->SetFont('helvetica', '', 12);
            }
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
        SimpleLogger::log('tcpdf_logs', '28. ' . __FUNCTION__, "Getting text width for font: {$font}, size: {$size}");
        
        // Map font to TCPDF font family and style
        $fontInfo = $this->_mapFontFamily($font);
        
        // Store current font settings to restore later
        $currentFont = $this->_pdf->getFontFamily();
        $currentSize = $this->_pdf->getFontSizePt();
        $currentStyle = $this->_pdf->getFontStyle();
        
        try {
            // Set the font for measurement
            $this->_pdf->SetFont($fontInfo['family'], $fontInfo['style'], $size);
            
            // Get the string width from TCPDF
            $width = $this->_pdf->GetStringWidth($text);
            
            // Add word spacing - count spaces in text and multiply by word_spacing
            if ($word_spacing != 0.0) {
                $spaceCount = substr_count($text, ' ');
                $width += $spaceCount * $word_spacing;
            }
            
            // Add character spacing - multiply by character count
            if ($char_spacing != 0.0) {
                $charCount = mb_strlen($text, 'UTF-8');
                $width += $charCount * $char_spacing;
            }
            
            return $width;
            
        } catch (\Exception $e) {
            // If there's an error, return a reasonable fallback
            return strlen($text) * $size * 0.6; // Rough approximation
        } finally {
            // Restore original font settings
            try {
                $this->_pdf->SetFont($currentFont, $currentStyle ?: '', $currentSize);
            } catch (\Exception $e) {
                // If we can't restore, at least try to set a default font
                $this->_pdf->SetFont('helvetica', '', 12);
            }
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
        SimpleLogger::log('tcpdf_logs', '29. ' . __FUNCTION__, "Getting font height for font: {$font}, size: {$size}");
        
        // Use TCPDF's native font height calculation (similar to CPDF approach)
        // This maps the font to TCPDF and gets the actual font metrics
        $tcpdf_font = $this->_mapFontFamily($font);
        $this->_pdf->SetFont($tcpdf_font['family'], $tcpdf_font['style'], $size);
        
        // TCPDF's getCellHeight already includes cell_height_ratio
        // We need the raw font height, so we divide by the TCPDF internal ratio
        $raw_height = $this->_pdf->getCellHeight($size, false) / $this->_pdf->getCellHeightRatio();
        
        // Now apply the Dompdf font height ratio (consistent with CPDF approach)
        $options = $this->_dompdf->getOptions();
        $height = $raw_height * $options->getFontHeightRatio();
        
        // Apply correction factors to match CPDF results exactly because TCPDF and CPDF have slight different font metrics
        // These factors were determined by comparing TCPDF vs CPDF output, and literally calculating the differences
        // to ensure identical font height calculations across both backends and dont break existing layouts by switching to tcpdf
        $fontName = strtolower(basename($font));
        switch (true) {
            case (strpos($fontName, 'times') !== false):
                $correction_factor = 0.9;
                break;
                
            case (strpos($fontName, 'courier') !== false):
            case (strpos($fontName, 'kurier') !== false):
                $correction_factor = 0.786; 
                break;
                
            case (strpos($fontName, 'helvetica') !== false):
                $correction_factor = 0.925;
                break;

            default:
                $correction_factor = 1;
                break;
        }
        
        $height = $height * $correction_factor;
        
        SimpleLogger::log('tcpdf_logs', '29a. ' . __FUNCTION__, "Returning value: {$height}");
        
        return $height;
    }
    
    /**
     * Returns the font x-height, in points
     *
     * @param string $font The font file to use
     * @param float  $size The font size, in points
     *
     * @return float
     */
    //function get_font_x_height($font, $size);

    /**
     * Calculates font baseline, in points
     *
     * @param string $font The font file to use
     * @param float  $size The font size, in points
     *
     * @return float
     */
    function get_font_baseline($font, $size) {
        SimpleLogger::log('tcpdf_logs', '30. ' . __FUNCTION__, "Getting font baseline for font: {$font}, size: {$size}");
        
        // Get font height without the ratio applied - this matches CPDF behavior
        // The baseline is the distance from the top of the line to where characters sit
        $ratio = $this->_dompdf->getOptions()->getFontHeightRatio();
        $fontHeight = $this->get_font_height($font, $size);
        
        // Return font height without the ratio, which represents the baseline distance
        return $fontHeight / $ratio;
    }

    /**
     * Returns the PDF's width in points
     *
     * @return float
     */
    function get_width() {
        SimpleLogger::log('tcpdf_logs', '38. ' . __FUNCTION__, "Get Width: " . $this->_width);
        return $this->_width;
    }

    /**
     * Returns the PDF's height in points
     *
     * @return float
     */
    function get_height() {
        SimpleLogger::log('tcpdf_logs', '39. ' . __FUNCTION__, "Get Height: " . $this->_height);
        return $this->_height;
    }

    /**
     * Sets the opacity
     *
     * @param float  $opacity
     * @param string $mode
     */
    public function set_opacity(float $opacity, string $mode = "Normal"): void {
        SimpleLogger::log('tcpdf_logs', '40. ' . __FUNCTION__, "Setting opacity: {$opacity} mode: {$mode}");
        
        // Clamp opacity to valid range (0.0 to 1.0)
        $opacity = max(0.0, min(1.0, $opacity));
        $this->_pdf->SetAlpha($opacity);
        
        // Store current opacity for potential future use
        $this->_current_opacity = $opacity;
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
        SimpleLogger::log('tcpdf_logs', '37. ' . __FUNCTION__, "Not Implemented");
    }

    /**
     * @param string $code
     */
    function javascript($code) {
        SimpleLogger::log('tcpdf_logs', '32. ' . __FUNCTION__, "Adding JavaScript code to PDF");
        $this->_pdf->IncludeJS($code);
    }

    /**
     * Starts a new page
     *
     * Subsequent drawing operations will appear on the new page.
     */
    function new_page() {
        SimpleLogger::log('tcpdf_logs', '6. ' . __FUNCTION__, "Not Implemented");
        $this->_pdf->AddPage();
    }

    /**
     * Streams the PDF to the client.
     *
     * @param string $filename The filename to present to the client.
     * @param array  $options  Associative array: 'compress' => 1 or 0 (default 1); 'Attachment' => 1 or 0 (default 1).
     */
    function stream($filename, $options = []) {
        SimpleLogger::log('tcpdf_logs', '41. ' . __FUNCTION__, "Not Implemented");
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

    /**
     * Processes a callback on every page
     *
     * @param callable $callback The callback function to process on every page
     */
    protected function processPageScript(callable $callback): void
    {
        SimpleLogger::log('tcpdf_logs', '65. ' . __FUNCTION__, "Processing page script callback");
        
        // Get the current page to restore it later
        $currentPage = $this->_pdf->getPage();
        
        // Get total number of pages
        $pageCount = $this->_pdf->getNumPages();
        
        // Loop through all pages
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            // Set the current page
            $this->_pdf->setPage($pageNumber);
            
            // Get FontMetrics instance
            $fontMetrics = $this->_dompdf->getFontMetrics();
            
            // Call the callback with page number, page count, canvas, and font metrics
            $callback($pageNumber, $pageCount, $this, $fontMetrics);
        }
        
        // Restore the original current page
        $this->_pdf->setPage($currentPage);
    }
}