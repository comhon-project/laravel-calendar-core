<?php

namespace Comhon\Calendar\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventParticipant extends Pivot
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string|null
     */
    protected $table = 'calendar_event_participants';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'accept_choice_at' => 'datetime',
        ];
    }
}
