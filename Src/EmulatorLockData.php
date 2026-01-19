<?php

namespace KonstantinBudylov\EloquentSpanner;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Keep lock data
 */
class EmulatorLockData
{
    public function __construct(public ?Lock $lock, public ?PhpRedisConnection $redisLockConnection, public int $locksCount)
    {
    }
}
