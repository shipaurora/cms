<?php

namespace Aurora\Database\Factories;

use Aurora\Models\SharedSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedSection>
 */
class SharedSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'blocks' => [
                ['id' => fake()->uuid(), 'type' => 'heading', 'data' => ['text' => 'Shared heading', 'level' => 'h2']],
            ],
        ];
    }
}
