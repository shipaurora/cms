<?php

namespace Aurora\Tests\Feature;

use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource;
use Aurora\Aurora;
use Aurora\Database\Seeders\RolesAndPermissionsSeeder;
use Aurora\Filament\Resources\Roles\RoleResource;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Providers\AuroraServiceProvider;
use Aurora\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as LaravelUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AuroraInstallsIntoAppsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_apps_empty_integer_users_table_is_made_again_with_uuids(): void
    {
        $this->useLaravelsUsersTable();

        $this->usersMigration()->up();

        $this->assertStringNotContainsString('int', strtolower(Schema::getColumnType('users', 'id')));
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertNotNull(User::factory()->create()->getKey());
    }

    public function test_an_app_without_laravel_s_users_migration_gets_its_tables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::enableForeignKeyConstraints();

        $this->usersMigration()->up();

        foreach (['users', 'password_reset_tokens', 'sessions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertStringNotContainsString('int', strtolower(Schema::getColumnType('users', 'id')));
        $this->assertStringNotContainsString('int', strtolower(Schema::getColumnType('sessions', 'user_id')));
    }

    public function test_a_users_table_with_people_in_it_is_never_touched(): void
    {
        $this->useLaravelsUsersTable();
        DB::table('users')->insert(['name' => 'Kari', 'email' => 'kari@example.com', 'password' => 'x']);

        try {
            $this->usersMigration()->up();
            $this->fail('A users table with people in it was rebuilt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('UUID user ids', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_the_apps_auth_uses_aurora_s_user_model_unless_it_extends_it(): void
    {
        config(['auth.providers.users.model' => LaravelUser::class, 'passkeys.models.authenticatable' => LaravelUser::class]);
        $this->configurePackages();

        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertSame(User::class, config('passkeys.models.authenticatable'));

        $ownUser = (new class extends User {})::class;
        config(['auth.providers.users.model' => $ownUser]);
        $this->configurePackages();

        $this->assertSame($ownUser, config('auth.providers.users.model'), 'An app model that extends Aurora\'s is kept.');
    }

    public function test_a_role_given_through_aurora_s_model_is_seen_by_the_app_s_own(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $ownUser = (new class extends User
        {
            protected $table = 'users';
        })::class;
        config(['auth.providers.users.model' => $ownUser]);

        $user = User::factory()->create();
        $user->syncRoles([User::SUPER_ADMIN]);

        $signedIn = $ownUser::query()->findOrFail($user->getKey());

        $this->assertTrue($signedIn->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($signedIn->isSuperAdmin());
    }

    public function test_aurora_s_own_layout_views_draw_a_page_until_the_site_makes_them_its_own(): void
    {
        $page = Page::factory()->homepage()->create(['title' => 'Welcome', 'blocks' => [
            ['id' => 'a', 'type' => 'heading', 'data' => ['level' => 'h1', 'text' => 'Hello from Aurora']],
        ]]);

        foreach (['standard', 'landing', 'minimal', 'blank'] as $view) {
            $html = view()->file(Aurora::path("resources/views/layouts/{$view}.blade.php"), ['page' => $page, 'layout' => $page->layoutOrDefault(), 'preview' => false, 'embedded' => false])->render();

            $this->assertStringContainsString('<title>Welcome', $html, $view);
            $this->assertStringContainsString('Hello from Aurora', $html, $view);
            // The blocks' own layout: the page builder's stylesheet.
            $this->assertStringContainsString('page-builder', $html, $view);
        }

        // The blank one: the page alone.
        $this->assertStringNotContainsString('<footer', $html);
    }

    public function test_only_aurora_s_roles_screen_is_offered_not_the_raw_permissions_one(): void
    {
        $this->assertSame(['RoleResource' => RoleResource::class], config('filament-spatie-roles-permissions.resources'));
        $this->assertNotContains(PermissionResource::class, Filament::getPanel('admin')->getResources());
    }

    /**
     * The users and sessions tables as a new Laravel app makes them: integer ids, no users.
     */
    protected function useLaravelsUsersTable(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    protected function usersMigration(): Migration
    {
        return require Aurora::path('database/migrations/2026_10_13_100000_give_users_uuid_keys.php');
    }

    protected function configurePackages(): void
    {
        (fn () => $this->configurePackages())->call(new AuroraServiceProvider($this->app));
    }
}
