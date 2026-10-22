<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\CreatePage;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Providers\AuroraServiceProvider;
use Aurora\Support\Layouts;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Once;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class LayoutsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Where this test's own layout views are (fixtures::landing, fixtures::minimal).
     */
    protected string $views;

    protected function setUp(): void
    {
        parent::setUp();

        // A site's own takes on Aurora's landing page and minimal layouts: its own views, one
        // with a footer and one without.
        $this->views = sys_get_temp_dir().'/aurora-layouts-'.uniqid();
        File::ensureDirectoryExists($this->views);
        File::put($this->views.'/landing.blade.php', '<main>Landing: {{ $page->title }}</main><footer>Footer</footer>');
        File::put($this->views.'/minimal.blade.php', '<main>Minimal: {{ $page->title }}</main>');
        View::addNamespace('fixtures', $this->views);

        Layouts::put($this->layout('landing'), ['name' => 'Landing page', 'handle' => 'landing', 'view' => 'fixtures::landing']);
        Layouts::put($this->layout('minimal'), ['name' => 'Minimal', 'handle' => 'minimal', 'view' => 'fixtures::minimal']);
    }

    protected function tearDown(): void
    {
        File::delete(resource_path('views/layouts/campaign.blade.php'));
        File::deleteDirectory($this->views);

        parent::tearDown();
    }

    protected function layout(string $handle): Layout
    {
        return Layout::query()->where('handle', $handle)->sole();
    }

    public function test_every_site_starts_with_aurora_s_four_layouts_standard_the_default(): void
    {
        Once::flush();

        $this->assertSame(['standard', 'landing', 'minimal', 'blank'], Layout::cached()->pluck('handle')->all());
        $this->assertSame('standard', Layout::default()->handle);

        // A new page starts on it.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => Page::factory()->create()->getKey()])
            ->assertSchemaStateSet(['layout_id' => $this->layout('standard')->getKey()]);
    }

    public function test_a_page_is_drawn_with_its_layout_s_view(): void
    {
        Page::factory()->homepage()->create();
        Page::factory()->published()->create(['slug' => 'standard-page']);
        Page::factory()->published()->create(['slug' => 'campaign', 'layout_id' => $this->layout('minimal')->getKey()]);

        $this->get('/standard-page')->assertOk()->assertSee('<footer', false);
        $this->get('/campaign')->assertOk()->assertDontSee('<footer', false);
    }

    public function test_the_installer_publishes_the_layout_views_for_the_site_to_make_its_own(): void
    {
        $this->assertContains(Layouts::path(), ServiceProvider::pathsToPublish(AuroraServiceProvider::class, 'aurora-layouts'));
        $this->assertSame('aurora::layouts.standard', $this->layout('standard')->view);

        // A view of the site's own, anywhere, draws the pages of a layout naming it.
        File::ensureDirectoryExists(resource_path('views/layouts'));
        File::put(resource_path('views/layouts/campaign.blade.php'), '<p>Campaign: {{ $page->title }} ({{ $layout->name }})</p>');

        $layout = Layouts::put(null, ['name' => 'Spring sale', 'handle' => 'spring-sale', 'view' => 'layouts.campaign']);
        Page::factory()->homepage()->create(['title' => 'Sale', 'layout_id' => $layout->getKey()]);

        $this->get('/')->assertOk()->assertSee('Campaign: Sale (Spring sale)');

        // Its view gone: drawn as standard rather than failing.
        File::delete(resource_path('views/layouts/campaign.blade.php'));
        app('view')->getFinder()->flush();
        $this->get('/')->assertOk()->assertSee('<footer', false);
    }

    public function test_layouts_are_added_changed_and_removed_in_the_layouts_dialog(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $landing = $this->layout('landing');
        $page = Page::factory()->create(['layout_id' => $landing->getKey()]);

        Livewire::actingAs($this->userWithRole('Author'))
            ->test(ListPages::class)
            ->assertActionHidden('layouts');

        $list = Livewire::actingAs($admin)->test(ListPages::class)->mountAction('layouts');

        $list->callAction(TestAction::make('addLayout')->schemaComponent('add-layout', schema: 'mountedActionSchema0'), data: [
            'name' => 'Campaign', 'handle' => 'campaign', 'description' => 'For campaigns.', 'view' => 'layouts.nowhere', 'is_default' => true,
        ])->assertHasActionErrors(['view']);

        // Checked as it's typed, too: a view that resolves says where it is.
        $list->mountAction(TestAction::make('addLayout')->schemaComponent('add-layout', schema: 'mountedActionSchema0'))
            ->setActionData(['view' => 'layouts.nowhere'])
            ->assertHasActionErrors(['view'])
            ->setActionData(['view' => 'aurora::layouts.standard'])
            ->assertHasNoActionErrors()
            ->call('unmountAction');

        $list->callAction(TestAction::make('addLayout')->schemaComponent('add-layout', schema: 'mountedActionSchema0'), data: [
            'name' => 'Campaign', 'handle' => 'campaign', 'description' => 'For campaigns.', 'view' => 'fixtures::minimal', 'is_default' => true,
        ])->assertHasNoActionErrors();

        $campaign = $this->layout('campaign');
        $this->assertTrue($campaign->is_default);
        $this->assertSame('fixtures::minimal', $campaign->view);
        $this->assertFalse($this->layout('standard')->is_default, 'One default.');

        // The default can't be deleted; another can, and its pages get the default.
        $this->assertFalse(Layouts::remove($campaign));
        $list->callAction(TestAction::make('deleteLayout')->schemaComponent('layout-'.$landing->id, schema: 'mountedActionSchema0'));

        $this->assertModelMissing($landing);
        $this->assertNull($page->fresh()->layout_id);
        $this->assertSame('campaign', $page->fresh()->layoutOrDefault()->handle);
    }

    public function test_ai_apps_pick_a_layout_by_its_handle(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create();

        CmsServer::actingAs($editor)
            ->tool(CreatePage::class, ['title' => 'Spring sale', 'layout' => 'landing'])
            ->assertOk();
        $this->assertSame('landing', Page::query()->whereSlug('spring-sale')->sole()->layout->handle);

        CmsServer::actingAs($editor)
            ->tool(UpdatePage::class, ['page' => $page->id, 'layout' => 'minimal'])
            ->assertOk();
        $this->assertSame('minimal', $page->fresh()->layout->handle);

        CmsServer::actingAs($editor)
            ->tool(UpdatePage::class, ['page' => $page->id, 'layout' => 'nope'])
            ->assertHasErrors(['No layout has the handle "nope"']);
    }

    public function test_the_page_s_layout_is_picked_from_thumbnails_of_the_page_drawn_with_each(): void
    {
        $minimal = $this->layout('minimal');
        $page = Page::factory()->published()->create(['slug' => 'campaign']);
        $picker = TestAction::make('pickLayout')->schemaComponent('layout_id', schema: 'form');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertSeeHtml('cms-layout-picker-chosen')
            ->callAction($picker, data: ['layout' => $minimal->getKey()])
            ->assertSchemaStateSet(['layout_id' => $minimal->getKey()])
            // The content preview draws the page with it before it's saved.
            ->assertSeeHtml('layout='.$minimal->getKey())
            ->call('save')
            ->assertHasNoFormErrors();

        // A live page: the new layout waits in its unpublished changes.
        $this->assertSame('minimal', $page->fresh()->applyWorkingCopy()->layout->handle);
    }

    public function test_a_thumbnail_draws_the_page_with_another_layout_without_changing_it(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'campaign']);

        $this->get($page->previewUrl(embed: true, layout: $this->layout('minimal')->getKey()))->assertOk()->assertDontSee('<footer', false);
        $this->get($page->previewUrl(embed: true))->assertOk()->assertSee('<footer', false);
        $this->assertNull($page->fresh()->layout_id);
    }
}
