<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\UsersAndRoles;
use Aurora\Filament\Resources\Roles\Pages\CreateRole;
use Aurora\Filament\Resources\Roles\Pages\EditRole;
use Aurora\Filament\Resources\Roles\RoleResource;
use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Filament\Resources\Users\Pages\ListUsers;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Models\Role;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class ProfileAndRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_is_dark_by_default_and_remembers_each_persons_theme(): void
    {
        $this->assertSame(ThemeMode::Dark, Filament::getPanel('admin')->getDefaultThemeMode());

        $admin = $this->userWithRole(User::SUPER_ADMIN);

        $this->actingAs($admin)
            ->get('/admin/main')
            ->assertOk()
            ->assertSee('--default-theme-mode: dark', false)
            ->assertSee("localStorage.setItem('filament:theme:mode', \"dark\")", false);

        $this->actingAs($admin)
            ->postJson(route('filament.admin.preferences.theme'), ['theme' => 'light'])
            ->assertNoContent();

        $this->assertSame('light', $admin->fresh()->theme);

        $this->actingAs($admin->fresh())
            ->get('/admin/main')
            ->assertSee("localStorage.setItem('filament:theme:mode', \"light\")", false);

        $this->actingAs($admin)
            ->postJson(route('filament.admin.preferences.theme'), ['theme' => 'pink'])
            ->assertUnprocessable();
    }

    public function test_the_sidebar_starts_collapsed_and_remembers_each_persons_choice(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        $this->assertTrue($admin->fresh()->sidebar_collapsed);

        $this->actingAs($admin)
            ->get('/admin/main')
            ->assertSee("localStorage.setItem('filament:sidebar:desktop_open', \"false\")", false);

        $this->actingAs($admin)
            ->postJson(route('filament.admin.preferences.sidebar'), ['collapsed' => false])
            ->assertNoContent();

        $this->assertFalse($admin->fresh()->sidebar_collapsed);

        $this->actingAs($admin->fresh())
            ->get('/admin/main')
            ->assertSee("localStorage.setItem('filament:sidebar:desktop_open', \"true\")", false);

        $this->actingAs($admin)
            ->postJson(route('filament.admin.preferences.sidebar'), ['collapsed' => 'sometimes'])
            ->assertUnprocessable();
    }

    public function test_the_profile_menu_item_opens_your_own_user_record(): void
    {
        $author = $this->userWithRole('Author');

        $this->actingAs($author)
            ->get('/admin/main')
            ->assertOk()
            ->assertSee(UserResource::getUrl('edit', ['record' => $author]), false);
    }

    public function test_anyone_can_edit_their_own_profile_but_not_their_roles(): void
    {
        $author = $this->userWithRole('Author');

        $this->actingAs($author)->get(UserResource::getUrl('edit', ['record' => $author]))->assertOk();
        $this->actingAs($author)->get(UserResource::getUrl('edit', ['record' => $this->userWithRole('Editor')]))->assertForbidden();

        Livewire::actingAs($author)
            ->test(EditUser::class, ['record' => $author->getKey()])
            ->assertFormFieldHidden('roles')
            ->fillForm(['job_title' => 'Senior copywriter'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Senior copywriter', $author->refresh()->job_title);
        $this->assertTrue($author->hasRole('Author'));
    }

    public function test_roles_are_edited_through_plain_language_permission_groups(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $author = Role::findByName('Author');

        Livewire::actingAs($admin)
            ->test(EditRole::class, ['record' => $author->getKey()])
            ->assertSchemaStateSet(['permissions_pages' => ['view-any Page', 'create Page', 'update Page']])
            ->fillForm(['permissions_pages' => ['view-any Page', 'create Page', 'update Page', 'publish Page']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($author->refresh()->hasPermissionTo('publish Page'));
        $this->assertTrue($author->hasPermissionTo('use-mcp'), 'Permissions in other groups are kept.');
    }

    public function test_the_super_admin_role_cannot_be_edited_or_deleted(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        $this->actingAs($admin)
            ->get(RoleResource::getUrl('edit', ['record' => Role::findByName(User::SUPER_ADMIN)]))
            ->assertForbidden();
    }

    public function test_a_role_is_named_and_described_in_each_admin_language(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $author = Role::findByName('Author');

        // Permissions ticked before switching language are the ones saved.
        Livewire::actingAs($admin)
            ->test(EditRole::class, ['record' => $author->getKey()])
            ->fillForm(['permissions_pages' => ['view-any Page', 'create Page', 'update Page', 'publish Page']])
            ->set('activeLocale', 'nb')
            ->fillForm(['label' => 'Skribent', 'description' => 'Skriver sider.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $author->refresh();
        $this->assertSame('Author', $author->name, 'The key permission checks use stays.');
        $this->assertSame('Author', $author->getTranslation('label', 'en'));
        $this->assertTrue($author->hasPermissionTo('publish Page'));

        app()->setLocale('nb');
        $this->assertSame('Skribent', $author->displayName());
        $this->assertSame('Skriver sider.', $author->displayDescription());
    }

    public function test_a_new_role_takes_its_first_name_as_its_key(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(CreateRole::class)
            ->fillForm(['label' => 'Marketing', 'description' => 'Runs campaigns.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::findByName('Marketing');
        $this->assertSame('Marketing', $role->getTranslation('label', 'en'));

        Livewire::actingAs($admin)
            ->test(CreateRole::class)
            ->fillForm(['label' => 'Marketing'])
            ->call('create')
            ->assertHasFormErrors(['label' => 'unique']);
    }

    public function test_the_built_in_roles_read_in_the_admins_language(): void
    {
        $this->userWithRole('Editor');
        app()->setLocale('nb');

        $this->assertSame('Redaktør', Role::findByName('Editor')->displayName());
        $this->assertSame('Kan se seg rundt, men ikke endre noe.', Role::findByName('Viewer')->displayDescription());
    }

    public function test_the_filter_button_carries_its_count_so_a_zero_can_be_hidden(): void
    {
        $users = Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))->test(ListUsers::class);
        $this->assertStringContainsString('data-active-filters="0"', $users->html());

        $users->set('tableFilters.is_active.value', '1');
        $this->assertStringContainsString('data-active-filters="1"', $users->html());
    }

    public function test_saving_your_own_profile_refreshes_the_top_bar(): void
    {
        $user = $this->userWithRole('Editor');

        Livewire::actingAs($user)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertDispatched('refresh-topbar');
    }

    public function test_the_sites_a_person_works_on_are_only_asked_with_more_than_one_site(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertFormFieldHidden('sites');

        Site::query()->create(['name' => 'Shop', 'handle' => 'shop', 'domain' => 'shop.example.com']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertFormFieldVisible('sites');
    }

    public function test_users_and_roles_share_one_sidebar_entry_with_a_menu_of_their_own(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $this->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Users &amp; Roles', false);

        $this->assertSame(
            [RoleResource::class, UserResource::class],
            UsersAndRoles::getClusteredComponents(),
            'Each once.',
        );
    }

    public function test_editing_a_user_or_role_has_breadcrumbs_back_through_users_and_roles(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $this->actingAs($admin);
        $editor = $this->userWithRole('Editor');

        $users = Livewire::test(EditUser::class, ['record' => $editor->getKey()])->instance()->getBreadcrumbs();
        $this->assertSame(['Users & Roles', 'Users'], array_values($users));

        $role = Role::query()->where('name', 'Editor')->sole();
        $roles = Livewire::test(EditRole::class, ['record' => $role->getKey()])->instance()->getBreadcrumbs();
        $this->assertSame(['Users & Roles', 'Roles'], array_values($roles));

        // Someone's own profile, without the list: no way back to it.
        $this->actingAs($editor);
        $this->assertSame([], Livewire::test(EditUser::class, ['record' => $editor->getKey()])->instance()->getBreadcrumbs());
    }
}
