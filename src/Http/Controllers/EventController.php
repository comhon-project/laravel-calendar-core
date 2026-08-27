<?php

namespace Comhon\Calendar\Http\Controllers;

use Carbon\Carbon;
use Closure;
use Comhon\Calendar\Contracts\ContextAuthorizerInterface;
use Comhon\Calendar\Contracts\HasScheduleInterface;
use Comhon\Calendar\Contracts\ParticipantScoperInterface;
use Comhon\Calendar\Http\Resources\EventResource;
use Comhon\Calendar\Http\Resources\HasScheduleResource;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Services\EventService;
use Comhon\MorphedModelExporter\Facades\MorphedModelExporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'participant_ids' => $this->getParticipantIdsRules($participantClass, true),
            ...$this->getBaseScopeValidation(),
            'embed_schedulable' => 'boolean',
            'context' => 'nullable|string|max:255',
            'embed_participants' => 'boolean',
            'embed_canceled' => 'boolean',
        ]);

        $this->authorize('view-any', [Event::class, $validated['participant_ids']]);
        $context = $this->resolveContext($request, $validated);

        $events = $participantClass::query()
            ->with([
                'events' => fn ($query) => $query->where(fn ($query) => $this->scopeEvents($query, $validated))
                    ->when($request->boolean('embed_canceled'), fn ($query) => $query->withTrashed()),
            ])
            ->findOrFail($validated['participant_ids'], (new $participantClass)->getKeyName())
            ->pluck('events')
            ->flatten();

        if ($request->boolean('embed_schedulable')) {
            MorphedModelExporter::loadMorphedModels($events, 'schedulable', $context);
        }
        if ($request->boolean('embed_participants')) {
            $this->loadParticipants($events);
        }

        return EventResource::collection($events);
    }

    public function listAuthUserEvents(Request $request)
    {
        /** @var Model|HasScheduleInterface $authUser */
        $authUser = Auth::user();
        $this->verifySameTable('calendar-core.participant_model', $authUser);

        return $this->listUserEvents($request, $authUser);
    }

    public function listUserEvents(Request $request, $user)
    {
        /** @var Model|HasScheduleInterface $user */
        $user = is_object($user) ? $user : app(config('calendar-core.participant_model'))->findOrFail($user);
        $this->verifyUserHasScheduleInterface($user);
        $this->authorize('view-user-events', [Event::class, $user]);

        $validated = $request->validate([
            ...$this->getBaseScopeValidation(),
            'include_as_creator' => 'boolean',
            'embed_schedulable' => 'boolean',
            'context' => 'nullable|string|max:255',
            'embed_participants' => 'boolean',
            'embed_canceled' => 'boolean',
        ]);
        $context = $this->resolveContext($request, $validated);

        $scopeEvents = fn ($query) => $this->scopeEvents($query, $validated);
        $events = $user->events()
            ->when($request->boolean('embed_canceled'), fn ($query) => $query->withTrashed())
            ->where($scopeEvents)->get();

        $includeAsCreator = $validated['include_as_creator'] ?? false;
        if ($includeAsCreator) {
            $this->verifySameTable('calendar-core.creator_model', $user);
            $creatorButNotParticipant = Event::query()
                ->when($request->boolean('embed_canceled'), fn ($query) => $query->withTrashed())
                ->where('creator_id', $user->getKey())
                ->whereDoesntHave('participants', fn ($query) => $query->where($user->getKeyName(), $user->getKey()))
                ->where($scopeEvents)
                ->get();

            $events = $events->merge($creatorButNotParticipant);
        }

        if ($request->boolean('embed_schedulable')) {
            MorphedModelExporter::loadMorphedModels($events, 'schedulable', $context);
        }
        if ($request->boolean('embed_participants')) {
            $this->loadParticipants($events);
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
    public function show(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        $validated = $request->validate([
            'embed_schedulable' => 'boolean',
            'context' => 'nullable|string|max:255',
            'embed_participants' => 'boolean',
        ]);
        $context = $this->resolveContext($request, $validated);

        $event->load('creator');
        if ($request->boolean('embed_schedulable')) {
            MorphedModelExporter::loadMorphedModels($event, 'schedulable', $context);
        }
        if ($request->boolean('embed_participants')) {
            $this->loadParticipants($event);
        }

        return new EventResource($event);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, EventService $eventService)
    {
        /** @var Model $authUser */
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
            'participant_ids' => $this->getParticipantIdsRules($participantClass, true),
        ]);

        $eventService->syncParticipants($event, $validated['participant_ids'], $validated['accepted'] ?? false);

        return response(null, 204);
    }

    public function detachParticipants(Request $request, EventService $eventService, Event $event)
    {
        $this->authorize('update', $event);

        $participantClass = config('calendar-core.participant_model');
        $validated = $request->validate([
            'participant_ids' => $this->getParticipantIdsRules($participantClass, true),
        ]);

        $eventService->detachParticipants($event, $validated['participant_ids']);

        return response(null, 204);
    }

    public function accept(Request $request, EventService $eventService, Event $event)
    {
        /** @var Model $participant */
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
                'participants.participant_ids' => $this->getParticipantIdsRules($participantClass, false),
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

    private function getParticipantIdsRules(string $modelClass, bool $required = false): array
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

    /**
     * Verify that the authenticated user may use the requested context,
     * then share it with event resources through the request attributes.
     *
     * @param  array  $inputs  must be already validated
     */
    private function resolveContext(Request $request, array $inputs): ?string
    {
        $context = $inputs['context'] ?? null;
        if ($context === null) {
            return null;
        }
        $authorized = app()->bound(ContextAuthorizerInterface::class)
            && app(ContextAuthorizerInterface::class)->authorize($context, Auth::user());
        if (! $authorized) {
            throw new AuthorizationException(__("unauthorized context ':context'", ['context' => $context]));
        }
        $request->attributes->set(EventResource::CONTEXT, $context);

        return $context;
    }

    /**
     * Load participants count and participants (limited by config to keep the payload bounded) on given events.
     */
    private function loadParticipants(Collection|Event $events): void
    {
        $participantClass = $this->verifyIsHasScheduleInterface('calendar-core.participant_model');

        app(EventService::class)->loadParticipants(
            $events,
            config('calendar-core.api.embed_participants_limit'),
            (new $participantClass)->getIdentityProperties()
        );
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

    public function verifyUserHasScheduleInterface(object $user)
    {
        if (! $user instanceof HasScheduleInterface) {
            throw new \Exception('the use model must be instanceof HasScheduleInterface');
        }
    }
}
