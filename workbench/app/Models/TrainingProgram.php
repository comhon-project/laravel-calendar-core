<?php

namespace App\Models;

use Comhon\Calendar\Contracts\SchedulableSeriesInterface;
use Comhon\Calendar\Models\Event;
use Comhon\Calendar\Observers\SchedulableObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([SchedulableObserver::class])]
class TrainingProgram extends Model implements SchedulableSeriesInterface
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    public function series(): array
    {
        return [
            'sessions',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function sessionEvents(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, TrainingSession::class, 'training_program_id', 'schedulable_id')
            ->where('schedulable_type', (new TrainingSession)->getMorphClass());
    }
}
