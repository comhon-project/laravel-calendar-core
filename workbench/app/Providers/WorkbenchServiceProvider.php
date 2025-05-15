<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\TrainingProgram;
use App\Models\TrainingProgramSimple;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'program' => TrainingProgram::class,
            'session' => TrainingSession::class,
            'program-simple' => TrainingProgramSimple::class,
            'appointment' => Appointment::class,
        ]);
    }
}
