<?php

namespace KonstantinBudylov\EloquentSpanner\Query\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Query\Grammar;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
trait AppliesBatchMode
{
    /**
     * @var ?bool
     */
    public $batchMode = null;

    /**
     * @param bool $batchMode
     * @return $this
     */
    public function batchMode(bool $batchMode)
    {
        $this->batchMode = $batchMode;

        return $this;
    }
}
