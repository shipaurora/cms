<?php

namespace Aurora\Tests\Feature;

use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

    public function test_blocks_have_no_picture_unless_they_give_one(): void
    {
        $this->assertNull(Block::preview());
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
