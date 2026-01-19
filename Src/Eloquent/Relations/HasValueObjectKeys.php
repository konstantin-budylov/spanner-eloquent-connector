<?php

declare(strict_types=1);

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

trait HasValueObjectKeys
{
    protected function getKeys(array $models, $key = null): array
    {
        $unique = [];
        $keys = parent::getKeys($models, $key);

        foreach ($keys as $k) {
            if (is_null($k)) {
                continue;
            } elseif (is_object($k)) {
                if (method_exists($k, 'toString')) {
                    $unique[$k->toString()] = $k;
                } elseif (method_exists($k, 'toInt')) {
                    $unique[$k->toInt()] = $k;
                } else {
                    $unique[(string) $k] = $k;
                }
            } elseif (is_scalar($k)) {
                return $keys;
            }
        }

        return $unique;
    }
}
