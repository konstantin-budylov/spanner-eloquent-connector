<?php

namespace KonstantinBudylov\EloquentSpanner;

use Google\Cloud\Spanner\Bytes;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Support\Str;
use JsonSerializable;
use Ramsey\Uuid\Uuid;

/**
 * Spanner Bytes field with casting bytes to uuids.
 */
class SpannerBinaryUuid extends Bytes implements
    JsonSerializable,
    Castable
{
    protected string $value;

    /**
     * Checks if it's spanner model
     *
     * @param mixed $model
     */
    public static function isSpanner($model): bool
    {
 /*       if (method_exists($model, 'getConnectionName')) {
            $driverName = config('database.connections.' . ($model->getConnectionName() ?? config("database.default")) . '.driver');
        } else {
            $driverName = $model->getConnection()->getDriverName();
        }
*/
        return true;
    }

    /**
     * Generate new random uuid bytes.
     */
    public static function randomUuidBytes(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    /**
     * @param string|resource|\Psr\Http\Message\StreamInterface|Bytes $value The bytes value.
     */
    public function __construct($value)
    {
        if ($value instanceof Bytes) {
            $this->assignBytes($value);
        } elseif (Str::isUuid($value)) {
            $this->value = $value;
        } elseif ($this->isValidHexadecimalUuid($value)) {
            $this->value = Uuid::fromString($value)->toString();
        } else {
            $bytes = new Bytes($value);
            $this->assignBytes($bytes);
        }
        $this->assignUuid($this->value);
    }

    public function equals(?self $id): bool
    {
        return $this->value === $id?->value;
    }

    /**
     * Assigning Bytes object
     */
    public function assignBytes(Bytes $value): void
    {
        $rawValue = base64_decode((string) $value);
        if (strlen($rawValue) == 0) {
            // empty
            $this->value = '';
        } else {
            if (strlen($rawValue) < 16) {
                // uuid requires 16 bytes
                $rawValue = str_pad($rawValue, 16, "\0", STR_PAD_LEFT);
            }
            $this->value = Uuid::fromBytes($rawValue)->toString();
        }
    }

    /**
     * Assigning Uuid
     */
    public function assignUuid(string $value): void
    {
        if (Str::isUuid($value)) {
            $bytes = Uuid::fromString($this->value)->getBytes();
            parent::__construct($bytes);
        }
    }

    /**
     * Sleep.
     */
    public function __sleep()
    {
        return ['value'];
    }

    /**
     * Wakeup.
     */
    public function __wakeup()
    {
        $this->assignUuid($this->value);
    }

    /**
     * Format the value as a string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->value;
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
     */
    public function makeVisible(): string
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
     * @param  array<mixed>  $arguments
     * @return object|string
     */
    public static function castUsing(array $arguments)
    {
        if (self::$staticCaster) {
            return self::$staticCaster;
        }

        self::$staticCaster = new class implements
            CastsAttributes,
            SerializesCastableAttributes
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
                if (! $value instanceof SpannerBinaryUuid) {
                    return new SpannerBinaryUuid($value);
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

            /**
             * Get the serialized representation of the value.
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @param  string  $key
             * @param  mixed  $value
             * @param  array<mixed>  $attributes
             * @return mixed
             */
            public function serialize($model, string $key, $value, array $attributes)
            {
                return (string) $value;
            }
        };
        return self::$staticCaster;
    }

    /**
     * Check if $value is Uuid a Hexadecimal
     *
     * @param string $value
     * @return bool
     */
    public function isValidHexadecimalUuid(string $value) : bool
    {
        $parsedUuid = str_replace(
            ['urn:', 'uuid:', 'URN:', 'UUID:', '{', '}', '-'],
            '',
            $value
        );

        $components = [
            substr($parsedUuid, 0, 8),
            substr($parsedUuid, 8, 4),
            substr($parsedUuid, 12, 4),
            substr($parsedUuid, 16, 4),
            substr($parsedUuid, 20),
        ];

        if (!Uuid::isValid(implode('-', $components))) {
            return false;
        }

        return true;
    }
}
