<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Support for binary uuid keys
 *
 * @template TChildModel of Model
 * @template TRelatedModel of Model
 * @extends BelongsTo<TRelatedModel, TChildModel>
 */
class SpannerBelongsTo extends BelongsTo
{
    use HasValueObjectKeys;

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array<mixed>  $models
     * @param  Collection<string, Model>  $results
     * @param  string  $relation
     * @return array<mixed>
     */
    public function match(array $models, Collection $results, $relation)
    {
        $foreign = $this->foreignKey;

        $owner = $this->ownerKey;

        // First we will get to build a dictionary of the child models by their primary
        // key of the relationship, then we can easily match the children back onto
        // the parents using that dictionary and the primary key of the children.
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[(string) $result->getAttribute($owner)] = $result;
        }

        // Once we have the dictionary constructed, we can loop through all the parents
        // and match back onto their children using these keys of the dictionary and
        // the primary key of the children to map them onto the correct instances.
        foreach ($models as $model) {
            if (isset($dictionary[(string) $model->{$foreign}])) {
                $model->setRelation($relation, $dictionary[(string) $model->{$foreign}]);
            }
        }

        return $models;
    }
}
