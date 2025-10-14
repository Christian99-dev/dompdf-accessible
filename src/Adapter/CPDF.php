<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

// FIXME: Need to sanity check inputs to this class
namespace Dompdf\Adapter;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Exception;
use Dompdf\FontMetrics;
use Dompdf\Helpers;
use Dompdf\Image\Cache;
use Dompdf\SimpleLogger;
use FontLib\Exception\FontNotFoundException;

/**
 * PDF rendering interface
 *
 * Dompdf\Adapter\CPDF provides a simple stateless interface to the stateful one
 * provided by the Cpdf class.
 *
 * Unless otherwise mentioned, all dimensions are in points (1/72 in).  The
 * coordinate origin is in the top left corner, and y values increase
 * downwards.
 *
 * See {@link http://www.ros.co.nz/pdf/} for more complete documentation
 * on the underlying {@link Cpdf} class.
 *
 * @package dompdf
 */
class CPDF implements Canvas
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
     * The Dompdf object
     *
     * @var Dompdf
     */
    protected $_dompdf;

    /**
     * Instance of Cpdf class
     *
     * @var \Dompdf\Cpdf
     */
    protected $_pdf;

    /**
     * PDF width, in points
     *
     * @var float
     */
    protected $_width;

    /**
     * PDF height, in points
     *
     * @var float
     */
    protected $_height;

    /**
     * Current page number
     *
     * @var int
     */
    protected $_page_number;

    /**
     * Total number of pages
     *
     * @var int
     */
    protected $_page_count;

    /**
     * Array of pages for accessing after rendering is initially complete
     *
     * @var array
     */
    protected $_pages;

    /**
     * Currently-applied opacity level (0 - 1)
     *
     * @var float
     */
    protected $_current_opacity = 1;

    public function __construct($paper = "letter", string $orientation = "portrait", ?Dompdf $dompdf = null)
    {
        SimpleLogger::log('cpdf_logs', '1. ' . __FUNCTION__, "Constructing CPDF with paper: {$paper}, orientation: {$orientation}");
        
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

        $this->_pdf = new \Dompdf\Cpdf(
            $size,
            true,
            $this->_dompdf->getOptions()->getFontCache(),
            $this->_dompdf->getOptions()->getTempDir()
        );

        $this->_pdf->addInfo("Producer", sprintf("%s + CPDF", $this->_dompdf->version));
        $time = substr_replace(date('YmdHisO'), '\'', -2, 0) . '\'';
        $this->_pdf->addInfo("CreationDate", "D:$time");
        $this->_pdf->addInfo("ModDate", "D:$time");

        if ($this->_dompdf->getOptions()->isPdfAEnabled()) {
            $this->_pdf->enablePdfACompliance();
        }
        
        /** Future Implementation - PDF/UA */
        if ($this->_dompdf->getOptions()->isPdfUaEnabled()) {
            $this->_pdf->enablePdfUACompliance();
        }        

        SimpleLogger::log("cpdf_adapter_logs", __METHOD__, "PDF/UA support " . ($this->_pdf->pdfua ? "enabled" : "disabled"));
        SimpleLogger::log("cpdf_adapter_logs", __METHOD__, "PDF/A support " . ($this->_pdf->pdfa ? "enabled" : "disabled"));


        $this->_width = $size[2] - $size[0];
        $this->_height = $size[3] - $size[1];

        $this->_page_number = $this->_page_count = 1;

        $this->_pages = [$this->_pdf->getFirstPageId()];
    }

    public function get_dompdf()
    {
        SimpleLogger::log('cpdf_logs', '2. ' . __FUNCTION__, "Getting Dompdf instance");
        return $this->_dompdf;
    }

    /**
     * Returns the Cpdf instance
     *
     * @return \Dompdf\Cpdf
     */
    public function get_cpdf()
    {
        SimpleLogger::log('cpdf_logs', '63. ' . __FUNCTION__, "Getting Cpdf instance");
        return $this->_pdf;
    }

    public function add_info(string $label, string $value): void
    {
        SimpleLogger::log('cpdf_logs', '36. ' . __FUNCTION__, "Adding info: {$label} = {$value}");
        $this->_pdf->addInfo($label, $value);
    }

    /**
     * Opens a new 'object'
     *
     * While an object is open, all drawing actions are recorded in the object,
     * as opposed to being drawn on the current page.  Objects can be added
     * later to a specific page or to several pages.
     *
     * The return value is an integer ID for the new object.
     *
     * @see CPDF::close_object()
     * @see CPDF::add_object()
     *
     * @return int
     */
    public function open_object()
    {
        SimpleLogger::log('cpdf_logs', '43. ' . __FUNCTION__, "Opening new object");
        $ret = $this->_pdf->openObject();
        $this->_pdf->saveState();
        return $ret;
    }

    /**
     * Reopens an existing 'object'
     *
     * @see CPDF::open_object()
     * @param int $object the ID of a previously opened object
     */
    public function reopen_object($object)
    {
        SimpleLogger::log('cpdf_logs', '44. ' . __FUNCTION__, "Reopening object: {$object}");
        $this->_pdf->reopenObject($object);
        $this->_pdf->saveState();
    }

    /**
     * Closes the current 'object'
     *
     * @see CPDF::open_object()
     */
    public function close_object()
    {
        SimpleLogger::log('cpdf_logs', '45. ' . __FUNCTION__, "Closing object");
        $this->_pdf->restoreState();
        $this->_pdf->closeObject();
    }

    /**
     * Adds a specified 'object' to the document
     *
     * $object int specifying an object created with {@link
     * CPDF::open_object()}.  $where can be one of:
     * - 'add' add to current page only
     * - 'all' add to every page from the current one onwards
     * - 'odd' add to all odd numbered pages from now on
     * - 'even' add to all even numbered pages from now on
     * - 'next' add the object to the next page only
     * - 'nextodd' add to all odd numbered pages from the next one
     * - 'nexteven' add to all even numbered pages from the next one
     *
     * @see Cpdf::addObject()
     *
     * @param int $object
     * @param string $where
     */
    public function add_object($object, $where = 'all')
    {
        SimpleLogger::log('cpdf_logs', '46. ' . __FUNCTION__, "Adding object: {$object} where: {$where}");
        $this->_pdf->addObject($object, $where);
    }

    /**
     * Stops the specified 'object' from appearing in the document.
     *
     * The object will stop being displayed on the page following the current
     * one.
     *
     * @param int $object
     */
    public function stop_object($object)
    {
        SimpleLogger::log('cpdf_logs', '47. ' . __FUNCTION__, "Stopping object: {$object}");
        $this->_pdf->stopObject($object);
    }

    /**
     * Serialize the pdf object's current state for retrieval later
     */
    public function serialize_object($id)
    {
        SimpleLogger::log('cpdf_logs','48. ' .  __FUNCTION__, "Serializing object: {$id}");
        return $this->_pdf->serializeObject($id);
    }

    public function reopen_serialized_object($obj)
    {
        SimpleLogger::log('cpdf_logs', '49. ' . __FUNCTION__, "Reopening serialized object");
        return $this->_pdf->restoreSerializedObject($obj);
    }

    //........................................................................

    public function get_width()
    {
        SimpleLogger::log('cpdf_logs', '38. ' . __FUNCTION__, "Getting width: {$this->_width}");
        return $this->_width;
    }

    public function get_height()
    {
        SimpleLogger::log('cpdf_logs', '39. ' . __FUNCTION__, "Getting height: {$this->_height}");
        return $this->_height;
    }

    public function get_page_number()
    {
        SimpleLogger::log('cpdf_logs', '3. ' . __FUNCTION__, "Getting page number: {$this->_page_number}");
        return $this->_page_number;
    }

    public function get_page_count()
    {
        SimpleLogger::log('cpdf_logs', '4. ' . __FUNCTION__, "Getting page count: {$this->_page_count}");
        return $this->_page_count;
    }

    /**
     * Sets the current page number
     *
     * @param int $num
     */
    public function set_page_number($num)
    {
        SimpleLogger::log('cpdf_logs', '50. ' . __FUNCTION__, "Setting page number to: {$num}");
        $this->_page_number = $num;
    }

    public function set_page_count($count)
    {
        SimpleLogger::log('cpdf_logs', '5. ' . __FUNCTION__, "Setting page count to: {$count}");
        $this->_page_count = $count;
    }

    /**
     * Sets the stroke color
     *
     * See {@link Style::set_color()} for the format of the color array.
     *
     * @param array $color
     */
    protected function _set_stroke_color($color)
    {
        SimpleLogger::log('cpdf_logs', '51. ' . __FUNCTION__, "Setting stroke color");
        $this->_pdf->setStrokeColor($color);
        $alpha = isset($color["alpha"]) ? $color["alpha"] : 1;
        $alpha *= $this->_current_opacity;
        $this->_set_line_transparency("Normal", $alpha);
    }

    /**
     * Sets the fill colour
     *
     * See {@link Style::set_color()} for the format of the colour array.
     *
     * @param array $color
     */
    protected function _set_fill_color($color)
    {
        SimpleLogger::log('cpdf_logs', '52. ' . __FUNCTION__, "Setting fill color");
        $this->_pdf->setColor($color);
        $alpha = isset($color["alpha"]) ? $color["alpha"] : 1;
        $alpha *= $this->_current_opacity;
        $this->_set_fill_transparency("Normal", $alpha);
    }

    /**
     * Sets line transparency
     * @see Cpdf::setLineTransparency()
     *
     * Valid blend modes are (case-sensitive):
     *
     * Normal, Multiply, Screen, Overlay, Darken, Lighten,
     * ColorDodge, ColorBurn, HardLight, SoftLight, Difference,
     * Exclusion
     *
     * @param string $mode    the blending mode to use
     * @param float  $opacity 0.0 fully transparent, 1.0 fully opaque
     */
    protected function _set_line_transparency($mode, $opacity)
    {
        SimpleLogger::log('cpdf_logs', '53. ' . __FUNCTION__, "Setting line transparency mode: {$mode}, opacity: {$opacity}");
        $this->_pdf->setLineTransparency($mode, $opacity);
    }

    /**
     * Sets fill transparency
     * @see Cpdf::setFillTransparency()
     *
     * Valid blend modes are (case-sensitive):
     *
     * Normal, Multiply, Screen, Overlay, Darken, Lighten,
     * ColorDogde, ColorBurn, HardLight, SoftLight, Difference,
     * Exclusion
     *
     * @param string $mode    the blending mode to use
     * @param float  $opacity 0.0 fully transparent, 1.0 fully opaque
     */
    protected function _set_fill_transparency($mode, $opacity)
    {
        SimpleLogger::log('cpdf_logs', '54. ' . __FUNCTION__, "Setting fill transparency mode: {$mode}, opacity: {$opacity}");
        $this->_pdf->setFillTransparency($mode, $opacity);
    }

    /**
     * Sets the line style
     *
     * @see Cpdf::setLineStyle()
     *
     * @param float  $width
     * @param string $cap
     * @param string $join
     * @param array  $dash
     */
    protected function _set_line_style($width, $cap, $join, $dash)
    {
        SimpleLogger::log('cpdf_logs', '55. ' . __FUNCTION__, "Setting line style width: {$width}, cap: {$cap}, join: {$join}");
        $this->_pdf->setLineStyle($width, $cap, $join, $dash);
    }

    public function set_opacity(float $opacity, string $mode = "Normal"): void
    {
        SimpleLogger::log('cpdf_logs', '40. ' . __FUNCTION__, "Setting opacity: {$opacity} mode: {$mode}");
        $this->_set_line_transparency($mode, $opacity);
        $this->_set_fill_transparency($mode, $opacity);
        $this->_current_opacity = $opacity;
    }

    public function set_default_view($view, $options = [])
    {
        SimpleLogger::log('cpdf_logs', '37. ' . __FUNCTION__, "Setting default view: {$view}");
        array_unshift($options, $view);
        call_user_func_array([$this->_pdf, "openHere"], $options);
    }

    /**
     * Remaps y coords from 4th to 1st quadrant
     *
     * @param float $y
     * @return float
     */
    protected function y($y)
    {
        SimpleLogger::log('cpdf_logs', '56. ' . __FUNCTION__, "Remapping y coordinate: {$y}");
        return $this->_height - $y;
    }

    public function line($x1, $y1, $x2, $y2, $color, $width, $style = [], $cap = "butt")
    {
        SimpleLogger::log('cpdf_logs', '7. ' . __FUNCTION__, "Drawing line from ({$x1}, {$y1}) to ({$x2}, {$y2})");
        $this->_set_stroke_color($color);
        $this->_set_line_style($width, $cap, "", $style);

        $this->_pdf->line($x1, $this->y($y1),
            $x2, $this->y($y2));
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    public function arc($x, $y, $r1, $r2, $astart, $aend, $color, $width, $style = [], $cap = "butt")
    {
        SimpleLogger::log('cpdf_logs', '8. ' . __FUNCTION__, "Drawing arc at ({$x}, {$y}) with radii {$r1}, {$r2}");
        $this->_set_stroke_color($color);
        $this->_set_line_style($width, $cap, "", $style);

        $this->_pdf->ellipse($x, $this->y($y), $r1, $r2, 0, 8, $astart, $aend, false, false, true, false);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    public function rectangle($x1, $y1, $w, $h, $color, $width, $style = [], $cap = "butt")
    {
        SimpleLogger::log('cpdf_logs', '9. ' . __FUNCTION__, "Drawing rectangle at ({$x1}, {$y1}) size {$w}x{$h}");
        $this->_set_stroke_color($color);
        $this->_set_line_style($width, $cap, "", $style);
        $this->_pdf->rectangle($x1, $this->y($y1) - $h, $w, $h);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    public function filled_rectangle($x1, $y1, $w, $h, $color)
    {
        SimpleLogger::log('cpdf_logs', '10. ' . __FUNCTION__, "Drawing filled rectangle at ({$x1}, {$y1}) size {$w}x{$h}");
        $this->_set_fill_color($color);
        $this->_pdf->filledRectangle($x1, $this->y($y1) - $h, $w, $h);
        $this->_set_fill_transparency("Normal", $this->_current_opacity);
    }

    public function clipping_rectangle($x1, $y1, $w, $h)
    {
        SimpleLogger::log('cpdf_logs', '14. ' . __FUNCTION__, "Setting clipping rectangle at ({$x1}, {$y1}) size {$w}x{$h}");
        $this->_pdf->clippingRectangle($x1, $this->y($y1) - $h, $w, $h);
    }

    public function clipping_roundrectangle($x1, $y1, $w, $h, $rTL, $rTR, $rBR, $rBL)
    {
        SimpleLogger::log('cpdf_logs', '15. ' . __FUNCTION__, "Setting clipping rounded rectangle at ({$x1}, {$y1}) size {$w}x{$h}");
        $this->_pdf->clippingRectangleRounded($x1, $this->y($y1) - $h, $w, $h, $rTL, $rTR, $rBR, $rBL);
    }

    public function clipping_polygon(array $points): void
    {
        SimpleLogger::log('cpdf_logs', '16. ' . __FUNCTION__, "Setting clipping polygon with " . count($points) . " points");
        // Adjust y values
        for ($i = 1; $i < count($points); $i += 2) {
            $points[$i] = $this->y($points[$i]);
        }

        $this->_pdf->clippingPolygon($points);
    }

    public function clipping_end()
    {
        SimpleLogger::log('cpdf_logs', '17. ' . __FUNCTION__, "Ending clipping");
        $this->_pdf->clippingEnd();
    }

    public function save()
    {
        SimpleLogger::log('cpdf_logs', '18. ' . __FUNCTION__, "Saving state");
        $this->_pdf->saveState();
    }

    public function restore()
    {
        SimpleLogger::log('cpdf_logs', '19. ' . __FUNCTION__, "Restoring state");
        $this->_pdf->restoreState();
    }

    public function rotate($angle, $x, $y)
    {
        SimpleLogger::log('cpdf_logs', '20. ' . __FUNCTION__, "Rotating by {$angle} degrees at ({$x}, {$y})");
        $this->_pdf->rotate($angle, $x, $y);
    }

    public function skew($angle_x, $angle_y, $x, $y)
    {
        SimpleLogger::log('cpdf_logs', '21. ' . __FUNCTION__, "Skewing by ({$angle_x}, {$angle_y}) at ({$x}, {$y})");
        $this->_pdf->skew($angle_x, $angle_y, $x, $y);
    }

    public function scale($s_x, $s_y, $x, $y)
    {
        SimpleLogger::log('cpdf_logs', '22. ' . __FUNCTION__, "Scaling by ({$s_x}, {$s_y}) at ({$x}, {$y})");
        $this->_pdf->scale($s_x, $s_y, $x, $y);
    }

    public function translate($t_x, $t_y)
    {
        SimpleLogger::log('cpdf_logs', '23. ' . __FUNCTION__, "Translating by ({$t_x}, {$t_y})");
        $this->_pdf->translate($t_x, $t_y);
    }

    public function transform($a, $b, $c, $d, $e, $f)
    {
        SimpleLogger::log('cpdf_logs', '24. ' . __FUNCTION__, "Applying transformation matrix");
        $this->_pdf->transform([$a, $b, $c, $d, $e, $f]);
    }

    public function polygon($points, $color, $width = null, $style = [], $fill = false)
    {
        SimpleLogger::log('cpdf_logs', '11. ' . __FUNCTION__, "Drawing polygon with " . count($points) . " points, fill: " . ($fill ? 'true' : 'false'));
        $this->_set_fill_color($color);
        $this->_set_stroke_color($color);

        if (!$fill && isset($width)) {
            $this->_set_line_style($width, "square", "miter", $style);
        }

        // Adjust y values
        for ($i = 1; $i < count($points); $i += 2) {
            $points[$i] = $this->y($points[$i]);
        }

        $this->_pdf->polygon($points, $fill);

        $this->_set_fill_transparency("Normal", $this->_current_opacity);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    public function circle($x, $y, $r, $color, $width = null, $style = [], $fill = false)
    {
        SimpleLogger::log('cpdf_logs', '12. ' . __FUNCTION__, "Drawing circle at ({$x}, {$y}) radius {$r}, fill: " . ($fill ? 'true' : 'false'));
        $this->_set_fill_color($color);
        $this->_set_stroke_color($color);

        if (!$fill && isset($width)) {
            $this->_set_line_style($width, "round", "round", $style);
        }

        $this->_pdf->ellipse($x, $this->y($y), $r, 0, 0, 8, 0, 360, 1, $fill);

        $this->_set_fill_transparency("Normal", $this->_current_opacity);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    /**
     * Convert image to a PNG image
     *
     * @param string $image_url
     * @param string $type
     *
     * @return string|null The url of the newly converted image
     */
    protected function _convert_to_png($image_url, $type)
    {
        SimpleLogger::log('cpdf_logs', '57. ' . __FUNCTION__, "Converting {$type} to PNG: {$image_url}");
        $filename = Cache::getTempImage($image_url);

        if ($filename !== null && file_exists($filename)) {
            return $filename;
        }
 
        $func_name = "imagecreatefrom$type";

        set_error_handler([Helpers::class, "record_warnings"]);

        if (method_exists(Helpers::class, $func_name)) {
            $func_name = [Helpers::class, $func_name];
        } elseif (!function_exists($func_name)) {
            throw new Exception("Function $func_name() not found.  Cannot convert $type image: $image_url.  Please install the image PHP extension.");
        }

        try {
            $im = call_user_func($func_name, $image_url);

            if ($im) {
                imageinterlace($im, false);

                $tmp_dir = $this->_dompdf->getOptions()->getTempDir();
                $tmp_name = @tempnam($tmp_dir, "{$type}_dompdf_img_");
                @unlink($tmp_name);
                $filename = "$tmp_name.png";

                imagepng($im, $filename);
                imagedestroy($im);
            } else {
                $filename = null;
            }
        } finally {
            restore_error_handler();
        }

        if ($filename !== null) {
            Cache::addTempImage($image_url, $filename);
        }

        return $filename;
    }

    public function image($img, $x, $y, $w, $h, $resolution = "normal")
    {
        SimpleLogger::log('cpdf_logs', '33. ' . __FUNCTION__, "Adding image: {$img} at ({$x}, {$y}) size {$w}x{$h}");
        [$width, $height, $type] = Helpers::dompdf_getimagesize($img, $this->get_dompdf()->getHttpContext());

        $debug_png = $this->_dompdf->getOptions()->getDebugPng();

        if ($debug_png) {
            print "[image:$img|$width|$height|$type]";
        }

        switch ($type) {
            case "jpeg":
                if ($debug_png) {
                    print '!!!jpg!!!';
                }
                $this->_pdf->addJpegFromFile($img, $x, $this->y($y) - $h, $w, $h);
                break;

            case "webp":
            /** @noinspection PhpMissingBreakStatementInspection */
            case "gif":
            /** @noinspection PhpMissingBreakStatementInspection */
            case "bmp":
                if ($debug_png) print "!!!{$type}!!!";
                $img = $this->_convert_to_png($img, $type);
                if ($img === null) {
                    if ($debug_png) print '!!!conversion to PDF failed!!!';
                    $this->image(Cache::$broken_image, $x, $y, $w, $h, $resolution);
                    break;
                }

            case "png":
                if ($debug_png) print '!!!png!!!';

                $this->_pdf->addPngFromFile($img, $x, $this->y($y) - $h, $w, $h);
                break;

            case "svg":
                if ($debug_png) print '!!!SVG!!!';

                $this->_pdf->addSvgFromFile($img, $x, $this->y($y) - $h, $w, $h);
                break;

            default:
                if ($debug_png) print '!!!unknown!!!';
        }
    }

    public function select($x, $y, $w, $h, $font, $size, $color = [0, 0, 0], $opts = [])
    {
        SimpleLogger::log('cpdf_logs', '58. ' . __FUNCTION__, "Adding select field at ({$x}, {$y}) size {$w}x{$h}");
        $pdf = $this->_pdf;

        $pdf->selectFont($font);

        if (!isset($pdf->acroFormId)) {
            $pdf->addForm();
        }

        $ft = \Dompdf\Cpdf::ACROFORM_FIELD_CHOICE;
        $ff = \Dompdf\Cpdf::ACROFORM_FIELD_CHOICE_COMBO;

        $id = $pdf->addFormField($ft, rand(), $x, $this->y($y) - $h, $x + $w, $this->y($y), $ff, $size, $color);
        $pdf->setFormFieldOpt($id, $opts);
    }

    public function textarea($x, $y, $w, $h, $font, $size, $color = [0, 0, 0])
    {
        SimpleLogger::log('cpdf_logs', '59. ' . __FUNCTION__, "Adding textarea at ({$x}, {$y}) size {$w}x{$h}");
        $pdf = $this->_pdf;

        $pdf->selectFont($font);

        if (!isset($pdf->acroFormId)) {
            $pdf->addForm();
        }

        $ft = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT;
        $ff = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT_MULTILINE;

        $pdf->addFormField($ft, rand(), $x, $this->y($y) - $h, $x + $w, $this->y($y), $ff, $size, $color);
    }

    public function input($x, $y, $w, $h, $type, $font, $size, $color = [0, 0, 0])
    {
        SimpleLogger::log('cpdf_logs', '60. ' . __FUNCTION__, "Adding input field type: {$type} at ({$x}, {$y}) size {$w}x{$h}");
        $pdf = $this->_pdf;

        $pdf->selectFont($font);

        if (!isset($pdf->acroFormId)) {
            $pdf->addForm();
        }

        $ft = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT;
        $ff = 0;

        switch ($type) {
            case 'text':
                $ft = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT;
                break;
            case 'password':
                $ft = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT;
                $ff = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT_PASSWORD;
                break;
            case 'submit':
                $ft = \Dompdf\Cpdf::ACROFORM_FIELD_BUTTON;
                break;
        }

        $pdf->addFormField($ft, rand(), $x, $this->y($y) - $h, $x + $w, $this->y($y), $ff, $size, $color);
    }

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        SimpleLogger::log('cpdf_logs', '25. ' . __FUNCTION__, "Adding text at ({$x}, {$y}) with font: {$font}, text: " . substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''));
        $pdf = $this->_pdf;

        $this->_set_fill_color($color);

        $is_font_subsetting = $this->_dompdf->getOptions()->getIsFontSubsettingEnabled();
        $pdf->selectFont($font, '', true, $is_font_subsetting);

        $pdf->addText($x, $this->y($y) - $pdf->getFontHeight($size), $size, $text, $angle, $word_space, $char_space);

        $this->_set_fill_transparency("Normal", $this->_current_opacity);
    }

    public function javascript($code)
    {
        SimpleLogger::log('cpdf_logs', '32. ' . __FUNCTION__, "Adding JavaScript code");
        $this->_pdf->addJavascript($code);
    }

    //........................................................................

    public function add_named_dest($anchorname)
    {
        SimpleLogger::log('cpdf_logs', '34. ' . __FUNCTION__, "Adding named destination: {$anchorname}");
        $this->_pdf->addDestination($anchorname, "Fit");
    }

    public function add_link($url, $x, $y, $width, $height)
    {
        SimpleLogger::log('cpdf_logs', '35. ' . __FUNCTION__, "Adding link to: {$url} at ({$x}, {$y}) size {$width}x{$height}");
        $y = $this->y($y) - $height;

        if (strpos($url, '#') === 0) {
            // Local link
            $name = substr($url, 1);
            if ($name) {
                $this->_pdf->addInternalLink($name, $x, $y, $x + $width, $y + $height);
            }
        } else {
            $this->_pdf->addLink($url, $x, $y, $x + $width, $y + $height);
        }
    }

    public function font_supports_char(string $font, string $char): bool
    {
        SimpleLogger::log('cpdf_logs', '27. ' . __FUNCTION__, "Checking font support for character in font: {$font}");
        if ($char === "") {
            return true;
        }

        $subsetting = $this->_dompdf->getOptions()->getIsFontSubsettingEnabled();
        $this->_pdf->selectFont($font, '', false, $subsetting);
        if (!\array_key_exists($font, $this->_pdf->fonts)) {
            return false;
        }
        $fontInfo = $this->_pdf->fonts[$font];
        $charCode = Helpers::uniord($char, "UTF-8");

        if (!$fontInfo["isUnicode"]) {
            // The core fonts use Windows ANSI encoding. The char map uses the
            // position of the character in the encoding's mapping table in this
            // case, not the Unicode code point, which is different for the
            // characters outside ISO-8859-1 (positions 0x80-0x9F)
            // https://www.unicode.org/Public/MAPPINGS/VENDORS/MICSFT/WINDOWS/CP1252.TXT
            $mapping = [
                0x20AC => 0x80,
                0x201A => 0x82,
                0x0192 => 0x83,
                0x201E => 0x84,
                0x2026 => 0x85,
                0x2020 => 0x86,
                0x2021 => 0x87,
                0x02C6 => 0x88,
                0x2030 => 0x89,
                0x0160 => 0x8A,
                0x2039 => 0x8B,
                0x0152 => 0x8C,
                0x017D => 0x8E,
                0x2018 => 0x91,
                0x2019 => 0x92,
                0x201C => 0x93,
                0x201D => 0x94,
                0x2022 => 0x95,
                0x2013 => 0x96,
                0x2014 => 0x97,
                0x02DC => 0x98,
                0x2122 => 0x99,
                0x0161 => 0x9A,
                0x203A => 0x9B,
                0x0153 => 0x9C,
                0x017E => 0x9E,
                0x0178 => 0x9F
            ];

            $charCode = $mapping[$charCode] ?? $charCode;

            if ($charCode > 0xFF) {
                return false;
            }
        }

        return \array_key_exists($charCode, $fontInfo["C"]);
    }

    /**
     * @throws FontNotFoundException
     */
    public function get_text_width($text, $font, $size, $word_spacing = 0.0, $char_spacing = 0.0)
    {
        SimpleLogger::log('cpdf_logs', '28. ' . __FUNCTION__, "Getting text width for font: {$font}, size: {$size}");
        $this->_pdf->selectFont($font, '', true, $this->_dompdf->getOptions()->getIsFontSubsettingEnabled());
        return $this->_pdf->getTextWidth($size, $text, $word_spacing, $char_spacing);
    }

    /**
     * @throws FontNotFoundException
     */
    public function get_font_height($font, $size)
    {
        SimpleLogger::log('cpdf_logs', '29. ' . __FUNCTION__, "Getting font height for font: {$font}, size: {$size}");
        $options = $this->_dompdf->getOptions();
        $this->_pdf->selectFont($font, '', true, $options->getIsFontSubsettingEnabled());

        $height = $this->_pdf->getFontHeight($size) * $options->getFontHeightRatio();
        SimpleLogger::log('cpdf_logs', '29a. ' . __FUNCTION__, "Returning value: {$height}");
        return $height;
    }

    /*function get_font_x_height($font, $size) {
      $this->_pdf->selectFont($font);
      $ratio = $this->_dompdf->getOptions()->getFontHeightRatio();
      return $this->_pdf->getFontXHeight($size) * $ratio;
    }*/

    /**
     * @throws FontNotFoundException
     */
    public function get_font_baseline($font, $size)
    {
        SimpleLogger::log('cpdf_logs', '30. ' . __FUNCTION__, "Getting font baseline for font: {$font}, size: {$size}");
        $ratio = $this->_dompdf->getOptions()->getFontHeightRatio();
        return $this->get_font_height($font, $size) / $ratio;
    }

    /**
     * Processes a callback or script on every page.
     *
     * The callback function receives the four parameters `int $pageNumber`,
     * `int $pageCount`, `Canvas $canvas`, and `FontMetrics $fontMetrics`, in
     * that order. If a script is passed as string, the variables `$PAGE_NUM`,
     * `$PAGE_COUNT`, `$pdf`, and `$fontMetrics` are available instead. Passing
     * a script as string is deprecated and will be removed in a future version.
     *
     * This function can be used to add page numbers to all pages after the
     * first one, for example.
     *
     * @param callable|string $callback The callback function or PHP script to process on every page
     */
    public function page_script($callback): void
    {
        SimpleLogger::log('cpdf_logs', '31. ' . __FUNCTION__, "Setting page script");
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

    public function page_text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        SimpleLogger::log('cpdf_logs', '26. ' . __FUNCTION__, "Setting page text at ({$x}, {$y})");
        $this->processPageScript(function (int $pageNumber, int $pageCount) use ($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle) {
            $text = str_replace(
                ["{PAGE_NUM}", "{PAGE_COUNT}"],
                [$pageNumber, $pageCount],
                $text
            );
            $this->text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
        });
    }

    public function page_line($x1, $y1, $x2, $y2, $color, $width, $style = [])
    {
        SimpleLogger::log('cpdf_logs', '13. ' . __FUNCTION__, "Setting page line from ({$x1}, {$y1}) to ({$x2}, {$y2})");
        $this->processPageScript(function () use ($x1, $y1, $x2, $y2, $color, $width, $style) {
            $this->line($x1, $y1, $x2, $y2, $color, $width, $style);
        });
    }

    /**
     * @return int
     */
    public function new_page()
    {
        SimpleLogger::log('cpdf_logs', '6. ' . __FUNCTION__, "Creating new page");
        $this->_page_number++;
        $this->_page_count++;

        $ret = $this->_pdf->newPage();
        $this->_pages[] = $ret;
        return $ret;
    }

    protected function processPageScript(callable $callback): void
    {
        SimpleLogger::log('cpdf_logs', '61. ' . __FUNCTION__, "Processing page script callback");
        $pageNumber = 1;

        foreach ($this->_pages as $pid) {
            $this->reopen_object($pid);

            $fontMetrics = $this->_dompdf->getFontMetrics();
            $callback($pageNumber, $this->_page_count, $this, $fontMetrics);

            $this->close_object();
            $pageNumber++;
        }
    }

    public function stream($filename = "document.pdf", $options = [])
    {
        SimpleLogger::log('cpdf_logs', '41. ' . __FUNCTION__, "Streaming PDF: {$filename}");
        if (headers_sent()) {
            die("Unable to stream pdf: headers already sent");
        }

        if (!isset($options["compress"])) $options["compress"] = true;
        if (!isset($options["Attachment"])) $options["Attachment"] = true;

        $debug = !$options['compress'];
        $tmp = ltrim($this->_pdf->output($debug));

        header("Cache-Control: private");
        header("Content-Type: application/pdf");
        header("Content-Length: " . mb_strlen($tmp, "8bit"));

        $filename = str_replace(["\n", "'"], "", basename($filename, ".pdf")) . ".pdf";
        $attachment = $options["Attachment"] ? "attachment" : "inline";
        header(Helpers::buildContentDispositionHeader($attachment, $filename));

        echo $tmp;
        flush();
    }

    public function output($options = [])
    {
        SimpleLogger::log('cpdf_logs', '42. ' . __FUNCTION__, "Generating PDF output");
        if (!isset($options["compress"])) $options["compress"] = true;

        $debug = !$options['compress'];

        return $this->_pdf->output($debug);
    }

    /**
     * Returns logging messages generated by the Cpdf class
     *
     * @return string
     */
    public function get_messages()
    {
        SimpleLogger::log('cpdf_logs', '62. ' . __FUNCTION__, "Getting CPDF messages");
        return $this->_pdf->messages;
    }
}
