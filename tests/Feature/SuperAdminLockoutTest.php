<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class SuperAdminLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_cannot_remove_their_own_super_admin_role_in_the_user_form(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $editor = Role::findByName('Editor');

        $this->actingAs($admin);

        try {
            Livewire::test(EditUser::class, ['record' => $admin->getKey()])
                ->fillForm(['roles' => [$editor->id]])
                ->call('save');
        } catch (ValidationException) {
            //
        }

        $this->assertTrue($admin->refresh()->isSuperAdmin());
    }

    public function test_the_guard_holds_for_any_way_of_removing_the_role(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $this->actingAs($admin);

        foreach ([
            fn () => $admin->removeRole(User::SUPER_ADMIN),
            fn () => $admin->syncRoles(['Editor']),
            fn () => Role::findByName(User::SUPER_ADMIN)->users()->detach($admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The Super Admin role was removed.');
            } catch (ValidationException) {
                $this->assertTrue($admin->fresh()->isSuperAdmin());
            }
        }
    }

    public function test_a_super_admin_can_still_demote_another_super_admin(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $other = $this->userWithRole(User::SUPER_ADMIN);

        $this->actingAs($admin);
        $other->syncRoles(['Editor']);

        $this->assertFalse($other->refresh()->isSuperAdmin());
    }

    public function test_super_admins_cannot_delete_or_deactivate_themselves_or_delete_the_role(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', $admin));
        $this->assertFalse($admin->can('delete', Role::findByName(User::SUPER_ADMIN)));
        $this->assertTrue($admin->can('delete', $this->userWithRole('Author')));

        $this->expectException(ValidationException::class);
        $admin->update(['is_active' => false]);
    }

    public function test_a_super_admin_can_save_their_own_profile(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['job_title' => 'CTO'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('CTO', $admin->refresh()->job_title);
        $this->assertTrue($admin->isSuperAdmin());
    }

    public function test_super_admins_need_no_other_roles(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $other = $this->userWithRole('Editor');
        $superAdmin = Role::findByName(User::SUPER_ADMIN)->id;

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $other->getKey()])
            ->fillForm(['roles' => [Role::findByName('Editor')->id, $superAdmin]])
            ->assertSchemaStateSet(['roles' => [$superAdmin]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([User::SUPER_ADMIN], $other->refresh()->getRoleNames()->all());
    }
}
