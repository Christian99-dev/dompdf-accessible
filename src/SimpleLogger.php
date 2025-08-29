<?php
namespace Dompdf;

class SimpleLogger
{
    private static $enabledChannels = [];

    public static function enableChannel(string $channel): void
    {
        self::$enabledChannels[$channel] = true;
    }

    public static function log(string $channel, string $functionName, string $message): void
    {
        if (isset(self::$enabledChannels[$channel])) {
            // Use stderr to avoid interfering with stdout JSON output in tests
            fwrite(STDERR, "[$channel] " . str_pad($functionName . "():", 30, "_") . $message . "\n");
            // echo "[$channel] " . str_pad($functionName . "():", 30, "_") . $message . "\n";
        }
    }

    public static function logFrameTree(string $channel, string $functionName, $frameTree): void
    {
        if (!isset(self::$enabledChannels[$channel])) {
            return;
        }

        fwrite(STDERR, "[$channel] " . str_pad($functionName . "():", 30, "_") . "Frame Tree:\n");
        
        $formatFrame = function($frame, $depth = 0) use (&$formatFrame) {
            if ($frame === null) {
                return str_repeat("  ", $depth) . "NULL\n";
            }

            $indent = str_repeat("  ", $depth);
            $output = "";
            
            $frameType = get_class($frame);
            $frameType = substr($frameType, strrpos($frameType, '\\') + 1);
            
            $output .= $indent . "├── " . $frameType;
            
            // Add ID if available
            if (method_exists($frame, 'get_id')) {
                $output .= " [ID:" . $frame->get_id() . "]";
            }
            
            // FIXED: Get dimensions using the correct dompdf methods
            $positionInfo = "";
            try {
                // Position
                if (method_exists($frame, 'get_position')) {
                    $pos = $frame->get_position();
                    if ($pos && (is_array($pos) || is_object($pos))) {
                        $x = isset($pos['x']) ? $pos['x'] : (isset($pos->x) ? $pos->x : 0);
                        $y = isset($pos['y']) ? $pos['y'] : (isset($pos->y) ? $pos->y : 0);
                        $positionInfo .= sprintf("pos: x:%.1f, y:%.1f", $x, $y);
                    }
                }
                
                // Dimensions - use containing block or content box
                $width = 0;
                $height = 0;
                
                if (method_exists($frame, 'get_containing_block')) {
                    $cb = $frame->get_containing_block();
                    if ($cb && is_array($cb) && count($cb) >= 4) {
                        $width = $cb[2] - $cb[0];  // right - left
                        $height = $cb[3] - $cb[1]; // bottom - top
                    }
                }
                
                // Alternative: try get_content_box
                if ($width == 0 && $height == 0 && method_exists($frame, 'get_content_box')) {
                    $contentBox = $frame->get_content_box();
                    if ($contentBox && is_array($contentBox) && count($contentBox) >= 4) {
                        $width = $contentBox[2] - $contentBox[0];
                        $height = $contentBox[3] - $contentBox[1];
                    }
                }
                
                // Alternative: try get_border_box
                if ($width == 0 && $height == 0 && method_exists($frame, 'get_border_box')) {
                    $borderBox = $frame->get_border_box();
                    if ($borderBox && is_array($borderBox) && count($borderBox) >= 4) {
                        $width = $borderBox[2] - $borderBox[0];
                        $height = $borderBox[3] - $borderBox[1];
                    }
                }
                
                if ($positionInfo || $width > 0 || $height > 0) {
                    $output .= " [" . $positionInfo;
                    if ($width > 0 || $height > 0) {
                        $output .= sprintf(", w:%.1f, h:%.1f", $width, $height);
                    }
                    $output .= "]";
                }
                
            } catch (Exception $e) {
                $output .= " [Error getting dimensions: " . $e->getMessage() . "]";
            }
            
            // Node info
            if (method_exists($frame, 'get_node')) {
                $node = $frame->get_node();
                if ($node && method_exists($node, 'nodeName')) {
                    $output .= " <" . $node->nodeName . ">";
                    if (method_exists($node, 'getAttribute')) {
                        if ($node->getAttribute('class')) {
                            $output .= " class=\"" . $node->getAttribute('class') . "\"";
                        }
                        if ($node->getAttribute('id')) {
                            $output .= " id=\"" . $node->getAttribute('id') . "\"";
                        }
                    }
                }
            }
            
            $output .= "\n";
            
            // Get children
            $children = [];
            try {
                if (method_exists($frame, 'get_first_child')) {
                    $child = $frame->get_first_child();
                    while ($child) {
                        $children[] = $child;
                        $child = method_exists($child, 'get_next_sibling') ? $child->get_next_sibling() : null;
                    }
                }
            } catch (Exception $e) {
                $output .= $indent . "  └─ [Error: " . $e->getMessage() . "]\n";
            }
            
            // Output children
            foreach ($children as $child) {
                $output .= $formatFrame($child, $depth + 1);
            }
            
            return $output;
        };

        fwrite(STDERR, $formatFrame($frameTree) . "\n");
    }

    public static function setupFrameTreeCallback($dompdf, string $channel): void
    {
        if (!isset(self::$enabledChannels[$channel])) {
            return;
        }

        // Set up callback to capture frame tree during rendering
        $dompdf->setCallbacks([
            'frame_tree_analysis' => [
                'event' => 'begin_page_render',
                'f' => function ($frame) use ($channel) {
                    self::logFrameTree($channel, "callback_during_render", $frame);
                }
            ]
        ]);
    }
}