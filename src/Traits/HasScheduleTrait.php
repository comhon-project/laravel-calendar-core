<?php

namespace Comhon\Calendar\Traits;

use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Models\EventParticipant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasScheduleTrait
{
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'calendar_event_participants', 'participant_id', 'event_id')
            ->using(EventParticipant::class)
            ->withPivot(['accepted', 'accept_choice_at']);
    }
}
