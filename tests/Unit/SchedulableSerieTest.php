<?php

namespace Tests\Feature\Unit;

use Comhon\Calendar\DTO\SchedulableSerie;
use Tests\Models\BadSchedulableSeries;
use Tests\Models\TrainingProgram;
use Tests\TestCase;

class SchedulableSerieTest extends TestCase
{
    public function testInvalidSchedulableSerieModel()
    {
        $this->expectExceptionMessage('$model must be instance of eloquent Model');
        new SchedulableSerie(new BadSchedulableSeries, 'foo');
    }

    public function testInvalidSchedulableSerieRelation()
    {
        $this->expectExceptionMessage('$serie must be a HasMany relationship name');
        new SchedulableSerie(new TrainingProgram, 'foo');
    }

    public function testGetModel()
    {
        $this->assertNotNull((new SchedulableSerie(new TrainingProgram, 'sessions'))->getModel());
    }

    public function testGetSerie()
    {
        $this->assertNotNull((new SchedulableSerie(new TrainingProgram, 'sessions'))->getSerie());
    }
}
