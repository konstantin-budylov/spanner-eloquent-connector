<?php

namespace KonstantinBudylov\EloquentSpanner;

interface ModelInteraface
{
    /**
     * Apply casting.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function applyCasts(string $key, $value);

    /**
     * Apply casting.
     *
     * @param  string  $key
     * @param  mixed  $values
     * @return mixed
     */
    public function applyCastsValues(string $key, $values);
}
