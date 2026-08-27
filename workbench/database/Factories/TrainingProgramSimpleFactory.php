<?php

namespace Database\Factories;

use App\Models\TrainingProgramSimple;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingProgramSimple>
 */
class TrainingProgramSimpleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
        ];
    }
}
