<?php

namespace Aurora\Tests\Feature;

use Aurora\Console\Commands\MakeLayout;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

class MakeAuroraLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The view the command writes, in the app's own views: removed again after each test.
     */
    protected string $view;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view = resource_path('views/layouts/spring-campaign.blade.php');
    }

    protected function tearDown(): void
    {
        File::delete($this->view);

        parent::tearDown();
    }

    public function test_it_makes_the_view_from_the_standard_layout_and_registers_the_layout(): void
    {
        $this->artisan('make:aurora-layout', ['name' => 'Spring campaign', '--description' => 'Campaign pages, with no menu.', '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertFileExists($this->view);
        $this->assertStringContainsString('{{-- The Spring campaign layout: Campaign pages, with no menu. Made with php artisan make:aurora-layout', File::get($this->view));

        $layout = Layout::query()->where('handle', 'spring-campaign')->sole();

        $this->assertSame(['Spring campaign', 'Campaign pages, with no menu.', MakeLayout::ICON, 'layouts.spring-campaign', false], [$layout->name, $layout->description, $layout->icon, $layout->view, (bool) $layout->is_default]);

        // A page on it is drawn with the new view.
        Page::factory()->homepage()->published()->create(['title' => 'Spring sale', 'layout_id' => $layout->getKey()]);
        File::append($this->view, '<!-- the spring campaign layout -->');

        $this->get('/')->assertOk()->assertSee('<!-- the spring campaign layout -->', false);
    }

    public function test_it_asks_for_the_name_and_description(): void
    {
        $this->artisan('make:aurora-layout')
            ->expectsQuestion('What is the layout called?', 'Spring campaign')
            ->expectsQuestion('What is it for?', '')
            ->assertSuccessful();

        $this->assertNull(Layout::query()->where('handle', 'spring-campaign')->value('description'));
    }

    public function test_it_leaves_an_existing_layout_alone_unless_forced(): void
    {
        $this->artisan('make:aurora-layout', ['name' => 'Spring campaign', '--no-interaction' => true])->assertSuccessful();
        Layout::query()->where('handle', 'spring-campaign')->update(['icon' => 'phosphor-flower']);

        $this->artisan('make:aurora-layout', ['name' => 'Spring campaign', '--no-interaction' => true])
            ->expectsOutputToContain('already a layout [spring-campaign]')
            ->assertFailed();

        $this->artisan('make:aurora-layout', ['name' => 'Spring campaign', '--description' => 'Now with a hero.', '--force' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $layout = Layout::query()->where('handle', 'spring-campaign')->sole();

        // Updated, with the icon chosen in the admin kept.
        $this->assertSame(['Now with a hero.', 'phosphor-flower'], [$layout->description, $layout->icon]);
    }
}
