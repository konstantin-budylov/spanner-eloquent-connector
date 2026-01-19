<?php

namespace KonstantinBudylov\EloquentSpanner\Query;

use KonstantinBudylov\EloquentSpanner\Connection as ColoplConnection;
use KonstantinBudylov\EloquentSpanner\Query\SpannerQueryGrammar as ColoplGrammar;
use \Colopl\Spanner\Query\Builder as ColoplBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Processors\Processor;

/**
 * Builder with spanner support and casting bytes based Uuids.
 */
class SpannerQueryBuilder extends ColoplBuilder
{
    use AppliesAsAlias;
    use AppliesGroupByScanOptimization;

    private ?BaseSpannerModelInteraface $model;
    /**
     * Create a new query builder instance.
     */
    public function __construct(
        ColoplConnection $connection,
        ?ColoplGrammar $grammar = null,
        ?Processor $processor = null,
        ?BaseSpannerModelInteraface $model = null
    ) {
        $this->connection = $connection;
        $grammar ??= $connection->getQueryGrammar();
        assert($grammar instanceof ColoplGrammar);
        $this->grammar = $grammar;
        $this->processor = $processor ?: $connection->getPostProcessor();
        $this->model = $model;
    }

    /**
     * Add a basic where clause to the query.
     * add auto-casts for binary uuid
     *
     * @param  Expression|\Closure|string|array<mixed> $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
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
            return parent::where($column, $operator, $value, $boolean);
        }

        if (2 == func_num_args()) {
            $value = $operator;
        }

        if ($this->model) {
            $value = $this->model->applyCasts($column, $value);
        }

        if (2 == func_num_args()) {
            return parent::where($column, $value);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Add a "where in" clause to the query.
     * add auto-casts for binary uuid
     *
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (
            $column instanceof Expression
            || $values instanceof Closure
            || $this->isQueryable($values)
        ) {
            return parent::whereIn($column, $values, $boolean, $not);
        }

        if ($this->model) {
            $values = $this->model->applyCastsValues($column, $values);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Retrieve column values from rows represented as arrays.
     *
     * @param  array<mixed>  $queryResult
     * @param  string  $column
     * @param  ?string  $key
     * @return \Illuminate\Support\Collection<int|string, mixed>
     */
    protected function pluckFromArrayColumn($queryResult, $column, $key)
    {
        $results = [];

        if (is_null($key)) {
            foreach ($queryResult as $row) {
                $results[] = $row[$column];
            }
        } else {
            foreach ($queryResult as $row) {
                $results[(string) $row[$key]] = $row[$column];
            }
        }

        return collect($results);
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newQuery()
    {
        return new self($this->connection, $this->grammar, $this->processor, $this->model);
    }

    /**
     * Get a new join clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $parentQuery
     * @param  string  $type
     * @param  string  $table
     * @return \Illuminate\Database\Query\JoinClause
     */
    protected function newJoinClause(Builder $parentQuery, $type, $table)
    {
        return new SpannerJoinClause($parentQuery, $type, $table);
    }
}
