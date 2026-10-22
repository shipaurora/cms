<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\ListImages;
use Aurora\Models\Asset;
use Aurora\Models\AssetTag;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class MediaTagsTest extends TestCase
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

    public function test_a_new_tag_gets_a_random_colour_from_the_palette_unless_one_is_chosen(): void
    {
        $random = AssetTag::query()->create(['name' => 'Hero']);
        $chosen = AssetTag::query()->create(['name' => 'Winter', 'color' => '#123456']);

        $this->assertContains($random->color, AssetTag::COLORS);
        $this->assertSame('#123456', $chosen->color);
    }

    public function test_tags_are_added_under_organise_and_created_on_the_spot(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord');
        $hero = AssetTag::factory()->create(['name' => 'Hero']);

        Livewire::actingAs($editor)
            ->test(EditAsset::class, ['record' => $fjord->id])
            ->assertSeeHtml('background-color: '.$hero->color)
            ->fillForm(['tags' => [$hero->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Hero'], $fjord->fresh()->tags->pluck('name')->all());

        Livewire::actingAs($editor)
            ->test(EditAsset::class, ['record' => $fjord->id])
            ->assertFormFieldExists('tags')
            ->assertFormComponentActionExists('tags', 'createOption')
            ->callFormComponentAction('tags', 'createOption', ['name' => 'Winter', 'color' => '#0ea5e9'])
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame('#0ea5e9', AssetTag::query()->where('name', 'Winter')->value('color'));
    }

    public function test_the_library_shows_tags_as_coloured_dots_with_their_names_as_tooltips(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord');
        $fjord->tags()->attach(AssetTag::factory()->create(['name' => 'Landscape', 'color' => '#22c55e']));

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->assertSeeHtml('background-color: #22c55e')
            ->assertSeeHtml('content: \'Landscape\'');
    }

    public function test_tags_are_searchable_everywhere_images_are_searched(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord', 'Photos');
        $desk = $this->asset('desk');
        $landscape = AssetTag::factory()->create(['name' => 'Landscape']);
        $fjord->tags()->attach($landscape);

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->searchTable('landsc')
            ->assertCanSeeTableRecords([$fjord])
            ->assertCanNotSeeTableRecords([$desk])
            ->searchTable('')
            ->filterTable('tags', [$landscape->id])
            ->assertCanSeeTableRecords([$fjord])
            ->assertCanNotSeeTableRecords([$desk]);

        $this->assertSame([$fjord->id], AssetPicker::search('landscape')->modelKeys());

        CmsServer::actingAs($editor)
            ->tool(ListImages::class, ['search' => 'landscape'])
            ->assertOk()
            ->assertSee($fjord->id)
            ->assertSee('Landscape')
            ->assertDontSee($desk->id);
    }

    public function test_the_active_tag_filter_lists_plain_tag_names(): void
    {
        $landscape = AssetTag::factory()->create(['name' => 'Landscape']);
        $licensed = AssetTag::factory()->create(['name' => 'Licensed']);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->filterTable('tags', [$licensed->id, $landscape->id])
            ->assertSeeText('Tags: Landscape, Licensed')
            ->assertDontSeeHtml('&lt;span');
    }

    public function test_select_all_while_filtering_by_tag_moves_the_tagged_images_from_every_folder(): void
    {
        $editor = $this->userWithRole('Editor');
        $fjord = $this->asset('fjord', 'Photos');
        $lake = $this->asset('lake', 'Archive');
        $desk = $this->asset('desk');
        $landscape = AssetTag::factory()->create(['name' => 'Landscape']);
        $landscape->assets()->attach([$fjord->id, $lake->id]);
        $campaign = AssetLibrary::fromUpload(UploadedFile::fake()->image('x.jpg'), ['folder' => 'Campaign'])->folder;

        Livewire::actingAs($editor)
            ->test(ListAssets::class)
            ->filterTable('tags', [$landscape->id])
            ->assertSee('Showing all folders')
            ->call('moveAssetsToFolder', [], $campaign->id, true, []);

        $this->assertSame($campaign->id, $fjord->fresh()->folder_id);
        $this->assertSame($campaign->id, $lake->fresh()->folder_id);
        $this->assertNull($desk->fresh()->folder_id);
    }

    public function test_the_image_picker_filters_by_tag(): void
    {
        $fjord = $this->asset('fjord');
        $desk = $this->asset('desk');
        $landscape = AssetTag::factory()->create(['name' => 'Landscape']);
        $fjord->tags()->attach($landscape);

        $this->assertSame([$fjord->id], AssetPicker::search('', null, [$landscape->id])->modelKeys());
        $this->assertCount(2, AssetPicker::search(''));
    }
}
