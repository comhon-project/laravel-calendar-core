<?php

namespace Comhon\Calendar\Http\Controllers;

use Carbon\Carbon;
use Closure;
use Comhon\Calendar\Http\Resources\EventResource;
use Comhon\Calendar\Http\Resources\HasScheduleResource;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\EventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('view-any', Event::class);

        $participantClass = config('calendar-core.participant_model');
        $properties = (new $participantClass)->getIdentityProperties();

        $validated = $request->validate([
            'participant_ids' => [
                'required',
                ...$this->getArrayExistsRule($participantClass),
            ],
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $participants = $participantClass::with(['events' => function ($query) use ($validated) {
            $query->where('end_at', '>', Carbon::parse($validated['from'])->tz('UTC'))
                ->where('start_at', '<', Carbon::parse($validated['to'])->tz('UTC'));
        }])->findOrFail($validated['participant_ids'], $properties);

        return EventResource::collection($participants->pluck('events')->flatten());
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event)
    {
        $this->authorize('view', $event);

        return new EventResource($event->load('participants', 'creator'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Event::class);

        $validated = $this->validateRequest($request);
        $event = new Event($validated);
        $event->creator()->associate(Auth::user());
        $event->save();

        return new EventResource($event);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $this->validateRequest($request, $event);

        $event->update($validated);

        return new EventResource($event);
    }

    /**
     * Update the specified resource in storage.
     */
    public function reschedule(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate($this->getSchedulePropertiesRules());
        $validated = $this->toUTC($validated);

        $eventService->reschedule($event, $validated['start_at'], $validated['end_at']);

        return new EventResource($event);
    }

    public function getParticipants(Event $event)
    {
        $this->authorize('view', $event);

        $participantClass = config('calendar-core.participant_model');
        $properties = (new $participantClass)->getIdentityProperties();

        return HasScheduleResource::collection($event->participants()->select($properties)->get());
    }

    public function syncParticipants(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('update', $event);

        $participantClass = config('calendar-core.participant_model');
        $validated = $request->validate([
            'accepted' => 'nullable|boolean',
            'participant_ids' => [
                'required',
                ...$this->getArrayExistsRule($participantClass),
            ],
        ]);

        $eventService->syncParticipants($event, $validated['participant_ids'], $validated['accepted'] ?? false);

        return response(null, 204);
    }

    public function detachParticipants(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('update', $event);

        $participantClass = config('calendar-core.participant_model');
        $validated = $request->validate([
            'participant_ids' => [
                'required',
                ...$this->getArrayExistsRule($participantClass),
            ],
        ]);

        $eventService->detachParticipants($event, $validated['participant_ids']);

        return response(null, 204);
    }

    public function accept(Request $request, EventService $eventService, Event $event)
    {
        /** @var \Illuminate\Database\Eloquent\Model $participant */
        $participant = Auth::user();
        $this->authorize('accept', [$event, $participant]);

        $validated = $request->validate([
            'accept' => 'required|boolean',
        ]);

        $eventService->setParticipationStatus($event, $participant, $validated['accept']);

        return response(null, 204);
    }

    public function cancel(EventService $eventService, Event $event)
    {
        $this->authorize('cancel', $event);

        $eventService->cancel($event);

        return response(null, 204);
    }

    private function validateRequest(Request $request, ?Event $event = null)
    {
        $create = ! $event || ! $event->exists;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            ...($create ? $this->getSchedulePropertiesRules() : []),
        ]);

        return $this->toUTC($validated);
    }

    /**
     * update the timezone to UTC in case the request contains datetimes in another timezone
     */
    private function toUTC(array $validated)
    {
        $dateTimes = ['start_at', 'end_at'];
        foreach ($dateTimes as $key) {
            if (isset($validated[$key])) {
                $validated[$key] = Carbon::parse($validated[$key])->tz('UTC');
            }
        }

        return $validated;
    }

    private function getSchedulePropertiesRules()
    {
        return [
            'start_at' => 'required|date|before:end_at',
            'end_at' => 'required|date|after:start_at',
        ];
    }

    private function getArrayExistsRule(string $modelClass): array
    {
        $key = (new $modelClass)->getKeyName();

        return [
            'array',
            function (string $attribute, mixed $value, Closure $fail) {
                foreach ($value as $element) {
                    if (! is_scalar($element)) {
                        $fail(__('each element must be a string or a number'));
                    }
                }
            },
            "exists:{$modelClass},{$key}",
        ];
    }
}
