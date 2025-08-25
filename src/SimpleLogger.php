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
}