<?php

namespace KonstantinBudylov\EloquentSpanner\Query\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Query\Grammar;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
trait AppliesJoinMethod
{
    /**
     * @var ?string
     */
    public $joinMethod;

    /**
     * @param string $joinMethod
     * @return $this
     */
    public function joinMethod(string $joinMethod)
    {
        // TODO add assert validation

        $this->joinMethod = $joinMethod;

        return $this;
    }
}
