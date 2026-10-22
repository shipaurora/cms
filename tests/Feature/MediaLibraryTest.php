<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Forms\Components\AssetGrid;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Assets\RelationManagers\UsagesRelationManager;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\ListImages;
use Aurora\Mcp\Tools\UploadImageFromUrl;
use Aurora\Models\Asset;
use Aurora\Models\Page;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function asset(array $attributes = []): Asset
    {
        return AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 2400, 1600), ['alt' => 'A deep blue fjord', ...$attributes]);
    }

    public function test_an_upload_becomes_an_asset_with_responsive_sizes(): void
    {
        $asset = $this->asset(['folder' => 'Norway']);

        $this->assertSame('Fjord', $asset->title);
        $this->assertSame(['width' => 2400, 'height' => 1600], $asset->dimensions());

        foreach (['thumb', 'sm', 'md', 'lg', 'og'] as $size) {
            $this->assertTrue($asset->file()->hasGeneratedConversion($size), "Missing the {$size} size.");
        }

        $this->assertStringContainsString('640w', $asset->srcset());
    }

    public function test_pages_record_where_each_asset_is_used(): void
    {
        $asset = $this->asset();
        $unused = $this->asset();

        $page = Page::factory()->create(['og_image' => $asset->id, 'blocks' => [
            ['id' => 'hero', 'type' => 'hero', 'data' => ['headline' => 'Hi', 'image' => $asset->id]],
            ['id' => 'gallery', 'type' => 'gallery', 'data' => ['images' => [$asset->id]]],
        ]]);

        $this->assertEqualsCanonicalizing(['hero', 'gallery', 'og_image'], $asset->usages()->pluck('location')->all());
        $this->assertSame(0, $unused->usages()->count());

        $page->update(['og_image' => null, 'blocks' => []]);

        $this->assertSame(0, $asset->usages()->count());
    }

    public function test_images_render_responsively_with_the_libraries_alt_text(): void
    {
        $asset = $this->asset();

        Page::factory()->homepage()->create(['blocks' => [
            ['id' => 'image', 'type' => 'image', 'data' => ['src' => $asset->id]],
        ]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('srcset=', false)
            ->assertSee('alt="A deep blue fjord"', false);
    }

    public function test_images_load_lazily_unless_the_caller_says_otherwise(): void
    {
        $asset = $this->asset();

        $lazy = Blade::render('<x-aurora::media :value="$id" />', ['id' => $asset->id]);
        $eager = Blade::render('<x-aurora::media :value="$id" loading="eager" fetchpriority="high" />', ['id' => $asset->id]);

        $this->assertStringContainsString('loading="lazy"', $lazy);
        $this->assertStringContainsString('loading="eager"', $eager);
        $this->assertStringNotContainsString('loading="lazy"', $eager);
        $this->assertStringContainsString('fetchpriority="high"', $eager);
    }

    public function test_legacy_paths_and_urls_still_render(): void
    {
        $this->assertSame('https://example.com/a.jpg', AssetLibrary::url('https://example.com/a.jpg'));
        $this->assertStringEndsWith('/storage/old/b.jpg', AssetLibrary::url('old/b.jpg'));
    }

    public function test_an_image_can_be_picked_for_a_page_from_the_library(): void
    {
        $editor = $this->userWithRole('Editor');
        $asset = $this->asset();
        $page = Page::factory()->create();

        Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->callAction(TestAction::make('pickAsset')->schemaComponent('og_image'), data: ['selection' => $asset->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($asset->id, $page->refresh()->og_image);
        $this->assertSame(1, $asset->usages()->count());
    }

    public function test_an_image_uploaded_inside_the_picker_lands_in_the_library(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'card', 'type' => 'card', 'data' => ['title' => 'Card']],
        ]]);

        Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('selectBlock', 'card')
            ->callAction(TestAction::make('pickAsset')->schemaComponent('image', schema: 'form'), data: [
                'uploads' => [UploadedFile::fake()->image('new.jpg', 800, 600)],
                'alt' => 'A new picture',
            ])
            ->assertHasNoActionErrors()
            ->call('save');

        $asset = Asset::query()->sole();
        $this->assertSame('A new picture', $asset->alt);
        $this->assertSame($asset->id, $page->refresh()->blocks[0]['data']['image']);
    }

    public function test_an_image_dropped_on_the_picker_grid_is_uploaded_and_selected(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create();

        $livewire = Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->mountAction(TestAction::make('pickAsset')->schemaComponent('og_image'));

        $grid = collect($livewire->instance()->getSchema($livewire->instance()->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof AssetGrid);

        // What the grid's drop handler does: upload beside the selection, then store.
        $livewire->set($grid->getDroppedStatePath(), [UploadedFile::fake()->image('dropped.jpg', 800, 600)])
            ->call('callSchemaComponentMethod', $grid->getKey(), 'storeDroppedFiles');

        $asset = Asset::query()->sole();
        $this->assertSame('Dropped', $asset->title);
        $livewire->assertSet($grid->getStatePath(), $asset->id);

        // Selected, not used yet: "Use image" does that.
        $livewire->call('callMountedAction')->assertHasNoActionErrors()->call('save');
        $this->assertSame($asset->id, $page->refresh()->og_image);
    }

    public function test_the_library_lists_assets_and_shows_where_they_are_used(): void
    {
        $editor = $this->userWithRole('Editor');
        $asset = $this->asset();
        Page::factory()->create(['blocks' => [['id' => 'image', 'type' => 'image', 'data' => ['src' => $asset->id]]]]);

        Livewire::actingAs($editor)->test(ListAssets::class)->assertCanSeeTableRecords([$asset]);

        Livewire::actingAs($editor)
            ->test(UsagesRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => EditAsset::class])
            ->assertCanSeeTableRecords($asset->usages);

        $this->actingAs($editor)->get(AssetResource::getUrl('edit', ['record' => $asset]))->assertOk()->assertSee('A deep blue fjord');
    }

    public function test_authors_can_use_the_library_but_not_manage_others_images(): void
    {
        $author = $this->userWithRole('Author');
        $asset = $this->asset();

        $this->assertTrue($author->can('viewAny', Asset::class));
        $this->assertTrue($author->can('create', Asset::class));
        $this->assertFalse($author->can('update', $asset));
        $this->assertFalse($author->can('delete', $asset));
        $this->assertFalse($this->userWithRole('Viewer')->can('viewAny', Asset::class));
    }

    public function test_the_ai_assistant_sees_and_adds_library_images(): void
    {
        $editor = $this->userWithRole('Editor');
        $asset = $this->asset(['folder' => 'Norway']);

        CmsServer::actingAs($editor)->tool(ListImages::class, ['search' => 'Norway'])
            ->assertOk()
            ->assertSee([$asset->id, 'A deep blue fjord']);

        UploadImageFromUrl::$resolver = fn (string $host): array => ['93.184.216.34'];
        Http::fake(['*' => Http::response(UploadedFile::fake()->image('team.jpg', 400, 300)->getContent(), 200, ['Content-Type' => 'image/jpeg'])]);

        CmsServer::actingAs($editor)->tool(UploadImageFromUrl::class, ['url' => 'https://example.com/team.jpg', 'alt' => 'The team on a hike', 'title' => 'Team hike'])
            ->assertOk()
            ->assertSee('Team hike');

        $this->assertSame('The team on a hike', Asset::query()->where('title', 'Team hike')->sole()->alt);
    }

    public function test_images_are_only_fetched_from_public_addresses_redirects_included(): void
    {
        $editor = $this->userWithRole('Editor');
        UploadImageFromUrl::$resolver = fn (string $host): array => $host === 'intranet.example.com' ? ['10.0.0.5'] : ['93.184.216.34'];
        Http::fake([
            'https://example.com/*' => Http::response('', 302, ['Location' => 'https://intranet.example.com/secret.png']),
            '*' => Http::response('secret', 200, ['Content-Type' => 'image/png']),
        ]);

        CmsServer::actingAs($editor)->tool(UploadImageFromUrl::class, ['url' => 'http://example.com/a.jpg'])->assertHasErrors(['public https']);
        CmsServer::actingAs($editor)->tool(UploadImageFromUrl::class, ['url' => 'https://example.com/a.jpg'])->assertHasErrors(['public https']);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'intranet'));
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_searching_finds_alt_text_in_any_case_and_language_but_not_language_codes(): void
    {
        $this->withEnglishAndNorwegian();
        $sunset = AssetLibrary::fromUpload(UploadedFile::fake()->image('a.jpg', 20, 20), ['title' => 'One', 'alt' => 'Sunset over the fjord']);
        ContentLocale::using('nb', fn () => $sunset->update(['alt' => 'Solnedgang over fjorden']));

        $this->assertSame([$sunset->id], Asset::query()->search('SUNSET')->pluck('id')->all());
        $this->assertSame([$sunset->id], Asset::query()->search('solnedgang')->pluck('id')->all());
        $this->assertSame([], Asset::query()->search('nb')->pluck('id')->all());
    }

    protected function tearDown(): void
    {
        UploadImageFromUrl::$resolver = null;

        parent::tearDown();
    }
}
