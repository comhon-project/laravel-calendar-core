<?php

namespace Comhon\Calendar\Services;

use Carbon\Carbon;
use Comhon\Calendar\Contracts\SchedulableInterface;
use Comhon\Calendar\DTO\SchedulableSerie;
use Comhon\Calendar\Events\EventRescheduled;
use Comhon\Calendar\Events\ParticipantsAttached;
use Comhon\Calendar\Events\ParticipantsDetached;
use Comhon\Calendar\Events\ParticipationStatusSet;
use Comhon\Calendar\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function getSchedulableEventsQuery(SchedulableInterface $schedulable, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        if (! $schedulable instanceof Model) {
            throw new \Exception('$schedulable must be instance of eloquent Model');
        }

        return Event::whereHasMorph(
            'schedulable',
            get_class($schedulable),
            fn ($query) => $query->where($schedulable->getKeyName(), $schedulable->getKey())
        )->when(
            $from,
            fn ($query) => $query->where('end_at', '>', $from->copy()->tz('UTC'))
        )->when(
            $to,
            fn ($query) => $query->where('start_at', '<', $to->copy()->tz('UTC'))
        );
    }

    public function getSchedulableSerieEventsQuery(SchedulableSerie $schedulableSerie, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $relationship = $schedulableSerie->getSerieRelation();
        $relatedModel = $relationship->getRelated();

        return Event::whereHasMorph(
            'schedulable',
            get_class($relatedModel),
            fn ($query) => $query->whereIn($relatedModel->getKeyName(), $relationship->select($relatedModel->getKeyName()))
        )->when(
            $from,
            fn ($query) => $query->where('end_at', '>', $from->copy()->tz('UTC'))
        )->when(
            $to,
            fn ($query) => $query->where('start_at', '<', $to->copy()->tz('UTC'))
        );
    }

    /**
     * @param  Carbon|null  $syncFrom  if specified, retrieve only events that have been attached after the given date
     */
    public function getParticipantEventsQuery(
        Model $participant,
        SchedulableInterface|SchedulableSerie $schedulable,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?Carbon $syncFrom = null
    ): Builder {
        $query = $schedulable instanceof SchedulableSerie
            ? $this->getSchedulableSerieEventsQuery($schedulable, $from, $to)
            : $this->getSchedulableEventsQuery($schedulable, $from, $to);

        return $query->whereHas('participants', function ($query) use ($participant, $syncFrom) {
            $query->where($participant->getKeyName(), $participant->getKey())
                ->when($syncFrom, fn ($query) => $query->where('calendar_event_participants.created_at', '>=', $syncFrom));
        });
    }

    /**
     * attach given participants to event (keep existing participants unchanged).
     *
     * @return Collection participants who have actually been attached.
     */
    public function syncParticipants(Event $event, array|Collection $participantIds, bool $accepted = false, bool $fireEvent = true)
    {
        $participantKeyName = $event->participants()->getRelated()->getKeyName();
        $alreadyAttachedIds = $event->participants()->whereIn($participantKeyName, $participantIds)->pluck($participantKeyName);
        $toAttachIds = collect($participantIds)->diff($alreadyAttachedIds)->values();

        if ($toAttachIds->isNotEmpty()) {
            DB::transaction(function () use ($event, $toAttachIds, $accepted, $fireEvent) {
                $event->participants()->syncWithoutDetaching(
                    $toAttachIds->mapWithKeys(fn ($id) => [$id => [
                        'accepted' => $accepted ?: null,
                        'accept_choice_at' => $accepted ? Carbon::now() : null,
                    ]])->all()
                );
                if ($fireEvent) {
                    $class = config('calendar-core.participant_model');
                    ParticipantsAttached::dispatch($event, $class::findOrFail($toAttachIds), $accepted);
                }
            });
        }

        return $toAttachIds;
    }

    /**
     * attach given participants (keep unreferenced participants).
     *
     * @return Collection participants who have actually been attached.
     */
    public function syncParticipantsFromQuery(
        Builder $eventQuery,
        array|Collection $participantIds,
        bool $accepted = false,
    ) {
        $attached = collect();

        DB::transaction(function () use ($eventQuery, $participantIds, $accepted, &$attached) {
            foreach ($eventQuery->lazyById() as $event) {
                $attachedEvent = $this->syncParticipants($event, $participantIds, $accepted, false);
                $attached = $attached->union($attachedEvent->flip());
            }
            $attached = $attached->keys();
        });

        return $attached;
    }

    /**
     * detach given participants from event.
     *
     * @return Collection participants who have actually been detached.
     */
    public function detachParticipants(Event $event, array|Collection $participantIds, bool $fireEvent = true)
    {
        $participantKeyName = $event->participants()->getRelated()->getKeyName();
        $alreadyAttachedIds = $event->participants()->whereIn($participantKeyName, $participantIds)->pluck($participantKeyName);
        $toDetachIds = collect($participantIds)->intersect($alreadyAttachedIds)->values();

        if ($toDetachIds->isNotEmpty()) {
            DB::transaction(function () use ($event, $toDetachIds, $fireEvent) {
                $event->participants()->detach($toDetachIds);
                if ($fireEvent) {
                    ParticipantsDetached::dispatch($event, $event->participants()->find($toDetachIds));
                }
            });
        }

        return $toDetachIds;
    }

    public function detachParticipantsFromQuery(Builder $eventQuery, array|Collection $participantIds)
    {
        $detached = collect();

        DB::transaction(function () use ($eventQuery, $participantIds, &$detached) {
            foreach ($eventQuery->lazyById() as $event) {
                $detachedEvent = $this->detachParticipants($event, $participantIds, false);
                $detached = $detached->union($detachedEvent->flip());
            }
            $detached = $detached->keys();
        });

        return $detached;
    }

    public function reschedule(Event $event, Carbon $startAt, Carbon $endAt)
    {
        if ($endAt <= $startAt) {
            throw new \Exception('$endAt must be after $startAt');
        }
        DB::transaction(function () use ($event, $startAt, $endAt) {
            $event->update([
                'start_at' => $startAt->copy()->tz('UTC'),
                'end_at' => $endAt->copy()->tz('UTC'),
            ]);
            EventRescheduled::dispatch($event);
        });
    }

    public function setParticipationStatus(Event $event, Model $participant, bool $accept, bool $fireEvent = true)
    {
        if (! $event->participants()->where($participant->getKeyName(), $participant->getKey())->exists()) {
            throw new \Exception("participant '{$participant->getKey()}' doesn't belong to event '{$event->id}'");
        }
        DB::transaction(function () use ($event, $participant, $accept, $fireEvent) {
            $event->participants()->syncWithoutDetaching([$participant->getKey() => [
                'accepted' => $accept,
                'accept_choice_at' => Carbon::now(),
            ]]);
            if ($fireEvent) {
                ParticipationStatusSet::dispatch($event, $participant, $accept);
            }
        });
    }

    public function setParticipationStatusFromQuery(Builder $eventQuery, Model $participant, bool $accept)
    {
        DB::table('calendar_event_participants')->where('participant_id', $participant->getKey())
            ->whereIn('event_id', $eventQuery->select('id'))
            ->update([
                'accepted' => $accept,
                'accept_choice_at' => Carbon::now(),
            ]);
    }

    public function cancel(Event $event, ?string $cancellationReason = null)
    {
        if ($cancellationReason) {
            $event->cancellation_reason = $cancellationReason;
            $event->save();
        }
        $event->delete();
    }

    /**
     * Cancel events that match with query.
     *
     * Since events models are not loaded (mass update), there are no model events triggered.
     */
    public function cancelFromQuery(Builder $eventQuery, ?string $cancellationReason = null)
    {
        if ($cancellationReason) {
            $eventQuery->update(['cancellation_reason' => $cancellationReason]);
        }
        $eventQuery->delete();
    }
}
