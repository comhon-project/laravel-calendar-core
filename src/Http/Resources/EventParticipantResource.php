<?php

namespace Comhon\Calendar\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EventParticipantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'participant_id' => $this->participant_id,
            'event_id' => $this->event_id,
            'accepted' => $this->accepted,
            'accept_choice_at' => $this->accept_choice_at,
        ];
    }
}
