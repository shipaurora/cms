<?php

namespace Aurora\Tests\Feature;

use Aurora\Console\Commands\MakeLayout;
use Aurora\Layouts\PageLayout;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Support\Layouts;
use Aurora\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

class MakeAuroraLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A folder and namespace of this test's own for the classes it makes (class names can't
     * be loaded twice in one run).
     */
    protected string $path;

    protected string $namespace;

    /**
     * The layout's handle, the same per test run: made unique by the namespace's suffix.
     */
    protected string $name;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = Str::lower(substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 2)), 0, 8));
        $this->path = sys_get_temp_dir().'/aurora-layouts-'.$suffix;
        $this->namespace = 'GeneratedLayouts'.$suffix;
        $this->name = 'Spring '.$suffix;
        config(['aurora.layout_path' => $this->path, 'aurora.layout_namespace' => $this->namespace]);
    }

    protected function tearDown(): void
    {
        File::delete($this->viewFile());
        (new Filesystem)->deleteDirectory($this->path);
        Layouts::forget($this->handle());

        parent::tearDown();
    }

    protected function handle(): string
    {
        return Str::slug($this->name);
    }

    protected function viewFile(): string
    {
        return resource_path('views/layouts/'.$this->handle().'.blade.php');
    }

    /**
     * @return class-string<PageLayout>
     */
    protected function made(): string
    {
        $class = $this->namespace.'\\'.Str::studly($this->name).'Layout';
        require_once $this->path.'/'.class_basename($class).'.php';

        return $class;
    }

    public function test_it_makes_the_layout_s_class_and_its_view_from_the_standard_layout(): void
    {
        $this->artisan('make:aurora-layout', ['name' => $this->name, '--description' => 'Campaign pages, with no menu.', '--no-interaction' => true])
            ->assertSuccessful();

        // Its class, from the stub; nothing in the database until it's discovered.
        $class = $this->made();
        $this->assertSame([$this->handle(), $this->name, 'Campaign pages, with no menu.', MakeLayout::ICON, 'layouts.'.$this->handle()], [$class::handle(), $class::name(), $class::description(), $class::icon(), $class::view()]);
        $this->assertFalse(Layout::query()->where('handle', $this->handle())->exists());
        $this->assertStringContainsString('{{-- The '.$this->name.' layout: Campaign pages, with no menu. Made with php artisan make:aurora-layout', File::get($this->viewFile()));

        // Found in aurora.layout_path, it's offered like any other layout, from its code.
        $this->assertSame([$class], Layouts::discover($this->path, $this->namespace));
        Once::flush();
        $layout = Layout::cached()->firstWhere('handle', $this->handle());
        $this->assertSame([$class, 'layouts.'.$this->handle()], [$layout->class, $layout->view]);

        // A page on it is drawn with the new view.
        Page::factory()->homepage()->published()->create(['title' => 'Spring sale', 'layout_id' => $layout->getKey()]);
        File::append($this->viewFile(), '<!-- the spring campaign layout -->');

        $this->get('/')->assertOk()->assertSee('<!-- the spring campaign layout -->', false);
    }

    public function test_it_asks_for_the_name_and_description(): void
    {
        $this->artisan('make:aurora-layout')
            ->expectsQuestion('What is the layout called?', $this->name)
            ->expectsQuestion('What is it for?', '')
            ->assertSuccessful();

        $this->assertNull($this->made()::description());
    }

    public function test_it_leaves_an_existing_layout_alone_unless_forced(): void
    {
        $this->artisan('make:aurora-layout', ['name' => $this->name, '--no-interaction' => true])->assertSuccessful();

        $this->artisan('make:aurora-layout', ['name' => $this->name, '--no-interaction' => true])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->artisan('make:aurora-layout', ['name' => $this->name, '--description' => "Now with a hero, it's said.", '--icon' => 'phosphor-flower', '--force' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $class = $this->made();
        $this->assertSame(["Now with a hero, it's said.", 'phosphor-flower'], [$class::description(), $class::icon()]);
    }

    public function test_it_says_when_a_layout_added_in_the_admin_has_the_handle(): void
    {
        Layouts::put(null, ['name' => 'Ours', 'handle' => $this->handle(), 'view' => 'aurora::layouts.standard']);

        $this->artisan('make:aurora-layout', ['name' => $this->name, '--no-interaction' => true])
            ->expectsOutputToContain('A layout added in the admin already has the handle')
            ->assertSuccessful();
    }

    public function test_discovery_leaves_alone_what_isn_t_a_layout(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->path);
        $files->put($this->path.'/BaseLayout.php', "<?php\n\nnamespace {$this->namespace};\n\nabstract class BaseLayout extends \\Aurora\\Layouts\\PageLayout {}\n");
        $files->put($this->path.'/Helper.php', "<?php\n\nnamespace {$this->namespace};\n\nclass Helper {}\n");
        require_once $this->path.'/BaseLayout.php';
        require_once $this->path.'/Helper.php';

        $this->assertSame([], Layouts::discover($this->path, $this->namespace));
        $this->assertSame([], Layouts::discover($this->path.'/missing', $this->namespace));
    }
}
