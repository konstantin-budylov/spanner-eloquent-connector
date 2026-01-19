<?php

namespace KonstantinBudylov\EloquentSpanner\Eloquent\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Support for binary uuid keys
 *
 * @template TRelatedModel of Model
 * @extends BelongsToMany<TRelatedModel>
 */
class SpannerBelongsToMany extends BelongsToMany
{
    use HasValueObjectKeys;

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  Collection<string, Model>  $results
     * @return array<mixed>
     */
    protected function buildDictionary(Collection $results)
    {
        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[(string) $result->{$this->accessor}->{$this->foreignPivotKey}][] = $result;
        }

        return $dictionary;
    }

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
        $dictionary = $this->buildDictionary($results);

        // Once we have an array dictionary of child objects we can easily match the
        // children back to their parent using the dictionary and the keys on the
        // the parent models. Then we will return the hydrated models back out.
        foreach ($models as $model) {
            if (isset($dictionary[$key = (string) $model->{$this->parentKey}])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key])
                );
            }
        }

        return $models;
    }

}
