<?php

namespace Comhon\Calendar\Http\Controllers;

use Carbon\Carbon;
use Closure;
use Comhon\Calendar\Contracts\HasScheduleInterface;
use Comhon\Calendar\Contracts\ParticipantScoperInterface;
use Comhon\Calendar\Http\Resources\EventResource;
use Comhon\Calendar\Http\Resources\HasScheduleResource;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\EventService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $participantClass = $this->verifyIsHasScheduleInterface('calendar-core.participant_model');
        $validated = $request->validate([
            'participant_ids' => $this->GetParticipantIdsRules($participantClass, true),
            ...$this->getBaseScopeValidation(),
        ]);

        $this->authorize('view-any', [Event::class, $validated['participant_ids']]);

        $participants = $participantClass::query()
            ->with(['events' => fn ($query) => $this->scopeEvents($query, $validated)])
            ->findOrFail($validated['participant_ids'], (new $participantClass)->getKeyName());

        return EventResource::collection($participants->pluck('events')->flatten());
    }

    public function listUserEvents(Request $request)
    {
        /** @var \Illuminate\Database\Eloquent\Model|HasScheduleInterface $authUser */
        $authUser = Auth::user();
        $this->verifyUserHasScheduleInterface($authUser);
        $this->verifySameTable('calendar-core.participant_model', $authUser);
        $this->authorize('view-auth-user-events', Event::class);

        $validated = $request->validate([
            ...$this->getBaseScopeValidation(),
            'include_as_creator' => 'boolean',
        ]);

        $scopeEvents = fn ($query) => $this->scopeEvents($query, $validated);
        $events = $authUser->events()->where($scopeEvents)->get();

        $includeAsCreator = $validated['include_as_creator'] ?? false;
        if ($includeAsCreator) {
            $this->verifySameTable('calendar-core.creator_model', $authUser);
            $creatorButNotParticipant = Event::query()
                ->where('creator_id', $authUser->getKey())
                ->whereDoesntHave('participants', fn ($query) => $query->where($authUser->getKeyName(), $authUser->getKey()))
                ->where($scopeEvents)
                ->get();

            $events = $events->merge($creatorButNotParticipant);
        }

        return EventResource::collection($events);
    }

    private function getBaseScopeValidation(): array
    {
        return [
            'from' => 'required|date',
            'to' => 'required|date',
            'types' => 'nullable|array',
            'types.*' => 'nullable|string',
        ];
    }

    /**
     * @param  array  $inputs  must be already validated
     */
    private function scopeEvents($query, array $inputs)
    {
        $hasNullValue = isset($inputs['types']) && in_array(null, $inputs['types']);
        if ($hasNullValue) {
            $inputs['types'] = array_filter($inputs['types'], fn ($value) => $value !== null);
        }

        $query->where('end_at', '>', Carbon::parse($inputs['from'])->tz('UTC'))
            ->where('start_at', '<', Carbon::parse($inputs['to'])->tz('UTC'))
            ->where(function ($query) use ($inputs, $hasNullValue) {
                $query->when(
                    isset($inputs['types']),
                    fn ($query) => $query->whereIn('schedulable_type', $inputs['types'])
                )->when(
                    $hasNullValue,
                    fn ($query) => $query->orWhereNull('schedulable_type')
                );
            });
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event)
    {
        $this->authorize('view', $event);

        return new EventResource($event->load('creator'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, EventService $eventService)
    {
        /** @var \Illuminate\Database\Eloquent\Model $authUser */
        $authUser = Auth::user();
        $this->verifySameTable('calendar-core.creator_model', $authUser);

        $this->authorize('create', Event::class);

        $validated = $this->validateRequest($request);
        $event = DB::transaction(function () use ($validated, $eventService, $authUser) {
            $event = new Event($validated);
            $event->creator()->associate($authUser);
            $event->save();

            if (count($validated['participants']['participant_ids'] ?? [])) {
                $eventService->syncParticipants(
                    $event,
                    $validated['participants']['participant_ids'],
                    $validated['participants']['accepted'] ?? false
                );
            }

            return $event;
        });

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
        $participantClass = $this->verifyIsHasScheduleInterface('calendar-core.participant_model');
        $this->authorize('view', $event);

        /** @var HasScheduleInterface $model */
        $model = app($participantClass);
        $properties = $model->getIdentityProperties();

        return HasScheduleResource::collection($event->participants()->select($properties)->paginate());
    }

    public function syncParticipants(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('update', $event);

        $participantClass = config('calendar-core.participant_model');
        $validated = $request->validate([
            'accepted' => 'nullable|boolean',
            'participant_ids' => $this->GetParticipantIdsRules($participantClass, true),
        ]);

        $eventService->syncParticipants($event, $validated['participant_ids'], $validated['accepted'] ?? false);

        return response(null, 204);
    }

    public function detachParticipants(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('update', $event);

        $participantClass = config('calendar-core.participant_model');
        $validated = $request->validate([
            'participant_ids' => $this->GetParticipantIdsRules($participantClass, true),
        ]);

        $eventService->detachParticipants($event, $validated['participant_ids']);

        return response(null, 204);
    }

    public function accept(Request $request, EventService $eventService, Event $event)
    {
        /** @var \Illuminate\Database\Eloquent\Model $participant */
        $participant = Auth::user();
        $this->verifySameTable('calendar-core.participant_model', $participant);
        $this->authorize('accept', [$event, $participant]);

        $validated = $request->validate([
            'accept' => 'required|boolean',
        ]);

        $eventService->setParticipationStatus($event, $participant, $validated['accept']);

        return response(null, 204);
    }

    public function cancel(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('cancel', $event);

        $validated = $request->validate([
            'cancellation_reason' => 'string|max:255',
        ]);

        $eventService->cancel($event, $validated['cancellation_reason'] ?? null);

        return response(null, 204);
    }

    private function validateRequest(Request $request, ?Event $event = null)
    {
        $create = ! $event || ! $event->exists;
        $rules = [
            'name' => 'required|string|max:255',
        ];
        if ($create) {
            $participantClass = $this->verifyIsHasScheduleInterface('calendar-core.participant_model');
            $rules = [
                ...$rules,
                ...$this->getSchedulePropertiesRules(),
                'participants.participant_ids' => $this->GetParticipantIdsRules($participantClass, false),
                'participants.accepted' => 'nullable|boolean',

            ];
        }

        return $this->toUTC($request->validate($rules));
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

    private function GetParticipantIdsRules(string $modelClass, bool $required = false): array
    {
        $key = (new $modelClass)->getKeyName();

        return [
            $required ? 'required' : 'nullable',
            'array',
            function (string $attribute, mixed $value, Closure $fail) {
                foreach ($value as $element) {
                    if (! is_scalar($element)) {
                        $fail(__('each element must be a string or a number'));
                    }
                }
            },
            Rule::exists($modelClass, $key)->when(
                app()->bound(ParticipantScoperInterface::class),
                fn ($query) => app(ParticipantScoperInterface::class)->scope($query, Auth::user())
            ),
        ];
    }

    public function verifySameTable(string $configClassKey, Model $authUser)
    {
        if (app(config($configClassKey))->getTable() != $authUser->getTable()) {
            throw new \Exception("the config '$configClassKey' doesn't match with auth user model (must have same database table)");
        }
    }

    public function verifyIsHasScheduleInterface(string $configClassKey): string
    {
        $configClass = config($configClassKey);
        if (! is_subclass_of($configClass, HasScheduleInterface::class)) {
            throw new \Exception("the config '$configClassKey' must be instanceof HasScheduleInterface");
        }

        return $configClass;
    }

    public function verifyUserHasScheduleInterface(object $authUser)
    {
        if (! $authUser instanceof HasScheduleInterface) {
            throw new \Exception('the use model must be instanceof HasScheduleInterface');
        }
    }
}
