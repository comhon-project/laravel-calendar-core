<?php

namespace App\Services;

use Comhon\Calendar\Contracts\ParticipantScoperInterface;
use Illuminate\Validation\Rules\Exists;

class ParticipantScoperAll implements ParticipantScoperInterface
{
    public function scope(Exists $query, $authUser)
    {
        // do nothing
    }
}
