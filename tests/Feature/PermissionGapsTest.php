<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Pages\Trash;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Filament\Resources\Forms\RelationManagers\SubmissionsRelationManager;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Filament\Resources\Roles\Pages\CreateRole;
use Aurora\Filament\Resources\Roles\Pages\EditRole;
use Aurora\Filament\Resources\Roles\RelationManagers\UsersRelationManager;
use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class PermissionGapsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Someone with just these permissions.
     *
     * @param  array<int, string>  $permissions
     */
    protected function userWith(array $permissions): User
    {
        $this->userWithRole('Viewer');
        $role = Role::findOrCreate('Custom '.uniqid(), 'web');
        $role->syncPermissions($permissions);

        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_restoring_from_the_trash_does_not_allow_deleting_for_good(): void
    {
        $page = Page::factory()->create();
        $page->delete();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(Trash::class)
            ->assertActionVisible(TestAction::make('restore')->table($page))
            ->assertActionHidden(TestAction::make('forceDelete')->table($page));
    }

    public function test_publishing_in_bulk_only_covers_pages_one_may_publish(): void
    {
        $publisher = $this->userWith(['view-any Page', 'update Page', 'publish Page']);
        $own = Page::factory()->create(['status' => PageStatus::Review, 'author_id' => $publisher->id]);
        $others = Page::factory()->create(['status' => PageStatus::Review]);

        Livewire::actingAs($publisher)
            ->test(ListPages::class)
            ->selectTableRecords([$own, $others])
            ->callAction(TestAction::make('publish')->table()->bulk());

        $this->assertSame(PageStatus::Published, $own->fresh()->status);
        $this->assertSame(PageStatus::Review, $others->fresh()->status);
    }

    public function test_moving_to_drafts_in_bulk_takes_the_homepage_too_with_a_warning(): void
    {
        $home = Page::factory()->homepage()->create();
        $page = Page::factory()->published()->create();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->selectTableRecords([$home, $page])
            ->mountAction(TestAction::make('unpublish')->table()->bulk())
            ->tap(fn ($page) => $this->assertStringContainsString('is the homepage', (string) $page->instance()->getMountedAction()->getModalDescription()))
            ->callMountedAction();

        $this->assertSame(PageStatus::Draft, $home->fresh()->status);
        $this->assertSame(PageStatus::Draft, $page->fresh()->status);
    }

    public function test_authors_cannot_take_a_page_live_by_its_date_or_as_the_homepage(): void
    {
        $author = $this->userWithRole('Author');
        // The site has a homepage: an author may not replace it.
        Page::factory()->homepage()->create();
        $scheduled = Page::factory()->create(['status' => PageStatus::Published, 'published_at' => now()->addWeek(), 'author_id' => $author->id]);

        CmsServer::actingAs($author)->tool(UpdatePage::class, ['page' => $scheduled->id, 'published_at' => now()->subDay()->toIso8601String()])
            ->assertHasErrors(['Permission denied']);
        CmsServer::actingAs($author)->tool(UpdatePage::class, ['page' => $scheduled->id, 'is_homepage' => true])
            ->assertHasErrors(['Permission denied']);

        $this->assertTrue($scheduled->fresh()->published_at->isFuture());
        $this->assertFalse($scheduled->fresh()->is_homepage);
    }

    public function test_people_who_only_read_submissions_cannot_delete_them(): void
    {
        $form = Form::factory()->create();
        $form->submissions()->create(['data' => []]);
        $reader = $this->userWith(['view-any Form']);

        Livewire::actingAs($reader)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditForm::class])
            ->assertActionHidden(TestAction::make('delete')->table()->bulk());
    }

    public function test_roles_people_hold_themselves_cannot_be_edited_by_them(): void
    {
        $manager = $this->userWith(['view-any Role', 'update Role']);
        $own = $manager->roles()->first();
        $other = Role::query()->where('name', 'Viewer')->sole();

        $this->assertFalse($manager->can('update', $own));
        $this->assertTrue($manager->can('update', $other));
    }

    public function test_the_browser_cannot_change_the_folder_or_language_being_worked_on(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ListAssets::class)->set('folder', 'not-a-folder');
    }

    public function test_a_role_editor_cannot_grant_permissions_they_do_not_have(): void
    {
        $editor = $this->userWith(['view-any Role', 'update Role', 'view-any Page']);

        Livewire::actingAs($editor)
            ->test(CreateRole::class)
            ->fillForm(['label' => 'Everything', 'permissions_pages' => ['view-any Page', 'publish Page', 'delete Page']])
            ->call('create')
            ->assertHasFormErrors(['permissions_pages.1', 'permissions_pages.2']);

        $this->assertNull(Role::query()->where('name', 'Everything')->first());

        // A role with permissions the editor lacks can still be saved: those stay as they were.
        $author = Role::findByName('Author');
        Livewire::actingAs($editor)
            ->test(EditRole::class, ['record' => $author->getKey()])
            ->fillForm(['label' => 'Writer'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertContains('use-mcp', $author->fresh()->permissions->pluck('name')->all());
    }

    public function test_nobody_adds_themselves_to_a_role_or_hands_out_one_stronger_than_their_own(): void
    {
        $manager = $this->userWith(['view-any Role', 'update Role', 'view-any User', 'update User']);
        $editor = Role::findByName('Editor');
        $weak = Role::findOrCreate('Weak', 'web');
        $weak->syncPermissions(['view-any User']);

        // The Editor role has permissions the manager lacks: they can't add people to it.
        Livewire::actingAs($manager)
            ->test(UsersRelationManager::class, ['ownerRecord' => $editor, 'pageClass' => EditRole::class])
            ->assertActionHidden(TestAction::make('addPeople')->table());

        // A role they could grant: still never to themselves.
        Livewire::actingAs($manager)
            ->test(UsersRelationManager::class, ['ownerRecord' => $weak, 'pageClass' => EditRole::class])
            ->callAction(TestAction::make('addPeople')->table(), ['people' => [$manager->id]]);

        $this->assertFalse($manager->fresh()->hasRole('Weak'));
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $manager = $this->userWith(['view-any User', 'update User']);

        Livewire::actingAs($manager)
            ->test(EditUser::class, ['record' => $manager->getKey()])
            ->assertFormFieldDisabled('roles')
            ->fillForm(['roles' => [Role::findByName('Editor')->id]])
            ->call('save');

        $this->assertFalse($manager->fresh()->hasRole('Editor'));
    }

    public function test_svg_files_are_not_accepted_into_the_media_library(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->assertNotNull(AssetLibrary::rejectionFor($svg));
    }
}
