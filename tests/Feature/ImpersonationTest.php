<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Users\Pages\ListUsers;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admins_can_impersonate_out_of_the_box(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $author = $this->userWithRole('Author');

        $this->assertTrue($admin->canImpersonate());
        $this->assertFalse($author->canImpersonate());

        foreach (Role::query()->where('name', '!=', User::SUPER_ADMIN)->get() as $role) {
            $this->assertFalse($role->hasPermissionTo('impersonate User'), "{$role->name} should not impersonate by default.");
        }
    }

    public function test_super_admins_can_view_as_other_users_but_never_as_a_super_admin(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $otherAdmin = $this->userWithRole(User::SUPER_ADMIN);
        $author = $this->userWithRole('Author');

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionVisible('impersonate', $author)
            ->assertTableActionHidden('impersonate', $otherAdmin)
            ->assertTableActionHidden('impersonate', $admin);
    }

    public function test_the_permission_can_be_granted_to_a_role(): void
    {
        $editor = $this->userWithRole('Editor');
        $superAdmin = $this->userWithRole(User::SUPER_ADMIN);

        $this->assertFalse($editor->canImpersonate());

        Role::findByName('Editor')->givePermissionTo('impersonate User');

        $this->assertTrue($editor->fresh()->canImpersonate());
        $this->assertFalse($superAdmin->canBeImpersonated());
    }

    public function test_the_banner_shows_who_you_are_viewing_as_in_the_theme_colours(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $author = $this->userWithRole('Author', ['name' => 'Aksel Author']);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('impersonate', $author);

        $this->get('/admin/main')
            ->assertOk()
            ->assertSee('id="impersonate-banner"', false)
            ->assertSee('var(--primary-600', false)
            ->assertSee('Aksel Author')
            ->assertSee(route('filament-impersonate.leave'), false);
    }
}
