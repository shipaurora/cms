<?php

namespace Aurora\Tests\Feature;

use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Models\Page;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Tests\TestCase;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\Blocks\QuoteBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use ReflectionProperty;

/**
 * What a website's blocks can ask while they're drawn, and which blocks it offers.
 */
class BlockApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $views;

    protected function setUp(): void
    {
        parent::setUp();

        // A block that says where it is: its depth, and the page it's on.
        $this->views = sys_get_temp_dir().'/aurora-block-api-'.uniqid();
        File::ensureDirectoryExists($this->views.'/components/blocks');
        File::put($this->views.'/components/blocks/probe.blade.php', '<p class="probe">depth {{ block_depth() }}/@blockDepth()/{{ is_nested_block() ? "nested" : "top" }} on {{ current_page()?->title ?? "no page" }}</p>@nested<i>in a container</i>@endnested');
        View::addLocation($this->views);
        Blocks::register([ProbeBlock::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->views);

        // The page builder's quote block back, for the next test.
        $forgotten = new ReflectionProperty(Blocks::class, 'forgotten');
        $forgotten->setValue(null, []);
        app(BlockRegistries::class)->for('admin')->register([QuoteBlock::class]);

        parent::tearDown();
    }

    public function test_a_block_knows_how_deep_it_sits_and_which_page_it_is_on(): void
    {
        $page = Page::factory()->homepage()->published()->create(['title' => 'Prices', 'blocks' => BlockTreeBuilder::flatten([
            ['type' => 'probe'],
            ['type' => 'band', 'children' => ['content' => [['type' => 'probe']]]],
        ])]);

        $this->get('/')
            ->assertOk()
            ->assertSee('depth 0/0/top on Prices', false)
            ->assertSee('depth 1/1/nested on Prices', false)
            ->assertSee('<i>in a container</i>', false);

        // On the canvas too.
        $this->actingAs($this->userWithRole('Editor'));
        Livewire::test(DesignPage::class, ['record' => $page->getKey()])
            ->assertSee('depth 0/0/top on Prices', false)
            ->assertSee('depth 1/1/nested on Prices', false);

        $this->assertSame(0, Blocks::depth());
    }

    public function test_a_page_s_blocks_take_attributes_and_can_be_a_list_of_their_own(): void
    {
        $page = Page::factory()->create(['title' => 'Prices', 'blocks' => BlockTreeBuilder::flatten([
            ['type' => 'probe'],
            ['type' => 'heading', 'data' => ['text' => 'Left out', 'level' => 'h2']],
        ])]);

        $html = Blade::render('<x-aurora::blocks :page="$page" class="flex flex-col gap-24" />', ['page' => $page]);
        $this->assertStringContainsString('class="fpb-page flex flex-col gap-24"', $html);
        $this->assertStringContainsString('Left out', $html);

        $html = Blade::render('<x-aurora::blocks :blocks="$blocks" />', ['blocks' => [$page->localizedBlocks()[0]]]);
        $this->assertStringContainsString('class="probe"', $html);
        $this->assertStringNotContainsString('Left out', $html);
    }

    public function test_the_page_builder_s_own_blocks_can_be_left_out(): void
    {
        $this->assertContains(QuoteBlock::class, Blocks::library());

        Blocks::forget('quote');

        $this->assertNotContains(QuoteBlock::class, Blocks::library());
        $this->assertFalse(app(BlockRegistries::class)->for('admin')->has('quote'));
        $this->assertTrue(app(BlockRegistries::class)->for('admin')->has('probe'));
    }
}

class ProbeBlock extends Block
{
    public static function type(): string
    {
        return 'probe';
    }

    public static function label(): string
    {
        return 'Probe';
    }

    public static function description(): string
    {
        return 'Says where it is.';
    }

    public static function schema(): array
    {
        return [];
    }
}
