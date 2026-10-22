<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Models\Asset;
use Aurora\Models\Page;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ImageEdits;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class ImageEditsTest extends TestCase
{
    use RefreshDatabase;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 2000, 1000), ['alt' => 'A fjord']);
    }

    protected function renditionSize(string $url): array
    {
        $path = str($url)->after('/storage/')->toString();

        return array_slice(getimagesizefromstring(Storage::disk('public')->get($path)), 0, 2);
    }

    public function test_an_unedited_image_is_stored_as_a_bare_id(): void
    {
        $this->assertSame($this->asset->id, ImageEdits::from(['asset' => $this->asset->id, 'crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])->toValue($this->asset->id));
    }

    public function test_a_crop_is_rendered_from_the_original_at_the_requested_width(): void
    {
        $value = ['asset' => $this->asset->id, 'crop' => ['x' => 0.25, 'y' => 0, 'width' => 0.5, 'height' => 1]];

        $url = AssetLibrary::url($value, 'sm');

        $this->assertStringContainsString('/renditions/'.$this->asset->id.'/', $url);
        $this->assertSame([640, 640], $this->renditionSize($url), 'A 1000 × 1000 crop fitted to the 640 px size.');
        $this->assertSame($url, AssetLibrary::url($value, 'sm'), 'Renditions are cached.');
    }

    public function test_rotation_happens_before_the_crop(): void
    {
        $url = AssetLibrary::url(['asset' => $this->asset->id, 'rotate' => 90, 'crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.5]], 'md');

        // Rotated, the 2000 × 1000 original is 1000 × 2000; its top half is 1000 × 1000.
        $this->assertSame([1000, 1000], $this->renditionSize($url));
    }

    public function test_the_same_image_is_framed_independently_in_two_blocks(): void
    {
        $hero = ['asset' => $this->asset->id, 'aspect' => '16/9', 'crop' => ['x' => 0, 'y' => 0.1, 'width' => 1, 'height' => 0.9]];
        $card = ['asset' => $this->asset->id, 'grayscale' => true];

        $this->assertNotSame(AssetLibrary::url($hero), AssetLibrary::url($card));
        $this->assertNotSame(AssetLibrary::url($hero), $this->asset->url('md'), 'The original is left alone.');

        Page::factory()->create(['blocks' => [
            ['id' => 'a', 'type' => 'hero', 'data' => ['image' => $hero]],
            ['id' => 'b', 'type' => 'card', 'data' => ['image' => $card]],
        ]]);

        $this->assertEqualsCanonicalizing(['hero', 'card'], $this->asset->usages()->pluck('location')->all());
    }

    public function test_the_adjust_action_stores_edits_on_that_block_only(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'one', 'type' => 'image', 'data' => ['src' => $this->asset->id]],
            ['id' => 'two', 'type' => 'image', 'data' => ['src' => $this->asset->id]],
        ]]);

        Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('selectBlock', 'one')
            ->callAction(
                TestAction::make('adjustAsset')->schemaComponent('src', schema: 'form')->arguments(['index' => 0]),
                data: ['edits' => ['asset' => $this->asset->id, 'aspect' => '1/1', 'crop' => ['x' => 0.25, 'y' => 0, 'width' => 0.5, 'height' => 1], 'exposure' => 20]],
            )
            ->call('save');

        $blocks = collect($page->refresh()->blocks)->keyBy('id');

        $this->assertEquals(['asset' => $this->asset->id, 'crop' => ['x' => 0.25, 'y' => 0, 'width' => 0.5, 'height' => 1], 'aspect' => '1/1', 'exposure' => 20], $blocks['one']['data']['src']);
        $this->assertSame($this->asset->id, $blocks['two']['data']['src']);
    }

    public function test_empty_image_placeholders_open_the_picker_on_the_canvas_only(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->homepage()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'img', 'type' => 'image', 'data' => []],
        ]]);

        Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->assertSeeHtml('data-fpb-picker')
            ->assertSeeHtml("mountAction('pickAsset'");

        $this->get('/')->assertDontSee('data-fpb-picker', false);
    }
}
