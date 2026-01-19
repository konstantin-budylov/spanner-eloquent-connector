<?php

namespace KonstantinBudylov\EloquentSpanner\Query\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Query\Grammar;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
trait AppliesAsAlias
{
    /**
     * @var ?string
     */
    public ?string $asAlias;

    /**
     * @param string $asAlias
     * @return $this
     */
    public function asAlias(string $asAlias)
    {
        $this->asAlias = $asAlias;

        return $this;
    }
}
