<?php

namespace Comhon\Calendar\Services;

use Carbon\Carbon;
use Comhon\Calendar\DTO\SchedulableSerie;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SchedulableSerieService
{
    public function __construct(private EventService $eventService) {}

    /**
     * attach given participants to events attached to given $schedulableSerie (keep unreferenced participants).
     *
     * only events with end_at less than current datetime are updated.
     *
     * @return Collection participants who have actually been attached.
     */
    public function syncParticipants(
        SchedulableSerie $schedulableSerie,
        array|Collection $participantIds,
        bool $accepted = false,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();
        $attached = collect();

        DB::transaction(function () use ($schedulableSerie, $participantIds, $accepted, $from, $to, &$attached) {
            $eventQuery = $this->eventService->getSchedulableSerieEventsQuery($schedulableSerie, $from, $to);
            $attached = $this->eventService->syncParticipantsFromQuery($eventQuery, $participantIds, $accepted);
        });

        return $attached;
    }

    /**
     * detach given participants from events attached to given $schedulableSerie.
     *
     * only events with end_at less than current datetime are updated.
     *
     * @return Collection participants who have actually been detached.
     */
    public function detachParticipants(
        SchedulableSerie $schedulableSerie,
        array|Collection $participantIds,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();
        $detached = collect();

        DB::transaction(function () use ($schedulableSerie, $participantIds, $from, $to, &$detached) {
            $eventQuery = $this->eventService->getSchedulableSerieEventsQuery($schedulableSerie, $from, $to);
            $detached = $this->eventService->detachParticipantsFromQuery($eventQuery, $participantIds);
        });

        return $detached;
    }

    public function setParticipationStatus(
        SchedulableSerie $schedulableSerie,
        Model $participant,
        bool $accept,
        ?Carbon $from = null,
        ?Carbon $to = null
    ) {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();

        DB::transaction(function () use ($schedulableSerie, $participant, $accept, $from, $to) {
            $eventQuery = $this->eventService->getSchedulableSerieEventsQuery($schedulableSerie, $from, $to);
            $this->eventService->setParticipationStatusFromQuery($eventQuery, $participant, $accept);
        });
    }

    public function cancelEvents(SchedulableSerie $schedulableSerie, ?Carbon $from = null, ?Carbon $to = null)
    {
        $this->verifyFromDate($from);
        $from ??= Carbon::now();
        $this->eventService->getSchedulableSerieEventsQuery($schedulableSerie, $from, $to)->delete();
    }

    public function verifyFromDate(?Carbon $from)
    {
        if ($from && $from < Carbon::now()) {
            throw new \Exception('date must be a future date');
        }
    }
}
