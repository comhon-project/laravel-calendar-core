<?php

namespace App\Models;

use Comhon\Calendar\Contracts\SchedulableInterface;

class BadSchedulable implements SchedulableInterface
{
    public function getEventName(): string
    {
        return 'foo';
    }
}
