<?php

namespace KonstantinBudylov\EloquentSpanner\Schema;

use Colopl\Spanner\Schema\Grammar as ColoplGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

/**
 * Schema grammar
 */
class BaseSpannerSchemaGrammar extends ColoplGrammar
{
    /**
     * @inheritDoc
     *
     * Add support for Generated
     */
    protected $modifiers = ['Nullable', 'Default', 'Generated'];

    /**
     * Create the column definition for a decimal type.
     *
     * @inheritDoc
     *
     * @param  Fluent<string, mixed>  $column
     * @return string
     */
    protected function typeNumeric(Fluent $column)
    {
        return "numeric";
    }

    /**
     * Compile an add column command.
     *
     * @inheritDoc
     * @return string|string[]
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        return $this->createAlterForEachColumn($blueprint, $this->prefixArray('ADD COLUMN', $this->getColumns($blueprint)));
    }

    /**
     * Compile a change column command into a series of SQL statements.
     *
     * @inheritDoc
     * @return string[]
     */
    public function compileChange(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        return $this->createAlterForEachColumn($blueprint, $this->prefixArray('ALTER COLUMN', $this->getChangedColumns($blueprint)));
    }

    /**
     * Compile a drop column command.
     * @return string|string[]
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        return $this->createAlterForEachColumn(
            $blueprint,
            $this->prefixArray(
                'DROP COLUMN',
                $this->wrapArray($command->columns ?? [])
            )
        );
    }

    /**
     * Compile alert table for each column.
     *
     * @param  Blueprint  $blueprint
     * @param  array<string>  $columns
     * @return string[]
     */
    private function createAlterForEachColumn($blueprint, array $columns)
    {
        $result = [];
        foreach ($columns as $column) {
            $result[] = 'ALTER TABLE ' . $this->wrapTable($blueprint) . ' ' . $column;
        }

        return $result;
    }

    /**
     * @return string
     */
    public function compileTableListing()
    {
        return "SELECT table_name FROM information_schema.tables WHERE table_catalog = '' AND table_schema = ''";
    }

    /**
     * Create the column definition for a generatable column.
     *
     * @inheritDoc
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent<string, mixed>  $column
     * @return ?string
     */
    protected function modifyGenerated(Blueprint $blueprint, Fluent $column)
    {
        return isset($column->generatedAs) ? " AS ($column->generatedAs) STORED" : null;
    }

    /**
     * @return string
     */
    public function compileFullColumnListing()
    {
        return 'SELECT * FROM information_schema.columns WHERE table_schema = \'\' AND table_name = ? AND column_name = ?';
    }

    /**
     * Create the column definition for a decimal type.
     * migration: $table->addColumn('json', 'json_column_name')->nullable();
     *
     * @param  Fluent<string, mixed>  $column
     * @return string
     */
    protected function typeJson(Fluent $column)
    {
        return "json";
    }
}
