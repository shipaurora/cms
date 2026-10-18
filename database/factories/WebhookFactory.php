<?php

namespace Aurora\Database\Factories;

use Aurora\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => 'https://hooks.example.com/'.fake()->uuid(),
            'events' => ['page.published'],
            'is_active' => true,
        ];
    }
}
