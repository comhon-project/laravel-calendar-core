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
     * by default (if $from is null), only events not finished yet (end_at greater than current datetime) are updated.
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
     * by default (if $from is null), only events not finished yet (end_at greater than current datetime) are updated.
     *
     * @return Collection participants who have actually been detached.
     */
    public function detachParticipants(
        SchedulableSerie $schedulableSerie,
        array|Collection $participantIds,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
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
        $from ??= Carbon::now();

        DB::transaction(function () use ($schedulableSerie, $participant, $accept, $from, $to) {
            $eventQuery = $this->eventService->getSchedulableSerieEventsQuery($schedulableSerie, $from, $to);
            $this->eventService->setParticipationStatusFromQuery($eventQuery, $participant, $accept);
        });
    }

    public function cancelEvents(
        SchedulableSerie $schedulableSerie,
        ?string $cancellationReason = null,
        ?Carbon $from = null,
        ?Carbon $to = null
    ) {
        $from ??= Carbon::now();

        $this->eventService->cancelFromQuery(
            $this->eventService->getSchedulableSerieEventsQuery($schedulableSerie, $from, $to),
            $cancellationReason
        );
    }
}
