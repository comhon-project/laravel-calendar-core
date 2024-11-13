<?php

namespace App\Policies\Calendar;

use App\Models\User;
use Carbon\Carbon;
use Comhon\Calendar\Models\Event;
use Illuminate\Auth\Access\HandlesAuthorization;

class EventPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user)
    {
        // TODO put your authorization logic here
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Event $event)
    {
        // TODO put your authorization logic here
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user)
    {
        // TODO put your authorization logic here
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Event $event)
    {
        if ($event->end_at < Carbon::now()) {
            return $this->deny(__('event is already finished'));
        }
        if ($user->is($event->creator)) {
            return true;
        }
    }

    /**
     * Determine whether the user can cancel the event.
     */
    public function cancel(User $user, Event $event)
    {
        return $this->update($user, $event);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Event $event)
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Event $event)
    {
        return false;
    }

    /**
     * Determine whether the user can set accepted status.
     */
    public function accept(User $user, Event $event, $participant)
    {
        if ($event->end_at < Carbon::now()) {
            return $this->deny(__('event is already finished'));
        }

        return $user->is($participant) && $event->participants()->where('id', $participant->id)->exists();
    }
}
