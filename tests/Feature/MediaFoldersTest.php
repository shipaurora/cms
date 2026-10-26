<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\AssetType;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\ListImages;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class MediaFoldersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function asset(string $name, ?string $folder = null): Asset
    {
        return AssetLibrary::fromUpload(UploadedFile::fake()->image($name.'.jpg', 200, 100), ['folder' => $folder]);
    }

    public function test_folder_paths_create_nested_folders_once(): void
    {
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $lake = $this->asset('lake', 'Photos / Norway');

        $this->assertSame('Photos / Norway', $fjord->folder->path());
        $this->assertTrue($fjord->folder->is($lake->folder));
        $this->assertSame(2, AssetFolder::query()->count());
    }

    public function test_the_library_browses_one_folder_at_a_time_and_searches_them_all(): void
    {
        $editor = $this->userWithRole('Editor');
        $loose = $this->asset('loose');
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertCanSeeTableRecords([$loose])
            ->assertCanNotSeeTableRecords([$fjord])
            ->assertSee('Photos')
            ->call('openFolder', $photos->id)
            ->assertSee('Norway')
            ->assertCanNotSeeTableRecords([$loose, $fjord])
            ->call('openFolder', $norway->id)
            ->assertCanSeeTableRecords([$fjord])
            ->assertCanNotSeeTableRecords([$loose])
            ->searchTable('fjord')
            ->assertCanSeeTableRecords([$fjord])
            ->assertSee('Searching all folders');
    }

    public function test_folders_are_the_first_cards_of_the_grid_and_rows_of_the_list(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->asset('loose');
        $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();

        // In the table, before its files, as its own records are: not in a grid of their own above it.
        $grid = Livewire::actingAs($editor)->test(ListAssets::class)->html();
        $this->assertMatchesRegularExpression('/cms-folder-items.*fi-ta-content-grid.*wire:key="folder-'.$photos->id.'".*cms-asset-record/s', $grid);
        // Select all is in the toolbar, so nothing stands between the folders and the files.
        $this->assertStringContainsString('cms-media-select-page', $grid);

        // The list has no heading row between them, and sorts from the toolbar instead.
        Livewire::actingAs($editor)
            ->withQueryParams(['view' => 'list'])
            ->test(ListAssets::class)
            ->assertSeeHtml('wire:key="folder-'.$photos->id.'"')
            ->assertSeeHtml('cms-folder-record-spacer')
            ->assertDontSeeHtml('<thead')
            ->assertSee('Oldest first');
    }

    public function test_folders_can_be_shown_apart_from_the_files_as_before(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->asset('loose');
        $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->withQueryParams(['tab' => 'media'])
            ->test(SiteSettings::class)
            ->assertFormSet([ListAssets::FOLDERS_INLINE_SETTING => true])
            ->fillForm([ListAssets::FOLDERS_INLINE_SETTING => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $html = Livewire::actingAs($editor)->test(ListAssets::class)->html();

        // A grid of its own above the table, and the table's own select-all bar.
        $this->assertStringContainsString('wire:key="folder-'.$photos->id.'"', $html);
        $this->assertStringNotContainsString('cms-folder-items', $html);
        $this->assertStringNotContainsString('cms-media-select-page', $html);
        $this->assertStringContainsString('fi-ta-content-header', $html);
    }

    public function test_folders_are_left_out_of_a_search_and_of_later_pages(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();

        foreach (range(1, 21) as $number) {
            $this->asset('loose-'.$number);
        }

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->set('tableRecordsPerPage', 20)
            ->assertSeeHtml('wire:key="folder-'.$photos->id.'"')
            ->call('gotoPage', 2)
            ->assertDontSeeHtml('wire:key="folder-'.$photos->id.'"')
            ->call('gotoPage', 1)
            ->searchTable('fjord')
            ->assertDontSeeHtml('wire:key="folder-'.$photos->id.'"');
    }

    public function test_only_the_library_and_the_open_folder_pick_a_folder_from_the_trail(): void
    {
        $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();

        // The folder above simply opens; the open one (and "Library") picks from the tree.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class, ['folder' => $norway->id])
            ->assertSeeHtml('wire:key="folder-tree-dropdown"')
            ->assertSeeHtml('wire:key="folder-tree-dropdown-'.$norway->id.'"')
            ->assertDontSeeHtml('wire:key="folder-tree-dropdown-'.$photos->id.'"')
            ->assertSeeHtml('wire:key="folder-crumb-'.$photos->id.'"')
            ->call('openFolder', $photos->id)
            ->assertSeeHtml('wire:key="folder-tree-dropdown-'.$photos->id.'"');
    }

    public function test_the_open_folders_dropdown_lists_only_the_folders_next_to_it(): void
    {
        $this->asset('fjord', 'Photos/Norway/Fjords');
        AssetFolder::findOrCreatePath('Photos/Iceland');
        [$photos, $norway, $iceland, $fjords] = collect(['Photos', 'Norway', 'Iceland', 'Fjords'])->map(fn (string $name): AssetFolder => AssetFolder::query()->where('name', $name)->sole());

        $html = Livewire::actingAs($this->userWithRole('Editor'))->test(ListAssets::class, ['folder' => $norway->id])->html();

        // Norway's own dropdown: Norway and Iceland, Photos's folders, without Photos above them or Fjords below.
        $this->assertStringContainsString('wire:key="folder-tree-siblings-'.$photos->id.'-'.$norway->id.'"', $html);
        $this->assertStringContainsString('wire:key="folder-tree-siblings-'.$photos->id.'-'.$iceland->id.'"', $html);
        $this->assertStringNotContainsString('wire:key="folder-tree-siblings-'.$photos->id.'-'.$fjords->id.'"', $html);
        // "Library" still holds the whole tree.
        $this->assertStringContainsString('wire:key="folder-tree-root-'.$fjords->id.'"', $html);
    }

    public function test_the_browsers_back_button_returns_to_the_folder_before(): void
    {
        $loose = $this->asset('loose');
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();

        // Going back or forward sets the folder from the address, as Livewire does.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->call('openFolder', $photos->id)
            ->call('openFolder', $norway->id)
            ->assertCanSeeTableRecords([$fjord])
            ->set('folder', $photos->id)
            ->assertSet('folder', $photos->id)
            ->assertSee('Norway')
            ->assertCanNotSeeTableRecords([$fjord])
            ->set('folder', null)
            ->assertCanSeeTableRecords([$loose]);
    }

    public function test_folders_can_be_created_renamed_moved_and_deleted_without_losing_images(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();

        Livewire::actingAs($editor)
            ->test(ListAssets::class, ['folder' => $photos->id])
            ->callAction('newFolder', ['name' => 'Iceland'])
            ->assertHasNoActionErrors()
            ->callAction('newFolder', ['name' => 'Iceland'])
            ->assertHasActionErrors(['name' => 'unique']);

        $this->assertSame($photos->id, AssetFolder::query()->where('name', 'Iceland')->sole()->parent_id);

        $component = Livewire::actingAs($editor)->test(ListAssets::class, ['folder' => $norway->id]);
        $component->callAction('renameFolder', ['name' => 'Norge'])->assertHasNoActionErrors();
        $component->callAction('moveFolder', ['parent_id' => null]);
        $this->assertSame('Norge', $norway->refresh()->name);
        $this->assertNull($norway->parent_id);

        Livewire::actingAs($editor)->test(ListAssets::class, ['folder' => $photos->id])
            ->callAction('moveFolder', ['parent_id' => AssetFolder::query()->where('name', 'Iceland')->value('id')])
            ->assertHasActionErrors();

        $component->callAction('deleteFolder');

        $this->assertModelMissing($norway);
        $this->assertNull($fjord->refresh()->folder_id, 'The image moved up a level instead of being deleted.');
    }

    public function test_images_can_be_moved_into_a_folder_in_bulk(): void
    {
        $editor = $this->userWithRole('Editor');
        $one = $this->asset('one');
        $two = $this->asset('two');
        $folder = AssetFolder::findOrCreatePath('Campaigns');

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->callTableBulkAction('move', [$one, $two], ['folder_id' => $folder->id]);

        $this->assertSame(2, $folder->assets()->count());
    }

    public function test_the_credit_of_selected_files_can_be_set_or_cleared_in_bulk(): void
    {
        $one = $this->asset('one');
        $two = $this->asset('two');
        $untouched = $this->asset('three');
        $one->update(['credit' => 'Photo: Kari']);
        $untouched->update(['credit' => 'Photo: Ola']);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->callTableBulkAction('credit', [$one, $two], ['credit' => 'Photo: Nordic Agency'])
            ->assertHasNoTableBulkActionErrors()
            ->callTableBulkAction('credit', [$two], ['credit' => '']);

        $this->assertSame('Photo: Nordic Agency', $one->fresh()->credit);
        $this->assertNull($two->fresh()->credit);
        $this->assertSame('Photo: Ola', $untouched->fresh()->credit);
    }

    public function test_a_folder_heading_has_a_button_up_one_level(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertDontSee('Up to Library');

        Livewire::actingAs($editor)
            ->test(ListAssets::class, ['folder' => $norway->id])
            ->assertSee('Up to Photos')
            ->assertSeeHtml('wire:click="openFolder(\''.$photos->id.'\')"')
            ->call('openFolder', $photos->id)
            ->assertSee('Up to Library');
    }

    public function test_dragging_images_onto_a_folder_moves_them(): void
    {
        $editor = $this->userWithRole('Editor');
        $one = $this->asset('one');
        $two = $this->asset('two');
        $three = $this->asset('three');
        $folder = AssetFolder::findOrCreatePath('Campaigns');

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertSeeHtml('cms-asset-record')
            ->assertSeeHtml('x-data="cmsMediaDrag(')
            ->call('moveAssetsToFolder', [$one->id, $two->id], $folder->id)
            ->assertNotified('Moved 2 images to Campaigns')
            ->assertCanSeeTableRecords([$three])
            ->assertCanNotSeeTableRecords([$one, $two]);

        $this->assertEqualsCanonicalizing([$one->id, $two->id], $folder->assets()->pluck('id')->all());

        Livewire::actingAs($editor)
            ->test(ListAssets::class, ['folder' => $folder->id])
            ->call('moveAssetsToFolder', [$one->id], null)
            ->assertNotified('Moved 1 image to Library');

        $this->assertNull($one->fresh()->folder_id);
    }

    public function test_dragging_a_select_all_selection_moves_everything_in_the_folder_except_deselected(): void
    {
        $editor = $this->userWithRole('Editor');
        $one = $this->asset('one');
        $two = $this->asset('two');
        $elsewhere = $this->asset('elsewhere', 'Archive');
        $folder = AssetFolder::findOrCreatePath('Campaigns');

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->call('moveAssetsToFolder', [], $folder->id, true, [$two->id]);

        $this->assertSame($folder->id, $one->fresh()->folder_id);
        $this->assertNull($two->fresh()->folder_id);
        $this->assertNotSame($folder->id, $elsewhere->fresh()->folder_id);
    }

    public function test_the_folder_tree_lists_every_folder_unfolded_to_the_open_one(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();
        $travel = AssetFolder::findOrCreatePath('Travel');

        $tree = Livewire::actingAs($editor)->test(ListAssets::class, ['folder' => $norway->id])->instance()->folderTree();

        $this->assertSame(['Photos', 'Norway', 'Travel'], array_map(fn (array $row): string => $row['folder']->name, $tree['rows']));
        $this->assertSame([0, 1, 0], array_column($tree['rows'], 'depth'));
        $this->assertSame([$photos->id], $tree['rows'][1]['ancestors']);
        $this->assertSame([$photos->id, $norway->id], $tree['expanded'], 'Opened down to the open folder.');
        $this->assertFalse($tree['rows'][2]['hasChildren']);
        $this->assertNotSame($travel->id, $tree['rows'][0]['folder']->id);
    }

    public function test_dragging_a_folder_onto_another_folder_nests_it(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();
        $travel = AssetFolder::findOrCreatePath('Travel');

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertSeeHtml('wire:key="folder-'.$photos->id.'"')
            ->call('moveFolderToFolder', $photos->id, $travel->id)
            ->assertNotified('Moved Photos to Travel')
            // Its tile leaves the top level (the folder tree still lists it, under Travel).
            ->assertDontSeeHtml('wire:key="folder-'.$photos->id.'"');

        $this->assertSame('Travel / Photos / Norway', $norway->fresh()->path());
        $this->assertTrue($fjord->fresh()->folder->is($norway));

        Livewire::actingAs($editor)
            ->test(ListAssets::class, ['folder' => $travel->id])
            ->call('moveFolderToFolder', $photos->id, null)
            ->assertNotified('Moved Photos to Library');

        $this->assertNull($photos->fresh()->parent_id);
    }

    public function test_a_folder_cannot_be_dragged_into_itself_or_onto_a_name_clash(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->asset('fjord', 'Photos/Norway');
        $this->asset('lake', 'Archive/Photos');
        $photos = AssetFolder::query()->where('name', 'Photos')->whereNull('parent_id')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();
        $archive = AssetFolder::query()->where('name', 'Archive')->sole();

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->call('moveFolderToFolder', $photos->id, $norway->id)
            ->assertNotified('A folder can’t be moved into itself')
            ->call('moveFolderToFolder', $photos->id, $archive->id)
            ->assertNotified('Archive already has a folder called Photos');

        $this->assertNull($photos->fresh()->parent_id);
    }

    public function test_only_people_who_manage_the_library_can_drag_folders(): void
    {
        $author = $this->userWithRole('Author');
        $this->asset('fjord', 'Photos');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $travel = AssetFolder::findOrCreatePath('Travel');

        Livewire::actingAs($author)
            ->test(ListAssets::class)
            ->assertDontSeeHtml('data-cms-folder=')
            ->call('moveFolderToFolder', $photos->id, $travel->id);

        $this->assertNull($photos->fresh()->parent_id);
    }

    public function test_authors_can_only_drag_their_own_images(): void
    {
        $author = $this->userWithRole('Author');
        $theirs = $this->asset('theirs');
        $mine = $this->asset('mine');
        $mine->forceFill(['uploaded_by_id' => $author->id])->save();
        $folder = AssetFolder::findOrCreatePath('Campaigns');

        Livewire::actingAs($author)
            ->test(ListAssets::class)
            ->call('moveAssetsToFolder', [$theirs->id, $mine->id], $folder->id);

        $this->assertSame($folder->id, $mine->fresh()->folder_id);
        $this->assertNull($theirs->fresh()->folder_id);
    }

    public function test_the_picker_and_the_ai_assistant_filter_by_folder(): void
    {
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $city = $this->asset('city', 'Photos/Places');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();
        $norway = AssetFolder::query()->where('name', 'Norway')->sole();

        $this->assertEqualsCanonicalizing([$fjord->id, $city->id], AssetPicker::search('', $photos->id)->pluck('id')->all());
        $this->assertSame([$fjord->id], AssetPicker::search('', $norway->id)->pluck('id')->all());

        CmsServer::actingAs($this->userWithRole('Editor'))->tool(ListImages::class, ['folder' => 'Photos/Norway'])
            ->assertOk()
            ->assertSee([$fjord->id, 'Photos/Norway'])
            ->assertDontSee($city->id);
    }

    public function test_files_dropped_from_the_desktop_are_uploaded_into_the_folder_one_by_one(): void
    {
        $editor = $this->userWithRole('Editor');
        $photos = AssetFolder::findOrCreatePath('Photos');

        $page = Livewire::actingAs($editor)
            ->test(ListAssets::class, ['folder' => $photos->id])
            ->assertSeeHtml('cmsMediaDrag(')
            ->assertSeeHtml('\u0022canUpload\u0022:true')
            ->set('dropped.u1', UploadedFile::fake()->image('harbour.jpg', 400, 300))
            ->set('dropped.u2', UploadedFile::fake()->createWithContent('notes.pdf', file_get_contents($this->fixture('brand-guidelines.pdf'))))
            ->set('dropped.u3', UploadedFile::fake()->createWithContent('page.html', '<html><body><script>alert(1)</script></body></html>'));

        $stored = $page->instance()->storeDroppedUpload('u1', $photos->id);
        $harbour = Asset::query()->where('title', 'Harbour')->sole();

        $this->assertSame(['id' => $harbour->id, 'title' => 'Harbour'], $stored);
        $this->assertSame(['error' => 'This type of file can’t be uploaded.'], $page->instance()->storeDroppedUpload('u3', $photos->id));
        $this->assertSame(['error' => 'The upload didn’t arrive.'], $page->instance()->storeDroppedUpload('u1', $photos->id));

        $notes = $page->instance()->storeDroppedUpload('u2', $photos->id);
        $pdf = Asset::query()->findOrFail($notes['id']);

        $this->assertSame(AssetType::Pdf, $pdf->type());
        $this->assertSame('application/pdf', $pdf->mime_type);
        $this->assertTrue($pdf->folder->is($photos));
        $this->assertSame(2, Asset::query()->count());
    }

    public function test_the_library_can_be_shown_as_a_list_and_remembers_the_choice(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord');

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertDontSeeHtml('fi-breadcrumbs')
            ->assertDontSee('Every image on the site')
            ->assertTableColumnDoesNotExist('created_at')
            ->call('switchDisplay', 'list')
            ->assertRedirect(ListAssets::getUrl(['view' => 'list']));

        $this->assertSame('list', session('media.display'));

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertSet('display', 'list')
            ->assertTableColumnExists('created_at')
            ->assertCanSeeTableRecords([$fjord])
            ->sortTable('title');
    }

    public function test_editing_an_image_shows_its_folder_path_as_breadcrumbs(): void
    {
        $fjord = $this->asset('fjord', 'Photos/Norway');
        $photos = AssetFolder::query()->where('name', 'Photos')->sole();

        $breadcrumbs = Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditAsset::class, ['record' => $fjord->getRouteKey()])
            ->instance()
            ->getBreadcrumbs();

        $this->assertSame(['Library', 'Photos', 'Norway'], array_values($breadcrumbs));
        $this->assertSame(AssetResource::getUrl('index', ['folder' => $photos->id]), array_keys($breadcrumbs)[1]);
    }

    public function test_a_folder_tiles_menu_renames_and_deletes_that_folder(): void
    {
        $folder = AssetFolder::query()->create(['name' => 'Photos']);

        $page = Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertActionHidden('renameFolder')
            ->callAction('renameFolder', ['name' => 'Pictures'], ['folder' => $folder->id])
            ->assertHasNoActionErrors();

        $this->assertSame('Pictures', $folder->fresh()->name);

        $page->callAction('deleteFolder', arguments: ['folder' => $folder->id]);

        $this->assertNull($folder->fresh());
    }

    public function test_folders_never_end_up_next_to_one_with_the_same_name(): void
    {
        $photos = AssetFolder::query()->create(['name' => 'Photos']);
        $old = AssetFolder::query()->create(['name' => 'Old', 'parent_id' => $photos->id]);
        AssetFolder::query()->create(['name' => '2024', 'parent_id' => $photos->id]);
        AssetFolder::query()->create(['name' => '2024', 'parent_id' => $old->id]);
        $stray = AssetFolder::query()->create(['name' => 'Old']);

        $page = Livewire::actingAs($this->userWithRole('Editor'))->test(ListAssets::class);

        // Deleting Photos/Old would put its 2024 next to Photos/2024.
        $page->callAction('deleteFolder', arguments: ['folder' => $old->id])->assertNotified('“Old” can’t be deleted yet');
        $this->assertNotNull($old->fresh());

        // Moving the top-level Old into Photos would put it next to Photos/Old.
        Livewire::test(ListAssets::class)->callAction('moveFolder', ['parent_id' => $photos->id], ['folder' => $stray->id])->assertNotified();
        $this->assertNull($stray->fresh()->parent_id);
    }
}
