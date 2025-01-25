<?php

namespace Comhon\Calendar\Traits;

use Comhon\Calendar\Models\Event;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait SchedulableTrait
{
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'schedulable');
    }
}
