<?php

namespace Comhon\Calendar\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string|null
     */
    protected $table = 'calendar_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'start_at',
        'end_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(
            config('calendar-core.participant_model'),
            'calendar_event_participants',
            'event_id',
            'participant_id'
        )->using(EventParticipant::class)
            ->withPivot([
                'accepted',
                'accept_choice_at',
            ]);
    }

    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('calendar-core.creator_model'));
    }
}
