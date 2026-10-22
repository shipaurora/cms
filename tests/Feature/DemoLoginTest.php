<?php

namespace Aurora\Tests\Feature;

use Aurora\Database\Seeders\RolesAndPermissionsSeeder;
use Aurora\Models\User;
use Aurora\Support\Demo;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The account this test offers for demo sign-in. The docs site offers none: a seeded
     * account's password would be in its public code.
     */
    protected const DEMO = 'demo@example.com';

    /**
     * The demo sign-in route only exists with AURORA_DEMO_LOGIN on, and routes are registered
     * when the application boots: switch it on before that.
     */
    protected function setUp(): void
    {
        $this->setDemoLogin('true');

        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['email' => self::DEMO])->syncRoles([User::SUPER_ADMIN]);
        Demo::register([['name' => 'Demo', 'email' => self::DEMO, 'role' => User::SUPER_ADMIN]]);
    }

    protected function tearDown(): void
    {
        Demo::forget();

        parent::tearDown();

        $this->setDemoLogin('false');
    }

    protected function setDemoLogin(string $value): void
    {
        putenv('AURORA_DEMO_LOGIN='.$value);
        $_ENV['AURORA_DEMO_LOGIN'] = $_SERVER['AURORA_DEMO_LOGIN'] = $value;
    }

    public function test_a_demo_account_signs_in_with_one_click(): void
    {
        $this->get(route('demo.login', self::DEMO))->assertRedirect('/admin');

        $this->assertAuthenticatedAs(User::query()->where('email', self::DEMO)->sole());
    }

    public function test_it_signs_in_as_no_one_but_the_active_demo_accounts(): void
    {
        User::factory()->create(['email' => 'boss@example.com']);
        User::query()->where('email', self::DEMO)->update(['is_active' => false]);

        $this->get(route('demo.login', 'boss@example.com'))->assertNotFound();
        $this->get(route('demo.login', self::DEMO))->assertNotFound();
        $this->assertGuest();
    }

    public function test_it_only_returns_to_addresses_on_this_site(): void
    {
        $this->get(route('demo.login', ['email' => self::DEMO, 'to' => '/admin/main/pages']))->assertRedirect('/admin/main/pages');
        $this->get(route('demo.login', ['email' => self::DEMO, 'to' => 'https://evil.example']))->assertRedirect('/admin');
        $this->get(route('demo.login', ['email' => self::DEMO, 'to' => '//evil.example']))->assertRedirect('/admin');
    }
}
