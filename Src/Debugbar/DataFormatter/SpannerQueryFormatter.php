<?php

namespace KonstantinBudylov\EloquentSpanner\Debugbar\DataFormatter;

use Barryvdh\Debugbar\DataFormatter\QueryFormatter;

class SpannerQueryFormatter extends QueryFormatter
{
    /**
     * Check bindings for illegal (non UTF-8) strings, like Binary data.
     *
     * Original function encodes objects into json
     *
     * @param mixed $bindings
     * @return mixed
     */
    public function checkBindings(mixed $bindings): mixed
    {
        foreach ($bindings as &$binding) {
            if (is_string($binding) && !mb_check_encoding($binding, 'UTF-8')) {
                $binding = '[BINARY DATA]';
            }

            if (is_array($binding)) {
                $binding = $this->checkBindings($binding);
                $binding = '[' . implode(',', $binding) . ']';
            }

            /* skip encoding
            if (is_object($binding)) {
                $binding =  json_encode($binding);
            }
            */
        }

        return $bindings;
    }
}
