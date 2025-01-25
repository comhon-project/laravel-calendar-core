<?php

namespace Comhon\Calendar\Services;

use Carbon\Carbon;
use Comhon\Calendar\Contracts\SchedulableInterface;
use Comhon\Calendar\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SchedulableService
{
    public function __construct(private EventService $eventService) {}

    /**
     * attach given participants to events attached to given $schedulable (keep unreferenced participants).
     *
     * only events with end_at less than current datetime are updated.
     *
     * @return Collection participants who have actually been attached.
     */
    public function syncParticipants(
        SchedulableInterface $schedulable,
        array|Collection $participantIds,
        bool $accepted = false,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();
        $attached = collect();

        DB::transaction(function () use ($schedulable, $participantIds, $accepted, $from, $to, &$attached) {
            $eventQuery = $this->eventService->getSchedulableEventsQuery($schedulable, $from, $to);
            $attached = $this->eventService->syncParticipantsFromQuery($eventQuery, $participantIds, $accepted);
        });

        return $attached;
    }

    /**
     * detach given participants from events attached to given $schedulable.
     *
     * only events with end_at less than current datetime are updated.
     *
     * @return Collection participants who have actually been detached.
     */
    public function detachParticipants(
        SchedulableInterface $schedulable,
        array|Collection $participantIds,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();
        $detached = collect();

        DB::transaction(function () use ($schedulable, $participantIds, $from, $to, &$detached) {
            $eventQuery = $this->eventService->getSchedulableEventsQuery($schedulable, $from, $to);
            $detached = $this->eventService->detachParticipantsFromQuery($eventQuery, $participantIds);
        });

        return $detached;
    }

    public function setParticipationStatus(
        SchedulableInterface $schedulable,
        Model $participant,
        bool $accept,
        ?Carbon $from = null,
        ?Carbon $to = null
    ) {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();

        DB::transaction(function () use ($schedulable, $participant, $accept, $from, $to) {
            $eventQuery = $this->eventService->getSchedulableEventsQuery($schedulable, $from, $to);
            $this->eventService->setParticipationStatusFromQuery($eventQuery, $participant, $accept);
        });
    }

    public function schedule(SchedulableInterface $schedulable, Carbon $startAt, Carbon $endAt, Model $creator): Event
    {
        if (! $schedulable instanceof Model) {
            throw new \Exception('$schedulable must be instance of eloquent Model');
        }
        $this->verifyFromDate($startAt);
        if ($endAt <= $startAt) {
            throw new \Exception('$endAt must be after $startAt');
        }

        $lock = Cache::lock("schedule_event_{$schedulable->getKey()}_{$schedulable->getTable()}", 10);
        $event = $lock->get(function () use ($schedulable, $startAt, $endAt, $creator) {
            $query = $this->eventService->getSchedulableEventsQuery($schedulable);
            if ($query->count()) {
                throw new \Exception('Schedulable already has scheduling');
            }
            $event = new Event([
                'start_at' => $startAt->copy()->tz('UTC'),
                'end_at' => $endAt->copy()->tz('UTC'),
                'name' => $schedulable->getEventName(),
            ]);
            $event->creator()->associate($creator);
            $event->schedulable()->associate($schedulable);
            $event->save();

            return $event;
        });
        if (! $event) {
            throw new \Exception('already scheduling');
        }

        return $event;
    }

    public function reschedule(SchedulableInterface $schedulable, Carbon $startAt, Carbon $endAt)
    {
        $query = $this->eventService->getSchedulableEventsQuery($schedulable);
        $count = $query->count();
        if ($count == 0) {
            throw new \Exception('schedulable has no scheduling');
        }
        if ($count > 1) {
            throw new \Exception('cannot reschedule schedulable with several schedulings');
        }
        $this->eventService->reschedule($query->firstOrFail(), $startAt, $endAt);
    }

    public function cancelEvents(
        SchedulableInterface $schedulable,
        ?string $cancellationReason = null,
        ?Carbon $from = null,
        ?Carbon $to = null
    ) {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();

        $this->eventService->cancelFromQuery(
            $this->eventService->getSchedulableEventsQuery($schedulable, $from, $to),
            $cancellationReason
        );
    }

    public function verifyFromDate(?Carbon $from)
    {
        if ($from && $from < Carbon::now()) {
            throw new \Exception('date must be a future date');
        }
    }
}
