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
            echo "[{$channel}] | [{$functionName}](): {$message}\n";
        }
    }
}