<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Assets\RelationManagers\VariantsRelationManager;
use Aurora\Models\Asset;
use Aurora\Models\ImageFormat;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\AssetVariants;
use Aurora\Support\ImageFormats;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class ImageFormatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function image(): Asset
    {
        return AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 2400, 1600));
    }

    protected function format(string $handle): ImageFormat
    {
        return ImageFormat::query()->where('handle', $handle)->sole();
    }

    public function test_every_site_starts_with_small_medium_and_large(): void
    {
        $this->assertSame(['sm' => 640, 'md' => 1280, 'lg' => 1920], ImageFormat::responsiveWidths());

        $file = $this->image()->file();

        foreach (['thumb', 'sm', 'md', 'lg', 'og'] as $size) {
            $this->assertTrue($file->hasGeneratedConversion($size), "Missing the {$size} size.");
        }
    }

    public function test_adding_removing_and_changing_formats_brings_existing_images_up_to_date(): void
    {
        $image = $this->image();
        $smallFile = $image->file()->getPathRelativeToRoot('sm');
        Storage::disk('public')->assertExists($smallFile);

        ImageFormats::remove($this->format('sm'));
        ImageFormats::put($this->format('md'), ['name' => 'Medium', 'width' => 1280, 'height' => 1280, 'file_format' => 'webp', 'quality' => 82, 'is_responsive' => false]);
        ImageFormats::put(null, ['name' => 'Extra large', 'handle' => 'xl', 'width' => 2560, 'height' => null, 'file_format' => 'webp', 'quality' => 80, 'is_responsive' => true]);

        $this->assertSame(['md', 'lg', 'xl'], ImageFormat::cached()->pluck('handle')->all());

        $file = $image->fresh()->file();
        $this->assertFalse($file->hasGeneratedConversion('sm'));
        Storage::disk('public')->assertMissing($smallFile);
        $this->assertTrue($file->hasGeneratedConversion('xl'));

        [$width, $height] = getimagesize(Storage::disk('public')->path($file->getPathRelativeToRoot('md')));
        $this->assertSame([1280, 1280], [$width, $height], 'Medium is made again, cropped to a square.');

        // The srcset follows: Medium isn't responsive any more, Extra large is.
        $srcset = $image->fresh()->srcset();
        $this->assertStringContainsString('1920w', $srcset);
        $this->assertStringContainsString('2560w', $srcset);
        $this->assertStringNotContainsString('640w', $srcset);
        $this->assertStringNotContainsString('1280w', $srcset);

        // Code still asking for the removed size gets the largest responsive one.
        $this->assertSame($file->getUrl('xl'), $image->fresh()->url('sm'));
    }

    public function test_formats_are_added_and_edited_one_at_a_time_in_the_formats_dialog(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        // The Formats dialog stays open after each change: a fresh page for each one.
        $page = fn () => Livewire::actingAs($admin)->test(ListAssets::class);
        $medium = $this->format('md');

        $page()->callAction([TestAction::make('formats'), TestAction::make('addFormat')->schemaComponent('add-format')], [
            'name' => 'Square', 'handle' => 'square', 'width' => 800, 'height' => 800, 'file_format' => 'jpg', 'quality' => 85, 'is_responsive' => false,
        ])->assertHasNoActionErrors()->assertNotified('Added “Square”');

        $page()->callAction([TestAction::make('formats'), TestAction::make('editFormat')->schemaComponent('format-'.$medium->id)], [
            'name' => 'Medium', 'width' => 1440, 'height' => null, 'file_format' => 'webp', 'quality' => 82, 'is_responsive' => true,
        ])->assertHasNoActionErrors();

        $this->assertSame(['width' => 800, 'height' => 800], $this->format('square')->only(['width', 'height']));
        $this->assertSame(1440, $this->format('md')->width);

        // Handles: not the built-in ones, not one already taken, and plain lowercase.
        foreach (['thumb', 'lg', 'Wide image'] as $handle) {
            $page()->callAction([TestAction::make('formats'), TestAction::make('addFormat')->schemaComponent('add-format')], [
                'name' => 'Wide', 'handle' => $handle, 'width' => 3000, 'file_format' => 'webp', 'quality' => 80, 'is_responsive' => true,
            ])->assertHasActionErrors(['handle']);
        }

        $this->assertSame(4, ImageFormat::query()->count());
    }

    public function test_only_people_who_manage_settings_change_formats(): void
    {
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertActionHidden(TestAction::make('formats'));
    }

    public function test_an_images_formats_can_be_regenerated_from_its_formats_tab(): void
    {
        $image = $this->image();
        $file = $image->file();
        Storage::disk('public')->delete($file->getPathRelativeToRoot('md'));

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(VariantsRelationManager::class, ['ownerRecord' => $image, 'pageClass' => EditAsset::class])
            ->callAction(TestAction::make('regenerateFormats')->schemaComponent('regenerate', schema: 'content'))
            ->assertNotified('Formats regenerated');

        Storage::disk('public')->assertExists($file->getPathRelativeToRoot('md'));
    }

    public function test_the_formats_tab_shows_which_sizes_are_made_being_made_or_missing(): void
    {
        $image = $this->image();
        Queue::fake();

        ImageFormats::put(null, ['name' => 'Extra large', 'handle' => 'xl', 'width' => 2560, 'height' => null, 'file_format' => 'webp', 'quality' => 80, 'is_responsive' => true]);
        $image->file()->markAsConversionNotGenerated('og');

        $status = collect(AssetVariants::sizes($image->fresh()))->pluck('status', 'handle');

        $this->assertSame('ready', $status['md']);
        $this->assertSame('pending', $status['xl'], 'Waiting in the queue.');
        $this->assertSame('missing', $status['og']);
    }

    public function test_the_formats_tab_opens_the_image_formats_dialog(): void
    {
        $image = $this->image();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(VariantsRelationManager::class, ['ownerRecord' => $image, 'pageClass' => EditAsset::class])
            ->mountAction(TestAction::make('formats')->schemaComponent('regenerate', schema: 'content'))
            ->assertActionMounted(TestAction::make('formats')->schemaComponent('regenerate', schema: 'content'))
            ->assertSee('Image formats')
            ->assertSee('Medium');
    }

    public function test_one_format_of_an_image_can_be_regenerated_on_its_own(): void
    {
        $image = $this->image();
        $file = $image->file();
        Storage::disk('public')->delete([$file->getPathRelativeToRoot('md'), $file->getPathRelativeToRoot('lg')]);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(VariantsRelationManager::class, ['ownerRecord' => $image, 'pageClass' => EditAsset::class])
            ->callAction(TestAction::make('regenerateFormat')->arguments(['handle' => 'md']))
            ->assertNotified('“Medium” regenerated');

        Storage::disk('public')->assertExists($file->getPathRelativeToRoot('md'));
        Storage::disk('public')->assertMissing($file->getPathRelativeToRoot('lg'), 'Only the one asked for.');
    }

    public function test_an_images_formats_can_be_regenerated_from_its_page_too(): void
    {
        $image = $this->image();
        $file = $image->file();
        Storage::disk('public')->delete($file->getPathRelativeToRoot('lg'));
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(EditAsset::class, ['record' => $image->getKey()])
            ->callAction('regenerateFormats')
            ->assertNotified('Formats regenerated');

        Storage::disk('public')->assertExists($file->getPathRelativeToRoot('lg'));

        // Only images the library resizes have formats to make.
        $pdf = AssetLibrary::fromUpload(UploadedFile::fake()->createWithContent('menu.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"));

        Livewire::actingAs($admin)
            ->test(EditAsset::class, ['record' => $pdf->getKey()])
            ->assertActionHidden('regenerateFormats');
    }
}
