<?php

declare(strict_types=1);

namespace Tbank\Invest\Mcp;

final class Sse
{
    public static function message(mixed $data, string $id, string $event = 'message'): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'id: ' . $id . "\n"
            . 'event: ' . $event . "\n"
            . 'data: ' . $json . "\n\n";
    }

    public static function comment(string $text): string
    {
        return ': ' . str_replace(["\r", "\n"], ' ', $text) . "\n\n";
    }

    public static function retry(int $milliseconds): string
    {
        return 'retry: ' . $milliseconds . "\n\n";
    }
}
