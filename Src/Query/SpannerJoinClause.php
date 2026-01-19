<?php

namespace KonstantinBudylov\EloquentSpanner\Query;

use KonstantinBudylov\Services\BaseSpanner\Connection as BaseConnection;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesAsAlias;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesBatchMode;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesForceJoinOrder;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesGroupByScanOptimization;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesHashJoinBuildSide;
use KonstantinBudylov\EloquentSpanner\Query\Concerns\AppliesJoinMethod;
use Illuminate\Database\Query\JoinClause;

/**
 * Support api-data helpers
 */
class SpannerJoinClause extends JoinClause
{
    use AppliesAsAlias;
    use AppliesBatchMode;
    use AppliesGroupByScanOptimization;
    use AppliesForceJoinOrder;
    use AppliesHashJoinBuildSide;
    use AppliesJoinMethod;

    public const JOIN_METHOD_HASH_JOIN = 'HASH_JOIN';
    public const JOIN_METHOD_APPLY_JOIN = 'APPLY_JOIN';

    public const HASH_JOIN_BUILD_SIDE_LEFT = 'BUILD_LEFT';
    public const HASH_JOIN_BUILD_SIDE_RIGHT = 'BUILD_RIGHT';
}
