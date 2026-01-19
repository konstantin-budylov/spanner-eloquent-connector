<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use KonstantinBudylov\Services\BaseSpanner\SpannerBinaryUuid;

/**
 * Support for binary uuid keys
 */
trait SpannerHasOneOrManyTrait
{
    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param  array<Model> $models
     * @param  \Illuminate\Database\Eloquent\Collection<string, Model>  $results
     * @param  string  $relation
     * @param  string  $type
     * @return array<mixed>
     */
    protected function matchOneOrMany(array $models, Collection $results, $relation, $type)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = (string) $model->getAttribute($this->localKey)])) {
                $model->setRelation(
                    $relation,
                    $this->getRelationValue($dictionary, $key, $type)
                );
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<string, Model>  $results
     * @return array<mixed>
     */
    protected function buildDictionary(Collection $results)
    {
        $foreign = $this->getForeignKeyName();

        return $results->mapToDictionary(function ($result) use ($foreign) {
            return [(string) $result->{$foreign} => $result];
        })->all();
    }
}
