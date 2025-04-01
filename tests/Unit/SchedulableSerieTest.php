<?php

namespace Tests\Feature\Unit;

use App\Models\BadSchedulableSeries;
use App\Models\BadSchedulableSeriesSerie;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use Carbon\Carbon;
use Comhon\Calendar\DTO\SchedulableSerie;
use Comhon\Calendar\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulableSerieTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_schedulable_serie_model()
    {
        $this->expectExceptionMessage('$model must be instance of eloquent Model');
        new SchedulableSerie(new BadSchedulableSeries, 'foo');
    }

    public function test_not_reguistered_schedulable_serie_model()
    {
        $this->expectExceptionMessage('$serie is not registered as serie');
        new SchedulableSerie(new BadSchedulableSeries, 'bar');
    }

    public function test_invalid_schedulable_serie_relation()
    {
        $this->expectExceptionMessage('$serie must be a HasMany relationship name');
        new SchedulableSerie(new BadSchedulableSeriesSerie, 'foo');
    }

    public function test_get_model()
    {
        $this->assertNotNull((new SchedulableSerie(new TrainingProgram, 'sessions'))->getModel());
    }

    public function test_get_serie()
    {
        $this->assertNotNull((new SchedulableSerie(new TrainingProgram, 'sessions'))->getSerie());
    }

    public function test_has_many_through_relationship()
    {
        $programOne = TrainingProgram::factory()
            ->has(TrainingSession::factory()->has(Event::factory(), 'event'), 'sessions')
            ->create();

        $programTwo = TrainingProgram::factory()
            ->has(TrainingSession::factory()->has(Event::factory(), 'event'), 'sessions')
            ->has(TrainingSession::factory()->has(Event::factory([
                'start_at' => Carbon::now()->addDays(1),
                'end_at' => Carbon::now()->addDays(2),
            ]), 'event'), 'sessions')
            ->create();

        $programs = TrainingProgram::withSum('sessionEvents', 'duration')->get()->keyBy('id');
        $this->assertEquals(120, $programs[$programOne->id]->session_events_sum_duration);
        $this->assertEquals(1560, $programs[$programTwo->id]->session_events_sum_duration);
    }
}
