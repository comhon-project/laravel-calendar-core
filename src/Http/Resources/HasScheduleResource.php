<?php

namespace Comhon\Calendar\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HasScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $array = [];
        foreach ($this->resource->getIdentityProperties() as $property) {
            $array[$property] = $this->{$property};
        }
        $array['pivot'] = $this->whenPivotLoaded('calendar_event_participants', function () {
            return new EventParticipantResource($this->pivot);
        });

        return $array;
    }
}
