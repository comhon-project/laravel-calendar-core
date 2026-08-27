<?php

namespace Comhon\Calendar\Database\Factories;

use Carbon\Carbon;
use Comhon\Calendar\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $creatorClass = config('calendar-core.creator_model');

        return [
            'name' => $this->faker->sentence(3),
            'creator_id' => $creatorClass::factory(),
            'start_at' => Carbon::now()->setSecond(0)->setMicrosecond(0),
            'end_at' => Carbon::now()->addHours(2)->setSecond(0)->setMicrosecond(0),
        ];
    }
}
