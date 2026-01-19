<?php

namespace KonstantinBudylov\EloquentSpanner\Query\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Query\Grammar;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
trait AppliesGroupByScanOptimization
{
    /**
     * @var ?bool
     */
    public $groupByScanOptimization;

    /**
     * @param bool $groupByScanOptimization
     * @return $this
     */
    public function groupByScanOptimization(bool $groupByScanOptimization)
    {
        $this->groupByScanOptimization = $groupByScanOptimization;

        return $this;
    }
}
