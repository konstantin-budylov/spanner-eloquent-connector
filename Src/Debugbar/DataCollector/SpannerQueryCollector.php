<?php

namespace KonstantinBudylov\EloquentSpanner\Debugbar\DataCollector;

use Barryvdh\Debugbar\DataCollector\QueryCollector;
use Google\Cloud\Spanner\Bytes;

/**
 * Collects data about SQL statements executed with PDO
 *
 * Add support for BYTES(16)
 */
class SpannerQueryCollector extends QueryCollector
{
    /**
     * @var array<string>
     */
    protected $backtraceExcludePaths = [
        '/vendor/laravel/framework/src/Illuminate/Support',
        '/vendor/laravel/framework/src/Illuminate/Database',
        '/vendor/laravel/framework/src/Illuminate/Events',
        '/vendor/october/rain',
        '/vendor/barryvdh/laravel-debugbar',
        '/vendor/colopl/laravel-spanner',
        '/app/Services/BaseSpanner',
    ];

    /**
     * Mimic mysql_real_escape_string
     *
     * @param mixed $value
     */
    protected function emulateQuote($value): string
    {
        if ($value instanceof Bytes) {
            $stream = $value->get();
            if ($stream->getSize() <= config('debugbar.options.spanner-db.bytes_max_length', 32768)) {
                $value = "FROM_HEX('" . bin2hex((string) $stream) . "')";
                return $value;
            } else {
                return '[BINARY DATA]';
            }
        }
        return parent::emulateQuote($value);
    }
}
