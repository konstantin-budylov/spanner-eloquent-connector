<?php

namespace KonstantinBudylov\EloquentSpanner;

use Google\Cloud\Spanner\Numeric;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use JsonSerializable;

/**
 * Spanner Numeric field with casting to string.
 */
class SpannerNumeric extends Numeric implements
    JsonSerializable,
    Castable
{
    public function equals(?self $id): bool
    {
        return $this->formatAsString() === $id?->formatAsString();
    }

    /**
     * Format the value for json.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return (string) $this;
    }

    /**
     * Format the value for Datatables.
     *
     * Pre-convert via
     * vendor/yajra/laravel-datatables-oracle/src/Utilities/Helper.php
     * convertToArray
     *
     * @return mixed
     */
    public function makeVisible()
    {
        return (string) $this;
    }

    /**
     * Store static caster object.
     *
     * @var object|null
     */
    protected static $staticCaster = null;

    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @inheritDoc
     *
     * @param  array<mixed> $arguments
     * @return object|string
     */
    public static function castUsing(array $arguments)
    {
        if (self::$staticCaster) {
            return self::$staticCaster;
        }

        self::$staticCaster = new class implements CastsAttributes
        {
            /**
             * Handle
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @param  mixed  $value
             * @return mixed
             */
            protected function getObject($model, $value)
            {
                if (is_null($value)) {
                    return $value;
                }
                if (!SpannerBinaryUuid::isSpanner($model)) {
                    return $value;
                }
                if (! $value instanceof SpannerNumeric) {
                    return new SpannerNumeric($value);
                }
                return $value;
            }

            /**
             * Cast the given value.
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @param  string  $key
             * @param  mixed  $value
             * @param  array<mixed>  $attributes
             * @return mixed
             */
            public function get($model, $key, $value, $attributes)
            {
                return $this->getObject($model, $value);
            }

            /**
             * Prepare the given value for storage.
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @param  string  $key
             * @param  mixed  $value
             * @param  array<mixed>  $attributes
             * @return array<mixed>
             */
            public function set($model, $key, $value, $attributes)
            {
                return $this->getObject($model, $value);
            }
        };
        return self::$staticCaster;
    }
}
