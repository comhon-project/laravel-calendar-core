<?php

namespace Comhon\Calendar\Http\Resources;

use Comhon\MorphedModelExporter\Facades\MorphedModelExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * request attribute holding the call context, once verified by the controller
     */
    public const CONTEXT = 'calendar-core.context';

    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'creator_id' => $this->creator_id,
            'creator' => new HasScheduleResource($this->whenLoaded('creator')),
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'schedulable_id' => $this->schedulable_id,
            'schedulable_type' => $this->schedulable_type,
            'schedulable' => $this->whenLoaded('schedulable', fn ($schedulable) => MorphedModelExporter::exportModel(
                $schedulable,
                $request->attributes->get(self::CONTEXT)
            )),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'pivot' => $this->whenPivotLoaded('calendar_event_participants', function () {
                return new EventParticipantResource($this->pivot);
            }),
        ];
    }
}
