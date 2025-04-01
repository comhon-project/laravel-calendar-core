<?php

namespace Comhon\Calendar\Contracts;

use Illuminate\Validation\Rules\Exists;

interface ParticipantScoperInterface
{
    /**
     * Scope users that can be associated to an event by the authenticated user.
     */
    public function scope(Exists $query, $authUser);
}
