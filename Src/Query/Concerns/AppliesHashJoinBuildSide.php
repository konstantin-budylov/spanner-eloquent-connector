<?php

namespace KonstantinBudylov\EloquentSpanner\Query\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Query\Grammar;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
trait AppliesHashJoinBuildSide
{
    /**
     * @var ?string
     */
    public $hashJoinBuildSide;

    /**
     * @param string $hashJoinBuildSide
     * @return $this
     */
    public function hashJoinBuildSide(string $hashJoinBuildSide)
    {
        // TODO add assert validation

        $this->hashJoinBuildSide = $hashJoinBuildSide;

        return $this;
    }
}
