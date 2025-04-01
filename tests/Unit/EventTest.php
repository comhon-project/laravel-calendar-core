<?php

namespace Tests\Feature\Unit;

use Carbon\Carbon;
use Comhon\Calendar\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_duration()
    {
        $startAt = Carbon::now();
        $event = Event::factory(['start_at' => $startAt, 'end_at' => $startAt->copy()->addHours(2)->addMinutes(45)])->create();
        $this->assertEquals(165, $event->refresh()->duration);
    }
}
