<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent;

use KonstantinBudylov\Services\BaseSpanner\Query\SpannerQueryBuilder;
use KonstantinBudylov\EloquentSpanner\Helper;
use KonstantinBudylov\EloquentSpanner\ModelInteraface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

/**
 * Builder with spanner support and casting bytes based Uuids.
 *
 * @template TModelClass of Model
 * @extends Builder<TModelClass>
 */
class SpannerEloquentBuilder extends Builder
{
    /**
     * Add a basic where clause to the query.
     *
     * @param \Closure|string|array<mixed>|\Illuminate\Database\Query\Expression $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (
            $column instanceof Closure
            || is_array($column)
            || $value instanceof Closure
            || $column instanceof Expression
        ) {
            $result = parent::where($column, $operator, $value, $boolean);
            assert($result === $this);
            return $result;
        }

        if (2 == func_num_args()) {
            $value = $operator;
        }

        assert($this->model instanceof ModelInteraface);
        $value = $this->model->applyCasts($column, $value);

        if (2 == func_num_args()) {
            $result = parent::where($column, $value);
            assert($result === $this);
            return $result;
        }

        $result = parent::where($column, $operator, $value, $boolean);
        assert($result === $this);
        return $result;
    }

    /** @inheritdoc */
    public function get($columns = ['*']): Collection | array
    {
        $builder = $this->applyScopes();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.
        $modelsLoaded = [];
        if (count($models = $builder->getModels($columns)) > 0) {
            foreach (array_chunk($models, Helper::PARAMS_LIMIT) as $chunk) {
                $modelsLoaded = [...$modelsLoaded, ...$builder->eagerLoadRelations($chunk)];
            }
        }

        return $builder->getModel()->newCollection($modelsLoaded);
    }
}
