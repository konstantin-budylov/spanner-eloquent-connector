<?php

namespace KonstantinBudylov\EloquentSpanner\Query;

use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesAsAlias;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesBatchMode;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesForceJoinOrder;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesGroupByScanOptimization;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesHashJoinBuildSide;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesJoinMethod;
use Colopl\Spanner\Query\Grammar;
use Illuminate\Database\Query\Builder as LaravelQueryBuilder;
use Illuminate\Database\Query\IndexHint;

/**
 * Support for join hints
 */
class SpannerQueryGrammar extends Grammar
{
    /**
     * @inheritDoc
     *
     * compiles hints and aliases atop of default from
     * https://github.com/cwhite92/framework/blob/5aa86f3c0955f186c550e9906cf0b564e67a9733/src/Illuminate/Database/Query/Grammars/Grammar.php#L163
     */
    protected function compileFrom(LaravelQueryBuilder $query, $table): string
    {
        assert($query instanceof SpannerQueryBuilder);

        return 'from ' . $this->wrapTable($table) . $this->compileTableHintExpr($query) . $this->compileAlias($query);
    }

    /**
     * Compile the "join" portions of the query.
     *
     * @param  LaravelQueryBuilder  $query
     * @param  array<mixed>  $joins
     * @return string
     */
    protected function compileJoins(LaravelQueryBuilder $query, $joins): string
    {
        return collect($joins)->map(function ($join) use ($query) {

            $table = $this->wrapTable(
                $join->table
            ) . $this->compileTableHintExpr($join) . $this->compileAlias($join);

            $nestedJoins = is_null($join->joins) ? '' : ' ' . $this->compileJoins($query, $join->joins);

            $tableAndNestedJoins = is_null($join->joins) ? $table : '(' . $table . $nestedJoins . ')';

            assert($join instanceof SpannerJoinClause, '$join must be App\Services/BaseSpanner\Query\SpannerJoinClause');

            return trim("{$join->type} join {$this->compileJoinHintExpr($join)} {$tableAndNestedJoins} {$this->compileWheres($join)}");
        })->implode(' ');
    }

    /**
     * @param  LaravelQueryBuilder  $query
     * @return string
     */
    protected function compileAlias($query): string
    {
        $alias = $query->asAlias ?? null;
        return $alias ? " as `$alias`" : '';
    }

    /**
     * @param LaravelQueryBuilder $query
     * @param IndexHint $indexHint
     * @return string
     */
    protected function compileIndexHint(LaravelQueryBuilder $query, $indexHint)
    {

        // use compileTableHintExpr instead
        return '';
    }

    /**
     * @param  LaravelQueryBuilder  $query
     * @return string
     */
    protected function compileTableHintExpr($query): string
    {
        $tableHints = [];

        $expression = $this->compileForceIndexTableHintKey($query);
        if (!empty($expression)) {
            $tableHints[] = $expression;
        }

        $expression = $this->compileGroupByScanOptimizationTableHintKey($query);
        if (!empty($expression)) {
            $tableHints[] = $expression;
        }

        return  !empty($tableHints) ?  "@{" . implode(",", $tableHints) . "}" : '';
    }

    /**
     * @param  LaravelQueryBuilder  $query
     * @return string
     */
    protected function compileForceIndexTableHintKey($query): string
    {
        $indexHint = $query->indexHint;

        /** @phpstan-ignore-next-line */
        if (!$indexHint || $indexHint->index === null) {
            return '';
        }
        return match ($indexHint->type) {
            'force' => "FORCE_INDEX={$indexHint->index}",
            default => $this->markAsNotSupported('index type: ' . $indexHint->type),
        };
    }

    /**
     * @param  LaravelQueryBuilder  $query
     * @return string
     */
    protected function compileGroupByScanOptimizationTableHintKey($query): string
    {
        $groupByScanOptimization = $query->groupByScanOptimization ?? null;
        if ($groupByScanOptimization === null) {
            return '';
        }

        return "GROUPBY_SCAN_OPTIMIZATION=" . ($groupByScanOptimization ? "TRUE" : "FALSE");
    }

    protected function compileJoinHintExpr(SpannerJoinClause $join): string
    {
        $joinHintExpressions = [];

        $expression = $this->compileForceJoinOrderJoinHintKey($join);
        if (!empty($expression)) {
            $joinHintExpressions[] = $expression;
        }

        $expression = $this->compileJoinMethodJoinHintKey($join);
        if (!empty($expression)) {
            $joinHintExpressions[] = $expression;
        }

        $expression = $this->compileHashJoinBuildSideJoinHintKey($join);
        if (!empty($expression)) {
            $joinHintExpressions[] = $expression;
        }

        $expression = $this->compileBatchModeJoinHintKey($join);
        if (!empty($expression)) {
            $joinHintExpressions[] = $expression;
        }

        return !empty($joinHintExpressions) ? "@{" . implode(",", $joinHintExpressions) . "}" : '';
    }

    protected function compileJoinMethodJoinHintKey(SpannerJoinClause $join): string
    {
        // HASH_JOIN
        // APPLY_JOIN

        $joinMethod = $join->joinMethod ?? null;

        return $joinMethod ? "JOIN_METHOD=$joinMethod" : '';
    }

    protected function compileForceJoinOrderJoinHintKey(SpannerJoinClause $join): string
    {
        if ($join->forceJoinOrder === null) {
            return '';
        }

        return "FORCE_JOIN_ORDER=" . ($join->forceJoinOrder ? "TRUE" : "FALSE");
    }

    protected function compileHashJoinBuildSideJoinHintKey(SpannerJoinClause $join): string
    {
        // BUILD_LEFT
        // BUILD_RIGHT

        $hashJoinBuildSide = $join->hashJoinBuildSide ?? null;

        return $hashJoinBuildSide ? "HASH_JOIN_BUILD_SIDE=$hashJoinBuildSide" : '';
    }

    protected function compileBatchModeJoinHintKey(SpannerJoinClause $join): string
    {
        if ($join->batchMode === null) {
            return '';
        }

        return "BATCH_MODE=" . ($join->batchMode ? "TRUE" : "FALSE");
    }

    /**
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * @return bool
     */
    public function supportsSavepoints()
    {
        return false;
    }
}
