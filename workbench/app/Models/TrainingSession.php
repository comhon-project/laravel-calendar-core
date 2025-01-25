<?php

namespace App\Models;

use Comhon\Calendar\Contracts\SchedulableInterface;
use Comhon\Calendar\Traits\SchedulableUniqueTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingSession extends Model implements SchedulableInterface
{
    use HasFactory;
    use SchedulableUniqueTrait;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    public function getEventName(): string
    {
        return 'training session';
    }
}
