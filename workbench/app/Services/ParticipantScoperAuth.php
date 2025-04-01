<?php

namespace App\Services;

use Comhon\Calendar\Contracts\ParticipantScoperInterface;
use Illuminate\Validation\Rules\Exists;

class ParticipantScoperAuth implements ParticipantScoperInterface
{
    public function scope(Exists $query, $authUser)
    {
        $query->where('id', $authUser->id);
    }
}
