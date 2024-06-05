<?php

namespace Spatie\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\ForwardsCalls;
use Spatie\Searchable\Exceptions\InvalidModelSearchAspect;
use Spatie\Searchable\Exceptions\InvalidSearchableModel;
use Laravel\Scout\Searchable as ScoutSearchable;

/**
 * @mixin Builder
 */
class ModelSearchAspect extends SearchAspect
{
    use ForwardsCalls;

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $model;

    /** @var array */
    protected $attributes = [];

    /** @var array */
    protected $callsToForward = [];

    /** @var bool */
    protected $usesScout = false;

    public static function forModel(string $model, ...$attributes): self
    {
        return new self($model, $attributes);
    }

    /**
     * @param string $model
     * @param array|\Closure $attributes
     *
     * @throws \Spatie\Searchable\Exceptions\InvalidSearchableModel
     */
    public function __construct(string $model, $attributes = [])
    {
        if (!is_subclass_of($model, Model::class)) {
            throw InvalidSearchableModel::notAModel($model);
        }

        $this->usesScout = in_array(ScoutSearchable::class, class_uses_recursive($model));

        if (
            !is_subclass_of($model, Searchable::class) ||
            !$this->usesScout
        ) {
            throw InvalidSearchableModel::modelDoesNotImplementSearchable($model);
        }

        $this->model = $model;

        if (is_array($attributes)) {
            $this->attributes = SearchableAttribute::createMany($attributes);

            return;
        }

        if (is_string($attributes)) {
            $this->attributes = SearchableAttribute::create($attributes);

            return;
        }

        if (is_callable($attributes)) {
            $callable = $attributes;

            $callable($this);

            return;
        }
    }

    public function addSearchableAttribute(string $attribute, bool $partial = true): self
    {
        $this->attributes[] = SearchableAttribute::create($attribute, $partial);

        return $this;
    }

    public function addExactSearchableAttribute(string $attribute): self
    {
        $this->attributes[] = SearchableAttribute::createExact($attribute);

        return $this;
    }

    public function getType(): string
    {
        $model = new $this->model();

        if (property_exists($model, 'searchableType')) {
            return $model->searchableType;
        }

        return $model->getTable();
    }

    public function getResults(string $term): Collection
    {
        if (empty($this->attributes)) {
            throw InvalidModelSearchAspect::noSearchableAttributes($this->model);
        }

        if ($this->usesScout) {
            return $this->getScoutResults($term);
        }

        return $this->getEloquentResults($term);
    }

    protected function getEloquentResults(string $term): Collection
    {
        $query = ($this->model)::query();

        $this->addSearchConditions($query, $term);

        foreach ($this->callsToForward as $callToForward) {
            $this->forwardCallTo($query, $callToForward['method'], $callToForward['parameters']);
        }

        if ($this->limit) {
            $query->limit($this->limit);
        }

        return $query->get();
    }

    protected function getScoutResults(string $term): Collection
    {
        $searchableAttributes = array_map(function ($attribute) {
            return $attribute->getAttribute();
        }, $this->attributes);

        // Perform a Scout search with specific attributes and forward function calls
        $results = ($this->model)::search($term, function ($searchEngine, string $query, array $options) use ($searchableAttributes) {
            $searchEngine->resetSearchableAttributes();
            $searchEngine->updateSearchableAttributes($searchableAttributes);
            return $searchEngine->search($query, $options);
        });

        // Apply forwarded calls
        foreach ($this->callsToForward as $callToForward) {
            $results = $results->{$callToForward['method']}(...$callToForward['parameters']);
        }

        if ($this->limit) {
            $results = $results->take($this->limit);
        }

        return $results->get();
    }

    protected function addSearchConditions(Builder $query, string $term)
    {
        $attributes = $this->attributes;
        $searchTerms = explode(' ', $term);

        $query->where(function (Builder $query) use ($attributes, $term, $searchTerms) {
            foreach (Arr::wrap($attributes) as $attribute) {
                $sql = "LOWER({$query->getGrammar()->wrap($attribute->getAttribute())}) LIKE ? ESCAPE ?";

                foreach ($searchTerms as $searchTerm) {
                    $searchTerm = mb_strtolower($searchTerm, 'UTF8');
                    $searchTerm = str_replace("\\", $this->getBackslashByPdo(), $searchTerm);
                    $searchTerm = addcslashes($searchTerm, "%_");

                    $attribute->isPartial()
                        ? $query->orWhereRaw($sql, ["%{$searchTerm}%", '\\'])
                        : $query->orWhere($attribute->getAttribute(), $searchTerm);
                }
            }
        });
    }

    protected function getBackslashByPdo()
    {
        $pdoDriver = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($pdoDriver === 'sqlite') {
            return '\\\\';
        }

        return '\\\\\\';
    }

    public function __call($method, $parameters)
    {
        $this->callsToForward[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        return $this;
    }
}
