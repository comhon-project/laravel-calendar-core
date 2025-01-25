<?php

namespace App\Models;

use Comhon\Calendar\Contracts\SchedulableSeriesInterface;
use Illuminate\Database\Eloquent\Model;

class BadSchedulableSeriesSerie extends Model implements SchedulableSeriesInterface
{
    public function series(): array
    {
        return ['foo'];
    }
}
