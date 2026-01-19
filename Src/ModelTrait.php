<?php

namespace KonstantinBudylov\EloquentSpanner;

use KonstantinBudylov\Services\BaseSpanner\Eloquent\Relations\SpannerBelongsTo;
use KonstantinBudylov\Services\BaseSpanner\Eloquent\Relations\SpannerBelongsToMany;
use KonstantinBudylov\Services\BaseSpanner\Eloquent\Relations\SpannerHasMany;
use KonstantinBudylov\Services\BaseSpanner\Eloquent\Relations\SpannerHasOne;
use KonstantinBudylov\Services\BaseSpanner\Eloquent\Relations\SpannerPivot;
use KonstantinBudylov\Services\BaseSpanner\Eloquent\SpannerEloquentBuilder;
use KonstantinBudylov\Services\BaseSpanner\Query\SpannerQueryBuilder;
use KonstantinBudylov\ValueObjects\Name64;
use Colopl\Spanner\Connection as ColoplConnection;
use Colopl\Spanner\Query\Grammar as ColoplGrammar;
use Exception;
use Google\Cloud\Spanner\Bytes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Dynamically switches spanner/mysql attributes when
 *  - connection uses base-spanner (and not named as spanner/sxope-spanner)
 *  - trait applied directly or via BaseMode
 *
 * For spanner:
 * - auto-generate int/string uuid/binary uuid ids
 * - if keytype is int then switch to spanner_binary_uuid, otherwise leave as is (string)
 * - cast spanner_binary_uuid uuids to SpannerBinaryUuid object compatible with serialization/datatables
 * - cast spanner_numeric to SpannerNumeric object compatible with serialization/datatables
 * - enforce conversion to int for int/integer casts
 *
 *  @property ?string $data_owner_id
 */
trait ModelTrait
{
    /**
     *  Initialize trait.
     *
     *  Replace all pseudo-casts with real classes
     */
    protected function initializeModelTrait(): void
    {
        if (SpannerBinaryUuid::isSpanner($this)) {
            // replace all casts with real classes
            $this->casts = str_replace(
                [
                    'spanner_binary_uuid',
                    'spanner_numeric',
                ],
                [
                    SpannerBinaryUuid::class,
                    SpannerNumeric::class,
                ],
                $this->casts
            );
        } else {
            // replace all casts with non-spanner classes
            if (array_key_exists('data_owner_id', $this->casts)) {
                $this->casts['data_owner_id'] = 'string';
            }

            $this->casts = str_replace(
                [
                    'spanner_binary_uuid',
                    'spanner_numeric',
                ],
                [
                    'integer',
                    'double',
                ],
                $this->casts
            );
        }
    }

    /**
     *  Setup model event hooks.
     */
    public static function bootModelTrait(): void
    {
        static::creating(function (self $model) {
            if (SpannerBinaryUuid::isSpanner($model) && !is_array($model->getKeyName())) {
                if ($model->hasId() === null) {
                    $type = $model->getKeyType();
                    switch ($type) {
                        case 'spanner_binary_uuid':
                        case SpannerBinaryUuid::class:
                        case 'string':
                            $model->{$model->getKeyName()} = (string) Uuid::uuid4()->toString();
                            break;
                        case 'int64':
                            $model->{$model->getKeyName()} = random_int(PHP_INT_MIN, PHP_INT_MAX);
                            break;
                        default:
                            throw new Exception("Unusupported by spanner primary key type : {$type}");
                    }
                }
                // auto-fill data_owner
                if (isset($model->getCasts()['data_owner_id']) && is_null($model->data_owner_id)) {
                    $model->data_owner_id = new SpannerBinaryUuid(getCurrentDataOwnerId());
                }
            }
        });
    }

    /**
     * @return bool
     *
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     */
    public function getIncrementing()
    {
        if (!SpannerBinaryUuid::isSpanner($this)) {
            return $this->incrementing;
        }
        return false;
    }

    /**
     * @return string
     */
    public function getKeyType()
    {
        if (!SpannerBinaryUuid::isSpanner($this)) {
            return $this->keyType;
        }
        if ($this->keyType == 'int') {
            return SpannerBinaryUuid::class;
        }
        return $this->keyType;
    }

    /**
     * Set a given attribute on the model.
     *
     * Spanner requires that integer field passed as integer, not as string
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        if (!SpannerBinaryUuid::isSpanner($this)) {
            return parent::setAttribute($key, $value);
        }

        if (
            $this->hasCast($key, ['int', 'integer'])
            && ! is_null($value)
            && ! is_int($value)
        ) {
            $value = (int) $value;
        }

        //@fixme replace by Name VO
        if ($this->isSha1($key)) {
            $value = trim($value);
            parent::setAttribute($key . '_hash', Name64::hash('sha1', $value));
        }

        //@fixme replace by Name VO
        if ($this->isSha256($key)) {
            $value = trim($value);
            parent::setAttribute($key . '_hash', Name64::hash('sha256', $value));
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     *
     * Replaces original function because casts doesn't work properly
     *
     * @param  string  $key
     * @return bool
     */
    public function originalIsEquivalent($key)
    {
        if (! array_key_exists($key, $this->original)) {
            return false;
        }

        $current = Arr::get($this->attributes, $key);
        $original = $this->getOriginal($key);

        if ($current === $original) {
            return true;
        } elseif (is_null($current)) {
            return false;
        } elseif ($this->isClassCastable($key)) {
            $castedCurrent = $this->castAttribute($key, $current);
            if (is_object($castedCurrent) && method_exists($castedCurrent, 'equals')) {
                return $castedCurrent->equals($original);
            }
        }

        return parent::originalIsEquivalent($key);
    }

    /**
     * Remove the table name from a given key.
     *
     * @param  string  $key
     * @return string
     */
    protected function baseRemoveTableFromKey($key)
    {
        return Str::contains($key, '.') ? last(explode('.', $key)) : $key;
    }

    /**
     * Apply casting.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function applyCasts(string $key, $value)
    {
        if (!SpannerBinaryUuid::isSpanner($this)) {
            return $value;
        }

        if (is_array($value)) {
            $value = head($value);
        }

        if (
            ! $this->isClassCastable($this->baseRemoveTableFromKey($key))
            || $this->getCasts()[$this->baseRemoveTableFromKey($key)] != SpannerBinaryUuid::class
            || ! is_string($value)
        ) {
            return $value;
        }

        // process uuids without dashes
        if (strlen($value) === 32 && preg_match('/^[\da-f]{32}$/iD', $value) > 0) {
            $value = Uuid::fromString($value)->toString();
        }

        $value = new SpannerBinaryUuid($value);

        return $value;
    }

    /**
     * Apply casting.
     *
     * @param  string  $key
     * @param  mixed  $values
     * @return mixed
     */
    public function applyCastsValues(string $key, $values)
    {
        if (
            ! SpannerBinaryUuid::isSpanner($this)
            || ! $this->isClassCastable($this->baseRemoveTableFromKey($key))
            || $this->getCasts()[$this->baseRemoveTableFromKey($key)] != SpannerBinaryUuid::class
        ) {
            return $values;
        }

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                // process uuids without dashes
                if (strlen($value) === 32 && preg_match('/^[\da-f]{32}$/iD', $value) > 0) {
                    $value = Uuid::fromString($value)->toString();
                }
                $values[$key] = new SpannerBinaryUuid($value);
            }
        }

        return $values;
    }

    /**
     * Instantiate a new HasOne relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<Model>
     */
    protected function newHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new SpannerHasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Instantiate a new BelongsTo relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @param  \Illuminate\Database\Eloquent\Model  $child
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @param  string  $relation
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Model, Model>
     */
    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey, $relation)
    {
        return new SpannerBelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Instantiate a new HasMany relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Model>
     */
    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new SpannerHasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Instantiate a new BelongsToMany relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string  $relationName
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Model>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null
    ) {
        return new SpannerBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return SpannerEloquentBuilder|Model|Builder<covariant Model>
     */
    public function newEloquentBuilder($query)
    {
        if (!SpannerBinaryUuid::isSpanner($this)) {
            return parent::newEloquentBuilder($query);
        }
        return new SpannerEloquentBuilder($query);
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newBaseQueryBuilder()
    {
        if (!SpannerBinaryUuid::isSpanner($this)) {
            return parent::newBaseQueryBuilder();
        }

        $connection = $this->getConnection();
        assert($connection instanceof ColoplConnection);

        $grammar = $connection->getQueryGrammar();
        assert($grammar instanceof ColoplGrammar);

        return new SpannerQueryBuilder(
            $connection,
            $grammar,
            $connection->getPostProcessor(),
            $this,
        );
    }

    /**
     * Create a new pivot model instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  array<mixed>  $attributes
     * @param  string  $table
     * @param  bool  $exists
     * @param  string|null  $using
     * @return \Illuminate\Database\Eloquent\Relations\Pivot
     */
    public function newPivot(Model $parent, array $attributes, $table, $exists, $using = null)
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
                      : SpannerPivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * Prepare the object for serialization.
     *
     * @return array
     */
    public function __sleep()
    {
        foreach ($this->attributes as $key => $value) {
            if (is_object($this->attributes[$key]) && get_class($this->attributes[$key]) == Bytes::class) {
                $this->attributes[$key] = new SpannerBinaryUuid($this->attributes[$key]);
            }
        }
        foreach ($this->original as $key => $value) {
            if (is_object($this->original[$key]) && get_class($this->original[$key]) == Bytes::class) {
                $this->original[$key] = new SpannerBinaryUuid($this->original[$key]);
            }
        }
        return parent::__sleep();
    }
}
