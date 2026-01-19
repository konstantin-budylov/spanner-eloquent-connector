<?php

namespace KonstantinBudylov\EloquentSpanner\Schema;

use Closure;
use Colopl\Spanner\Schema\Builder as ColoplSchemaBuilder;
use KonstantinBudylov\Services\BaseSpanner\BaseSpannerConnection;

/**
 * @property BaseSpannerSchemaGrammar $grammar
 * @property Closure|null $resolver
 */
class BaseSpannerSchemaBuilder extends ColoplSchemaBuilder
{
    /**
     * @inheritDoc
     */
    protected function createBlueprint($table, Closure $callback = null)
    {
        return isset($this->resolver)
            ? call_user_func($this->resolver, $table, $callback)
            : new BaseSpannerSchemaBlueprint($table, $callback);
    }

    /**
     * @return string[]
     */
    public function getTableListing()
    {
        $results = $this->connection->select(
            $this->grammar->compileTableListing()
        );

        return array_column($results, 'table_name');
    }

    /**
     * @param string $table
     * @param string $name
     * @param array<string> $columns
     */
    public function createIndex($table, $name, array $columns): void
    {
        $blueprint = $this->createBlueprint($table);
        $blueprint->index($columns, $name);
        $this->build($blueprint);
    }

    /**
     * @param string $table
     * @param string $column
     */
    public function isGeneratedColumn($table, $column): bool
    {
        $columnData = $this->connection->select(
            $this->grammar->compileFullColumnListing(),
            [$table, $column]
        );

        return $columnData[0]['IS_GENERATED'] == 'ALWAYS';
    }
}
