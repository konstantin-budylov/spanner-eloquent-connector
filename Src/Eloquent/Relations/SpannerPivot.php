<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

use KonstantinBudylov\Services\BaseSpanner\SpannerBinaryUuid;
use KonstantinBudylov\EloquentSpanner\ModelInteraface;
use KonstantinBudylov\EloquentSpanner\ModelTrait;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Support for binary uuid keys
 */
class SpannerPivot extends Pivot implements ModelInteraface
{
    use ModelTrait;
}
