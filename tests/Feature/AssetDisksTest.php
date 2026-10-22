<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class AssetDisksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A second disk to move to: the media library only takes disks the app defines.
        config(['filesystems.disks.cloud' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/cloud'), 'url' => 'https://cdn.example.com', 'visibility' => 'public']]);
        Storage::fake('public');
        Storage::fake('cloud', ['url' => 'https://cdn.example.com', 'visibility' => 'public']);
    }

    public function test_files_stay_on_their_own_disk_and_in_the_library_when_the_default_disk_changes(): void
    {
        $old = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 40, 30));
        $this->assertSame('public', $old->disk);
        $oldUrl = $old->url();

        // Moving to another disk (local to S3, say).
        config(['media-library.disk_name' => 'cloud']);
        $new = AssetLibrary::fromUpload(UploadedFile::fake()->image('glacier.jpg', 40, 30));
        $this->assertSame('cloud', $new->disk);

        // Both are in the library and the picker.
        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListAssets::class)
            ->assertCanSeeTableRecords([$old, $new]);

        $this->assertEqualsCanonicalizing([$old->id, $new->id], AssetPicker::search('')->modelKeys());

        // Each is served from the disk it's on.
        $this->assertSame($oldUrl, AssetLibrary::url($old->id));
        $this->assertStringStartsWith('https://cdn.example.com/', (string) AssetLibrary::url($new->id));
        Storage::disk('public')->assertExists($old->file()->getPathRelativeToRoot());
    }

    public function test_a_file_replaced_after_a_move_goes_to_the_current_disk_with_its_asset(): void
    {
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 40, 30));
        config(['media-library.disk_name' => 'cloud']);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditAsset::class, ['record' => $asset->getKey()])
            ->callAction('replace', ['file' => UploadedFile::fake()->image('fjord-2.jpg', 40, 30)])
            ->assertHasNoActionErrors();

        $this->assertSame('cloud', $asset->fresh()->disk);
        $this->assertSame('cloud', $asset->fresh()->file()->disk);
    }
}
