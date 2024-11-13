<?php

namespace Comhon\Calendar\Events;

use Comhon\Calendar\Models\Event;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantsDetached
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Event $event,
        public Collection $detached
    ) {}
}
