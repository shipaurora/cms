<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class FocalPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_cropped_sizes_keep_the_focal_point_in(): void
    {
        Storage::fake('public');
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('team.jpg', 1600, 900));

        // No focal point: crops from the middle.
        $asset->registerMediaConversions($asset->file());
        $this->assertTrue(collect($asset->mediaConversions)->firstWhere(fn ($conversion) => $conversion->getName() === 'og')->getManipulations()->getManipulationArgument('fit') !== null);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditAsset::class, ['record' => $asset->getKey()])
            ->fillForm(['focal_point' => ['x' => 0.25, 'y' => 0.4]])
            ->call('save')
            ->assertHasNoFormErrors();

        $asset->refresh();
        $this->assertSame(['x' => 0.25, 'y' => 0.4], $asset->focal_point);

        $asset->mediaConversions = [];
        $asset->registerMediaConversions($asset->file());
        $og = collect($asset->mediaConversions)->firstWhere(fn ($conversion) => $conversion->getName() === 'og');

        // Around the point, in the original's pixels.
        $this->assertSame([1200, 630, 400, 360], $og->getManipulations()->getManipulationArgument('focalCropAndResize'));
    }
}
