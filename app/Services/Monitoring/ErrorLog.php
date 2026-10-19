<?php

namespace App\Services\Monitoring;

/** Last errors of the application log (storage/logs/laravel.log): only its tail is read. */
class ErrorLog
{
    private const TAIL_BYTES = 262144;

    /** @return list<array{at: string, level: string, message: string}> newest first */
    public function recent(int $limit = 10): array
    {
        $path = storage_path('logs/laravel.log');
        $size = @filesize($path);
        if (! $size) {
            return [];
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $tail, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [
            'at' => $match[1],
            'level' => $match[2],
            'message' => mb_strimwidth($match[3], 0, 300, '…'),
        ], array_reverse(array_slice($matches, -$limit)));
    }
}
