<?php

namespace App\Models;

use Comhon\Calendar\Contracts\SchedulableSeriesInterface;

class BadSchedulableSeries implements SchedulableSeriesInterface
{
    public function series(): array
    {
        return ['foo'];
    }
}
