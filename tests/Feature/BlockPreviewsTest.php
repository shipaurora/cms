<?php

namespace Aurora\Tests\Feature;

use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\GenerateBlockScreenshots;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\BlockScreenshots;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Workbench\App\Blocks\FeatureBlock;
use Workbench\App\Blocks\GridBlock;

class BlockPreviewsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, class-string<Block>>
     */
    protected array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registered = Blocks::all();
    }

    protected function tearDown(): void
    {
        // The list of blocks outlives the app between tests: the one added here goes again.
        $registered = $this->registered;
        (fn () => static::$blocks = $registered)->call(new Blocks);

        parent::tearDown();
    }

    public function test_a_block_s_picture_is_in_its_card_in_the_visual_editor_s_block_list(): void
    {
        Blocks::register([PictureBlock::class]);
        $page = Page::factory()->create();

        $this->actingAs($this->userWithRole('Editor'))
            ->get(PageResource::getUrl('design', ['record' => $page]))
            ->assertOk()
            ->assertSee('<img src="https://example.com/pictured.png" alt="" loading="lazy" draggable="false" class="cms-palette-preview">', false);
    }

    public function test_a_screenshot_uploaded_in_settings_replaces_the_block_s_own_picture(): void
    {
        Storage::fake('public');
        Blocks::register([PictureBlock::class]);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $settings = Livewire::withQueryParams(['tab' => 'templates'])
            ->test(SiteSettings::class)
            ->assertSee('Pictured')
            ->assertActionHidden('save');

        $settings->callAction(TestAction::make('uploadBlockPreview')->schemaComponent('section.blocks.block-pictured', schema: 'form'), data: [
            'screenshot' => UploadedFile::fake()->image('pictured.png', 1200, 700),
        ])->assertHasNoActionErrors();

        $first = Setting::get(Blocks::PREVIEWS_SETTING)['pictured'];
        $this->assertStringStartsWith('block-previews/', $first);
        $this->assertSame(Storage::disk('public')->url($first), Blocks::preview('pictured'));

        // Another one: the first file goes. Removing it: the block's own picture again.
        $settings->callAction(TestAction::make('uploadBlockPreview')->schemaComponent('section.blocks.block-pictured', schema: 'form'), data: [
            'screenshot' => UploadedFile::fake()->image('again.png', 1200, 700),
        ]);
        Storage::disk('public')->assertMissing($first);

        $settings->callAction(TestAction::make('resetBlockPreview')->schemaComponent('section.blocks.block-pictured', schema: 'form'));
        $this->assertSame('https://example.com/pictured.png', Blocks::preview('pictured'));
        $this->assertSame([], Setting::get(Blocks::PREVIEWS_SETTING));
    }

    public function test_settings_say_why_there_are_no_blocks_when_the_website_has_none_of_its_own(): void
    {
        (fn () => static::$blocks = [])->call(new Blocks);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'templates'])
            ->test(SiteSettings::class)
            ->assertSee('No blocks of the website’s own yet')
            ->assertSee('php artisan aurora:make-block')
            ->assertSee('How to make a block');
    }

    public function test_the_site_s_screenshot_of_a_block_is_its_picture_for_people_who_edit_pages(): void
    {
        $this->assertNull(Blocks::preview('hero'));

        $file = resource_path('block-previews/hero.webp');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, 'webp');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(dirname($file)));

        // Its address changes with the file, so browsers keep it for long.
        $url = Blocks::preview('hero');
        $this->assertSame(route('aurora.blocks.screenshot', ['type' => 'hero', 'v' => filemtime($file)]), $url);

        $this->get($url)->assertRedirect();
        $this->actingAs($this->userWithRole('Viewer'))->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole('Editor'))->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
        $this->get(route('aurora.blocks.screenshot', ['type' => 'grid']))->assertNotFound();
    }

    public function test_a_block_is_drawn_alone_from_its_example_data_to_take_its_screenshot(): void
    {
        // A block that holds others shows them in its sample, rather than an empty grid.
        Blocks::register([SampledGridBlock::class]);

        $this->get(route('aurora.blocks.sample', ['type' => 'hero']))->assertForbidden();
        $this->actingAs($this->userWithRole('Editor'))
            ->get(route('aurora.blocks.sample', ['type' => 'hero']))
            ->assertOk()
            ->assertSee('Introducing our latest release')
            ->assertSee('<meta name="robots" content="noindex', false);
        $this->get(route('aurora.blocks.sample', ['type' => 'nothing']))->assertNotFound();

        // A screenshot tool isn't signed in: the addresses aurora:block-previews gives are signed.
        auth()->logout();
        $grid = URL::temporarySignedRoute('aurora.blocks.sample', now()->addHour(), ['type' => 'grid']);
        $this->get($grid)->assertOk()->assertSee('Lightning fast');

        $this->artisan('aurora:block-previews')
            ->expectsOutputToContain('/aurora/blocks/hero/sample?expires=')
            ->expectsOutputToContain('aurora:block-previews {block} --from=screenshot.png')
            ->assertSuccessful();
    }

    public function test_a_block_without_a_picture_is_shown_live_in_the_block_list_until_it_has_one(): void
    {
        Blocks::register([PictureBlock::class]);
        $page = Page::factory()->create();
        $this->actingAs($this->userWithRole('Editor'));
        $design = fn (): string => $this->get(PageResource::getUrl('design', ['record' => $page]))->assertOk()->getContent();

        // The same address on every render, so the editor redrawing doesn't reload it.
        $sample = BlockScreenshots::sampleUrl('hero', minutes: null);
        $this->assertSame($sample, BlockScreenshots::sampleUrl('hero', minutes: null));
        $html = $design();
        $this->assertStringContainsString('src="'.e($sample).'"', $html);
        $this->assertStringContainsString('sandbox="allow-scripts"', $html);
        $this->assertStringNotContainsString('expires=', $sample);

        // It opens for the sandboxed frame, which has no session of the admin.
        auth()->logout();
        $this->get($sample)->assertOk()->assertSee('Introducing our latest release');

        // A block with a picture keeps it; the hero's screenshot replaces the live one.
        $this->actingAs($this->userWithRole('Editor'));
        $this->assertStringNotContainsString(e(BlockScreenshots::sampleUrl('pictured', minutes: null)), $html);
        $this->assertStringContainsString('src="https://example.com/pictured.png"', $html);

        BlockScreenshots::saveToSite('hero', BlockScreenshots::toWebp(UploadedFile::fake()->image('hero.png', 1600, 900)->getContent()));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(resource_path('block-previews')));
        $this->assertStringNotContainsString(e($sample), $design());
    }

    public function test_a_coding_agent_lists_the_missing_screenshots_and_saves_the_ones_it_takes(): void
    {
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(resource_path('block-previews')));

        $this->assertSame(0, Artisan::call('aurora:block-previews', ['--missing' => true, '--json' => true]));
        $listed = json_decode(Artisan::output(), true);
        $this->assertSame([1600, 900], $listed['window']);
        $hero = collect($listed['blocks'])->firstWhere('type', 'hero');
        $this->assertSame('resources/block-previews/hero.webp', $hero['file']);
        $this->get($hero['sample_url'])->assertOk();

        // Whatever size the agent's browser took it at: the block list's size and kind.
        $png = sys_get_temp_dir().'/hero-'.Str::random(8).'.png';
        File::put($png, UploadedFile::fake()->image('hero.png', 1600, 1200)->getContent());
        $this->beforeApplicationDestroyed(fn () => File::delete($png));

        $this->artisan('aurora:block-previews', ['type' => 'hero', '--from' => $png])->expectsOutputToContain('Commit it with the block')->assertSuccessful();
        $this->assertSame([800, 450, IMAGETYPE_WEBP], array_slice(getimagesize(resource_path('block-previews/hero.webp')), 0, 3));
        $this->assertNotNull(Blocks::find('hero')::preview());

        Artisan::call('aurora:block-previews', ['--missing' => true, '--json' => true]);
        $this->assertNull(collect(json_decode(Artisan::output(), true)['blocks'])->firstWhere('type', 'hero'));

        $this->artisan('aurora:block-previews', ['--from' => $png])->expectsOutputToContain('Name the block')->assertFailed();
        $this->artisan('aurora:block-previews', ['type' => 'hero', '--from' => __FILE__])->expectsOutputToContain('isn\'t an image')->assertFailed();
    }

    public function test_aurora_takes_the_screenshots_itself_only_with_browsershot(): void
    {
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(resource_path('block-previews')));
        $this->beforeApplicationDestroyed(fn () => BlockScreenshots::fake(null));

        $this->artisan('aurora:block-previews', ['type' => 'hero', '--generate' => true])->expectsOutputToContain('spatie/browsershot')->assertFailed();

        // The browser opens the block's signed sample page.
        $opened = [];
        BlockScreenshots::fake(function (string $url) use (&$opened): string {
            $opened[] = $url;

            return UploadedFile::fake()->image('shot.png', 1600, 900)->getContent();
        });

        $this->artisan('aurora:block-previews', ['type' => 'hero', '--generate' => true])->assertSuccessful();
        $this->assertStringContainsString('/aurora/blocks/hero/sample?expires=', $opened[0]);
        $this->assertFileExists(resource_path('block-previews/hero.webp'));
    }

    public function test_ai_apps_take_the_missing_screenshots_where_browsershot_is_installed(): void
    {
        Storage::fake('public');
        $this->beforeApplicationDestroyed(fn () => BlockScreenshots::fake(null));
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        // Without Browsershot, there's no such tool.
        CmsServer::actingAs($admin)->tool(GenerateBlockScreenshots::class)->assertNotRegistered();

        BlockScreenshots::fake(fn (string $url): string => UploadedFile::fake()->image('shot.png', 1600, 900)->getContent());

        // Only for people who may upload screenshots in Settings › Blocks.
        CmsServer::actingAs($this->userWithRole('Editor'))->tool(GenerateBlockScreenshots::class)->assertNotRegistered();

        $missing = BlockScreenshots::missing();
        $this->assertContains('hero', $missing);

        CmsServer::actingAs($admin)->tool(GenerateBlockScreenshots::class)->assertOk()->assertSee('"hero"');

        // Saved as if uploaded: the live site's code is left alone.
        $this->assertSame([], BlockScreenshots::missing());
        $uploaded = Setting::get(Blocks::PREVIEWS_SETTING);
        $this->assertEqualsCanonicalizing($missing, array_keys($uploaded));
        Storage::disk('public')->assertExists($uploaded['hero']);
        $this->assertSame(Storage::disk('public')->url($uploaded['hero']), Blocks::preview('hero'));
        $this->assertFileDoesNotExist(resource_path('block-previews/hero.webp'));

        // Asked again for one block: its picture is replaced, and the old file goes.
        CmsServer::actingAs($admin)->tool(GenerateBlockScreenshots::class, ['type' => 'hero'])->assertOk();
        Storage::disk('public')->assertMissing($uploaded['hero']);
    }
}

class SampledGridBlock extends GridBlock
{
    public static function sample(): array
    {
        return [...parent::sample(), 'children' => ['items' => [
            ['type' => 'feature', 'data' => FeatureBlock::defaults()],
            ['type' => 'feature', 'data' => FeatureBlock::defaults()],
        ]]];
    }
}

class PictureBlock extends Block
{
    public static function type(): string
    {
        return 'pictured';
    }

    public static function label(): string
    {
        return 'Pictured';
    }

    public static function description(): string
    {
        return 'A block with a picture of itself.';
    }

    public static function schema(): array
    {
        return [];
    }

    public static function preview(): ?string
    {
        return 'https://example.com/pictured.png';
    }
}
