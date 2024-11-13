<?php

namespace Comhon\Calendar\Traits;

use Comhon\Calendar\Models\Event;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait SchedulableUniqueTrait
{
    public function event(): MorphOne
    {
        return $this->morphOne(Event::class, 'schedulable');
    }
}
