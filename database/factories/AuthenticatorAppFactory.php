<?php

namespace Aurora\Database\Factories;

use Aurora\Models\AuthenticatorApp;
use Aurora\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<AuthenticatorApp>
 */
class AuthenticatorAppFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['iPhone', 'Pixel', '1Password', 'Work phone']),
            'secret' => (new Google2FA)->generateSecretKey(16),
        ];
    }
}
