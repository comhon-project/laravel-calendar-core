<?php

namespace Comhon\Calendar\DTO;

use Comhon\Calendar\Contracts\SchedulableSeriesInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulableSerie
{
    /**
     * @param  string  $serie  a HasMany relationship name callable from $model
     */
    public function __construct(private SchedulableSeriesInterface $model, private string $serie)
    {
        if (! $model instanceof Model) {
            throw new \Exception('$model must be instance of eloquent Model');
        }
        if (! method_exists($model, $this->serie) || ! $this->model->{$this->serie}() instanceof HasMany) {
            throw new \Exception('$serie must be a HasMany relationship name');
        }
    }

    /**
     * @return SchedulableSeriesInterface|Model
     */
    public function getModel(): SchedulableSeriesInterface
    {
        return $this->model;
    }

    public function getSerie(): string
    {
        return $this->serie;
    }

    public function getSerieRelation(): HasMany
    {
        return $this->model->{$this->serie}();
    }
}
