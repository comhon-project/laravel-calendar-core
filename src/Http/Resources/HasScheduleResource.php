<?php

namespace Comhon\Calendar\Http\Resources;

use Comhon\Calendar\Contracts\HasIdentityInterface;
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
        $array = [$this->resource->getKeyName() => $this->resource->getKey()];
        if ($this->resource instanceof HasIdentityInterface) {
            $array += $this->resource->getIdentity();
        }
        $array['pivot'] = $this->whenPivotLoaded('calendar_event_participants', function () {
            return new EventParticipantResource($this->pivot);
        });

        return $array;
    }
}
