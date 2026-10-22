<?php

namespace Aurora\Tests\Feature;

use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use Aurora\Blocks\Contracts\Container;
use Aurora\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

class MakeBlockTest extends TestCase
{
    /**
     * Where the test's blocks are made: never the site's own folders.
     */
    protected string $path;

    protected string $namespace;

    /**
     * @var array<int, string>
     */
    protected array $views = [];

    /**
     * The blocks registered before the test: the ones it adds go again afterwards, since the
     * list outlives the app between tests.
     *
     * @var array<string, class-string<Block>>
     */
    protected array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = Str::lower(substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 2)), 0, 8));
        $this->registered = Blocks::all();
        $this->path = sys_get_temp_dir().'/aurora-blocks-'.$suffix;
        $this->namespace = 'Generated'.$suffix;
        config(['aurora.block_path' => $this->path, 'aurora.block_namespace' => $this->namespace]);
    }

    protected function tearDown(): void
    {
        foreach ($this->views as $view) {
            @unlink(resource_path("views/components/blocks/{$view}.blade.php"));
        }

        (new Filesystem)->deleteDirectory($this->path);
        $registered = $this->registered;
        (fn () => static::$blocks = $registered)->call(new Blocks);

        parent::tearDown();
    }

    /**
     * @return class-string<Block>
     */
    protected function make(string $name, array $options = []): string
    {
        $this->artisan('aurora:make-block', ['name' => $name.' '.substr($this->namespace, -8), ...$options])->assertSuccessful();

        $class = $this->namespace.'\\'.Str::studly($name.' '.substr($this->namespace, -8)).'Block';
        require_once $this->path.'/'.class_basename($class).'.php';
        $this->views[] = $class::type();

        return $class;
    }

    public function test_a_block_is_made_with_its_class_and_view_and_is_found_on_its_own(): void
    {
        $class = $this->make('Pricing table', ['--category' => 'marketing', '--icon' => 'phosphor-tag']);

        $this->assertTrue(is_subclass_of($class, Block::class));
        $this->assertStringStartsWith('pricing-table-', $class::type());
        $this->assertStringStartsWith('Pricing table', $class::label());
        $this->assertSame('marketing', $class::category());
        $this->assertSame('phosphor-tag', $class::icon());
        $this->assertSame(['title', 'text'], array_keys($class::editables()));

        // Found in aurora.block_path, without being listed anywhere.
        Blocks::discover($this->path, $this->namespace);
        $this->assertSame($class, Blocks::find($class::type()));

        // And its view draws the defaults.
        $html = Blade::render('<x-dynamic-component :component="$view" :data="$data" />', ['view' => $class::view(), 'data' => $class::defaults()]);
        $this->assertStringContainsString('A sentence or two about it.', $html);
    }

    public function test_a_container_block_holds_other_blocks_in_a_slot(): void
    {
        $class = $this->make('Showcase', ['--container' => true, '--category' => 'layout']);

        $this->assertTrue(is_subclass_of($class, Container::class));
        $this->assertSame(['content'], $class::slots([]));
        $this->assertStringContainsString("block_slot('content')", file_get_contents(resource_path('views/components/blocks/'.$class::type().'.blade.php')));
    }

    public function test_an_existing_block_is_only_replaced_when_asked(): void
    {
        $this->make('Banner');

        $this->artisan('aurora:make-block', ['name' => 'Banner '.substr($this->namespace, -8)])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->artisan('aurora:make-block', ['name' => 'Banner '.substr($this->namespace, -8), '--force' => true])->assertSuccessful();
    }

    public function test_names_and_categories_are_checked(): void
    {
        $this->artisan('aurora:make-block', ['name' => '3 columns'])->expectsOutputToContain('letters and numbers')->assertFailed();
        $this->artisan('aurora:make-block', ['name' => 'Quote', '--category' => 'fun'])->expectsOutputToContain('The category is one of')->assertFailed();
    }

    public function test_discovery_leaves_alone_what_isnt_a_block_and_keeps_registered_ones(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->path.'/Concerns');
        $files->put($this->path.'/Concerns/HasTone.php', "<?php\n\nnamespace {$this->namespace}\\Concerns;\n\ntrait HasTone {}\n");
        $files->put($this->path.'/BaseBlock.php', "<?php\n\nnamespace {$this->namespace};\n\nabstract class BaseBlock extends \\Aurora\\Blocks\\Block {}\n");
        require_once $this->path.'/Concerns/HasTone.php';
        require_once $this->path.'/BaseBlock.php';

        $before = Blocks::all();

        $this->assertSame([], Blocks::discover($this->path, $this->namespace));
        $this->assertSame($before, Blocks::all());

        // Blocks already registered (the site's own, in their order) aren't registered again.
        $this->assertSame([], Blocks::discover(app_path('Blocks'), 'App\\Blocks'));
    }
}
