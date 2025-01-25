<?php

namespace App\Models;

use Comhon\Calendar\Contracts\SchedulableInterface;
use Comhon\Calendar\Observers\SchedulableObserver;
use Comhon\Calendar\Traits\SchedulableTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([SchedulableObserver::class])]
class TrainingProgramSimple extends Model implements SchedulableInterface
{
    use HasFactory;
    use SchedulableTrait;
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
