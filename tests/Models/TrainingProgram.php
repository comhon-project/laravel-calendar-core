<?php

namespace Tests\Models;

use Comhon\Calendar\Contracts\SchedulableSeriesInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
