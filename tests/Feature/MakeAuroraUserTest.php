<?php

namespace Aurora\Tests\Feature;

use Aurora\Database\Seeders\RolesAndPermissionsSeeder;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class MakeAuroraUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_asks_filament_s_questions_and_the_role(): void
    {
        $this->artisan('make:aurora-user')
            ->expectsQuestion('Name', 'Kari Nordmann')
            ->expectsQuestion('Email address', 'kari@example.com')
            ->expectsQuestion('Password', 'secret-coffee')
            ->expectsQuestion('Which role should the user have?', Role::query()->where('name', 'Author')->value('id'))
            ->assertSuccessful();

        $user = User::query()->where('email', 'kari@example.com')->sole();

        $this->assertSame('Kari Nordmann', $user->name);
        $this->assertTrue(Hash::check('secret-coffee', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['Author'], $user->getRoleNames()->all());
    }

    public function test_it_runs_unattended_with_the_role_by_name(): void
    {
        $this->artisan('make:aurora-user', ['--no-interaction' => true, '--name' => 'Ola', '--email' => 'ola@example.com', '--password' => 'secret-coffee', '--role' => 'super admin'])
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'ola@example.com')->sole()->isSuperAdmin());
    }

    public function test_it_creates_no_one_with_an_unknown_role_or_a_taken_email_address(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $options = ['--no-interaction' => true, '--name' => 'Ola', '--password' => 'secret-coffee'];

        $this->artisan('make:aurora-user', [...$options, '--email' => 'ola@example.com', '--role' => 'Janitor'])
            ->expectsOutputToContain('There is no role "Janitor"')
            ->assertFailed();

        $this->artisan('make:aurora-user', [...$options, '--email' => 'taken@example.com', '--role' => 'Editor'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->artisan('make:aurora-user', [...$options, '--email' => 'ola@example.com'])
            ->expectsOutputToContain('Pass --name, --email, --password and --role')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}
