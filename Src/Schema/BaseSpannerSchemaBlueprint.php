<?php

namespace KonstantinBudylov\EloquentSpanner\Schema;

use Colopl\Spanner\Schema\Blueprint as ColoplBlueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Helper methods
 */
class BaseSpannerSchemaBlueprint extends ColoplBlueprint
{
    /**
     * BYTE(16) type
     *
     * @param string $column
     * @return ColumnDefinition
     */
    public function binaryUuid($column)
    {
        return $this->binary($column, 16);
    }

    /**
     * BYTE(32) type
     *
     * @param string $column
     * @return ColumnDefinition
     */
    public function binaryHash($column)
    {
        return $this->binary($column, 32);
    }

    /**
     * Create a new decimal column on the table.
     *
     * @param  string  $column
     * @param  int  $total
     * @param  int  $places
     * @return ColumnDefinition
     */
    public function numeric($column, $total = 8, $places = 2)
    {
        return $this->addColumn('numeric', $column, [
            'total' => $total,
            'places' => $places,
            'unsigned' => false,
        ]);
    }

    public function technical(int $precision = 0, bool $withDataOwner = true, bool $withUserNames = true): void
    {
        if ($withDataOwner) {
            $this->binaryUuid('data_owner_id');
        }
        $this->timestamp('created_at', $precision);
        $this->timestamp('updated_at', $precision);
        parent::softDeletes('deleted_at', $precision);
        $this->binaryUuid('created_at_day_id');
        $this->binaryUuid('updated_at_day_id');
        $this->binaryUuid('deleted_at_day_id')->nullable();
        if ($withUserNames) {
            $this->string('created_by_sxope_username_plaintext', 256);
            $this->string('updated_by_sxope_username_plaintext', 256);
            $this->string('deleted_by_sxope_username_plaintext', 256)->nullable();
        }
        $this->binaryUuid('created_by_sxope_user_id');
        $this->binaryUuid('updated_by_sxope_user_id');
        $this->binaryUuid('deleted_by_sxope_user_id')->nullable();
        $this->boolean('is_deleted')->generatedAs('deleted_at IS NOT NULL');
    }
}
