<?php

namespace Comhon\Calendar\Events;

use Comhon\Calendar\Models\Event;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipationStatusSet
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Event $event,
        public $participant,
        public bool $accepted
    ) {}
}
