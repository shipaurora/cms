<?php

namespace Aurora\Tests\Feature;

use Aurora\Blocks\Fields;
use Aurora\Enums\AssetType;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Forms\Components\RichTextLinkAction;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Redirects\Pages\ManageRedirects;
use Aurora\Models\Asset;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\BlockCatalogue;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

class MediaFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function image(string $name): Asset
    {
        return AssetLibrary::fromUpload(UploadedFile::fake()->image($name.'.jpg', 200, 100));
    }

    protected function file(string $seeded): Asset
    {
        return AssetLibrary::fromFile($this->fixture($seeded));
    }

    public function test_every_file_keeps_its_type_and_shows_its_icon(): void
    {
        $this->assertSame(AssetType::Image, $this->image('fjord')->type());
        $this->assertSame(AssetType::Pdf, $this->file('brand-guidelines.pdf')->type());
        $this->assertSame(AssetType::Spreadsheet, $this->file('price-list.csv')->type());
        $this->assertSame(AssetType::Audio, $this->file('studio-jingle.wav')->type());

        $this->assertSame('phosphor-file-pdf', AssetType::Pdf->getIcon());
        $this->assertSame(AssetType::Other, AssetType::fromMimeType('application/x-unknown'));
        $this->assertSame('phosphor-file', AssetType::Other->getIcon());
    }

    public function test_the_library_filters_by_type(): void
    {
        $fjord = $this->image('fjord');
        $guidelines = $this->file('brand-guidelines.pdf');
        $jingle = $this->file('studio-jingle.wav');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertCanSeeTableRecords([$fjord])
            ->filterTable('type', [AssetType::Pdf->value, AssetType::Audio->value])
            ->assertCanSeeTableRecords([$guidelines, $jingle])
            ->assertCanNotSeeTableRecords([$fjord]);
    }

    public function test_image_fields_only_take_images(): void
    {
        $fjord = $this->image('fjord');
        $jingle = $this->file('studio-jingle.wav');

        $this->assertSame([$fjord->id], AssetPicker::search('')->modelKeys());
        $this->assertNull(AssetLibrary::url($jingle->id));
        $this->assertNotNull(AssetLibrary::url($fjord->id));

        $this->assertSame([], BlockCatalogue::validate('image', ['src' => $fjord->id]));
        $this->assertNotSame([], BlockCatalogue::validate('image', ['src' => $jingle->id]));
    }

    public function test_dangerous_or_oversized_files_are_refused(): void
    {
        $script = UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>');
        $huge = UploadedFile::fake()->image('huge.jpg')->size(AssetType::maxImageKilobytes() + 1);

        $this->assertNotNull(AssetLibrary::rejectionFor($script));
        $this->assertNotNull(AssetLibrary::rejectionFor($huge));
    }

    public function test_files_up_to_the_librarys_limit_are_stored_past_spaties_own(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n".str_repeat('x', 20 * 1024 * 1024)."\n%%EOF");

        $asset = AssetLibrary::fromUpload(new UploadedFile($path, 'annual-report.pdf', 'application/pdf', null, true));

        $this->assertSame(AssetType::Pdf, $asset->type());
        $this->assertContains('max:'.AssetType::largestUploadKilobytes(), config('livewire.temporary_file_upload.rules'));
    }

    public function test_the_upload_limits_come_from_the_config(): void
    {
        config(['aurora.media.max_image_mb' => 1, 'aurora.media.max_file_mb' => 2]);

        $this->assertSame(1024, AssetType::Image->maxKilobytes());
        $this->assertSame(2048, AssetType::Pdf->maxKilobytes());
        $this->assertNotNull(AssetLibrary::rejectionFor(UploadedFile::fake()->image('big.jpg')->size(1025)));
        $this->assertNull(AssetLibrary::rejectionFor(UploadedFile::fake()->image('small.jpg')->size(1000)));
    }

    public function test_a_file_is_replaced_by_one_of_the_same_type(): void
    {
        $fjord = $this->image('fjord');
        $pdf = UploadedFile::fake()->createWithContent('notes.pdf', file_get_contents($this->fixture('brand-guidelines.pdf')));

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditAsset::class, ['record' => $fjord->getRouteKey()])
            ->callAction(TestAction::make('replace'), ['file' => $pdf]);

        $this->assertSame(AssetType::Image, $fjord->fresh()->type());
    }

    public function test_links_can_point_at_a_library_file_by_its_lasting_address(): void
    {
        $guidelines = $this->file('brand-guidelines.pdf');
        $address = '/files/'.$guidelines->id;

        $this->assertSame($address, $guidelines->linkPath());
        $this->get($address)->assertRedirect($guidelines->url(null));
        $this->get('/files/'.Str::uuid())->assertNotFound();

        $this->assertSame($address, Fields::href(['link_type' => 'file', 'link_file' => $guidelines->id]));
        $this->assertSame($address, RichTextLinkAction::href(['type' => 'file', 'file' => $guidelines->id]));
        $this->assertSame(['type' => 'file', 'page' => null, 'file' => $guidelines->id, 'url' => null], RichTextLinkAction::parse($address));
        $this->assertSame('url', RichTextLinkAction::parse('https://example.com')['type']);
    }

    public function test_a_file_linked_from_rich_text_counts_as_used(): void
    {
        $guidelines = $this->file('brand-guidelines.pdf');
        $page = Page::factory()->create(['blocks' => BlockTreeBuilder::flatten([
            ['type' => 'rich-text', 'data' => ['body' => '<p>Read our <a href="/files/'.$guidelines->id.'">brand guidelines</a>.</p>']],
        ])]);

        AssetLibrary::syncUsages($page, AssetLibrary::pageValues($page));

        $this->assertSame(1, $guidelines->usages()->count());
    }

    public function test_a_redirect_can_send_visitors_to_a_library_file(): void
    {
        $guidelines = $this->file('brand-guidelines.pdf');

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ManageRedirects::class)
            ->callAction('create', ['from_path' => '/brochure.pdf', 'target' => 'file', 'asset_id' => $guidelines->id, 'status_code' => 301])
            ->assertHasNoActionErrors();

        $redirect = Redirect::query()->where('from_path', '/brochure.pdf')->sole();

        $this->assertSame($guidelines->id, $redirect->asset_id);
        $this->assertNull($redirect->page_id);
        $this->get('/brochure.pdf')->assertRedirect($guidelines->url(null));
        $this->assertSame(1, $guidelines->usages()->count());

        $redirect->delete();

        $this->assertSame(0, $guidelines->usages()->count());
    }

    public function test_the_original_file_downloads_under_its_own_name(): void
    {
        $asset = $this->file('brand-guidelines.pdf');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditAsset::class, ['record' => $asset->getRouteKey()])
            ->callAction('download')
            ->assertFileDownloaded('brand-guidelines.pdf');
    }
}
