<?php

namespace Aurora\Database\Factories;

use Aurora\Enums\PageLayout;
use Aurora\Enums\PageStatus;
use Aurora\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'excerpt' => fake()->sentence(),
            'layout' => PageLayout::Default,
            'status' => PageStatus::Draft,
            'blocks' => [
                ['id' => fake()->uuid(), 'type' => 'heading', 'data' => ['text' => 'Hello', 'level' => 'h1']],
            ],
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => PageStatus::Published, 'published_at' => now()->subDay()]);
    }

    public function homepage(): static
    {
        return $this->published()->state(['is_homepage' => true, 'slug' => 'home']);
    }
}
