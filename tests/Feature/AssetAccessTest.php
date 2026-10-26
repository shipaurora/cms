<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Assets\Pages\ViewAsset;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Support\AssetAccess;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class AssetAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function image(?AssetFolder $folder = null, string $name = 'fjord.jpg'): Asset
    {
        return AssetLibrary::fromUpload(UploadedFile::fake()->image($name, 20, 20), ['folder_id' => $folder?->id]);
    }

    protected function role(string $name): string
    {
        return 'role:'.Role::query()->where('name', $name)->value('id');
    }

    public function test_everything_starts_open_to_everyone(): void
    {
        $folder = AssetFolder::query()->create(['name' => 'Photos']);
        $image = $this->image($folder);
        $editor = $this->userWithRole('Editor');

        $this->assertTrue(AssetAccess::canWrite($editor, $folder));
        $this->assertTrue(AssetAccess::canWrite($editor, $image));
        $this->assertTrue($editor->can('update', $image));
    }

    public function test_a_restricted_folder_hides_itself_and_what_is_in_it_unless_something_has_its_own_access(): void
    {
        $editor = $this->userWithRole('Editor');
        $author = $this->userWithRole('Author');
        $hr = AssetFolder::query()->create(['name' => 'Personnel files']);
        $contracts = AssetFolder::query()->create(['name' => 'Contracts', 'parent_id' => $hr->id]);
        AssetAccess::put($hr, AssetAccess::CUSTOM, [['grantee' => $this->role('Editor'), 'level' => 'write']]);

        $secret = $this->image($contracts, 'contract.jpg');
        $shared = $this->image($hr, 'team-photo.jpg');
        $public = $this->image(null, 'logo.jpg');
        // One file in the closed folder is opened to everyone, to read.
        AssetAccess::put($shared, AssetAccess::CUSTOM, [['grantee' => 'everyone', 'level' => 'read']]);

        $this->actingAs($author);
        $this->assertFalse(AssetAccess::canRead($author, $hr));
        $this->assertFalse(AssetAccess::canRead($author, $contracts), 'Subfolders follow it.');
        $this->assertEqualsCanonicalizing([$shared->id, $public->id], AssetPicker::search('')->modelKeys());

        Livewire::test(ListAssets::class)
            ->assertCanSeeTableRecords([$public])
            ->assertCanNotSeeTableRecords([$secret])
            ->assertDontSee('Personnel files')
            // Not by its address either, nor by going back to it.
            ->set('folder', $hr->id)
            ->assertSet('folder', null)
            ->assertCanNotSeeTableRecords([$shared, $secret]);

        $this->get(route('filament.admin.resources.assets.edit', ['tenant' => 'main', 'record' => $secret]))->assertForbidden();

        // Read is not write: the shared file can be seen, not changed. It opens view-only.
        $this->assertTrue($author->can('view', $shared));
        $this->assertFalse($author->can('update', $shared));
        $this->get(route('filament.admin.resources.assets.view', ['tenant' => 'main', 'record' => $shared]))->assertOk()->assertSee('team-photo');
        $this->get(route('filament.admin.resources.assets.edit', ['tenant' => 'main', 'record' => $shared]))->assertForbidden();
        Livewire::test(ViewAsset::class, ['record' => $shared->getKey()])
            ->assertSee('You can view this file, but not change it')
            ->assertActionVisible('download')
            ->assertSchemaComponentStateSet('title', 'Team photo')
            ->assertFormFieldDisabled('title');

        // The Editors still see everything in it.
        $this->actingAs($editor);
        $this->assertEqualsCanonicalizing([$secret->id, $shared->id, $public->id], AssetPicker::search('')->modelKeys());

        // And Super Admins always do.
        $this->assertTrue(AssetAccess::canWrite($this->userWithRole(User::SUPER_ADMIN), $contracts));
    }

    public function test_read_only_access_keeps_files_and_folders_from_being_changed(): void
    {
        $editor = $this->userWithRole('Editor');
        $archive = AssetFolder::query()->create(['name' => 'Archive']);
        AssetAccess::put($archive, AssetAccess::CUSTOM, [['grantee' => 'everyone', 'level' => 'read']]);
        $old = $this->image($archive);

        $this->actingAs($editor);
        $this->assertTrue($editor->can('view', $old));
        $this->assertFalse($editor->can('update', $old));
        $this->assertFalse($editor->can('delete', $old));

        Livewire::test(ListAssets::class, ['folder' => $archive->id])
            ->assertSee('You can view this folder, but not change it')
            ->assertActionHidden(TestAction::make('upload'))
            ->assertActionHidden(TestAction::make('renameFolder'))
            ->assertActionHidden(TestAction::make('folderAccess'));

        // Its files can't be selected, and with only those on the page there's no selecting at all.
        $folderView = Livewire::test(ListAssets::class, ['folder' => $archive->id]);
        $this->assertFalse($folderView->instance()->getTable()->isRecordSelectable($old));
        $this->assertFalse($folderView->instance()->getTable()->isSelectionEnabled());

        // Nor can things be dropped into it.
        $loose = $this->image();
        Livewire::test(ListAssets::class)->call('moveAssetsToFolder', [$loose->id], $archive->id);
        $this->assertNull($loose->fresh()->folder_id);
    }

    public function test_access_is_set_in_its_dialog_without_locking_yourself_out(): void
    {
        $editor = $this->userWithRole('Editor');
        $image = $this->image();

        // The dialog stays open when saving is refused: a fresh page for each try.
        $page = fn () => Livewire::actingAs($editor)->test(EditAsset::class, ['record' => $image->getKey()]);

        // Only Authors: the Editor would lose their own access.
        $page()->callAction('access', ['access' => AssetAccess::CUSTOM, 'rules' => [['grantee' => $this->role('Author'), 'level' => 'write']]])
            ->assertHasNoActionErrors()
            ->assertNotified('You’d lose access yourself');
        $this->assertSame(AssetAccess::INHERIT, $image->fresh()->access);

        $page()->callAction('access', ['access' => AssetAccess::CUSTOM, 'rules' => [
            ['grantee' => $this->role('Editor'), 'level' => 'write'],
            ['grantee' => $this->role('Author'), 'level' => 'read'],
        ]])->assertNotified('Access saved');

        $this->assertSame(AssetAccess::CUSTOM, $image->fresh()->access);
        $this->assertCount(2, AssetAccess::rules($image->fresh()));

        // And back to following its folder.
        $page()->callAction('access', ['access' => AssetAccess::INHERIT])->assertNotified('Access saved');
        $this->assertSame([], AssetAccess::rules($image->fresh()));
    }

    public function test_access_is_set_for_several_selected_files_at_once_skipping_those_that_cant_be_changed(): void
    {
        $editor = $this->userWithRole('Editor');
        $archive = AssetFolder::query()->create(['name' => 'Archive']);
        AssetAccess::put($archive, AssetAccess::CUSTOM, [['grantee' => 'everyone', 'level' => 'read']]);
        $mine = $this->image(null, 'one.jpg');
        $locked = $this->image($archive, 'two.jpg');

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->set('display', 'grid')
            ->callTableBulkAction('access', [$mine, $locked], ['access' => AssetAccess::CUSTOM, 'rules' => [
                ['grantee' => $this->role('Editor'), 'level' => 'write'],
            ]])
            ->assertNotified('Access saved');

        $this->assertSame(AssetAccess::CUSTOM, $mine->fresh()->access);
        $this->assertSame(AssetAccess::INHERIT, $locked->fresh()->access, 'Read-only to them: left as it was.');
    }

    public function test_only_people_allowed_to_change_access_get_the_access_action(): void
    {
        $image = $this->image();

        // Editors have "Change who has access" by default.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditAsset::class, ['record' => $image->getKey()])
            ->assertActionVisible('access');

        // Without it, even someone who may change the file can't change who has access.
        $manager = $this->userWithRole('Editor');
        $manager->roles->first()->revokePermissionTo('manage-access Asset');

        Livewire::actingAs($manager->fresh())
            ->test(EditAsset::class, ['record' => $image->getKey()])
            ->assertActionHidden('access');
    }

    public function test_people_who_may_change_a_file_open_it_to_edit_and_only_others_to_view(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $folder = AssetFolder::query()->create(['name' => 'New folder']);
        $image = $this->image($folder);
        $edit = route('filament.admin.resources.assets.edit', ['tenant' => 'main', 'record' => $image]);
        $view = route('filament.admin.resources.assets.view', ['tenant' => 'main', 'record' => $image]);

        // Filament would link to the view page first, whenever there is one.
        $this->actingAs($admin);
        $this->assertSame($edit, AssetResource::urlFor($image));
        $this->assertSame($edit, AssetResource::getGlobalSearchResultUrl($image));
        Livewire::test(ViewAsset::class, ['record' => $image->getKey()])->assertRedirect($edit);

        // Someone who may only read it gets the view page.
        AssetAccess::put($folder, AssetAccess::CUSTOM, [['grantee' => 'everyone', 'level' => 'read'], ['grantee' => 'user:'.$admin->id, 'level' => 'write']]);
        $this->actingAs($author = $this->userWithRole('Author'));
        $this->assertSame($view, AssetResource::urlFor($image));
        Livewire::test(ViewAsset::class, ['record' => $image->getKey()])->assertNoRedirect();
    }
}
