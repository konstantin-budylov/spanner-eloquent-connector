<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Support for binary uuid keys
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @extends HasMany<TRelatedModel>
 */
class SpannerHasMany extends HasMany
{
    use SpannerHasOneOrManyTrait;
    use HasValueObjectKeys;
}
