<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Support for binary uuid keys
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @extends HasOne<TRelatedModel>
 */
class SpannerHasOne extends HasOne
{
    use SpannerHasOneOrManyTrait;
    use HasValueObjectKeys;
}
