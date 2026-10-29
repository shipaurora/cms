<?php

namespace Aurora\Tests\Feature;

use Aurora\Events\PageSaved;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Filament\Resources\PageTemplates\Pages\DesignPageTemplate;
use Aurora\Filament\Resources\PageTemplates\PageTemplateResource;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\ListPages as ListPagesTool;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\PageTemplate;
use Aurora\Models\Role;
use Aurora\Models\Site;
use Aurora\Support\PageTemplates;
use Aurora\Support\PublicApi;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;

class PageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_is_never_a_page(): void
    {
        Event::fake([PageSaved::class]);
        $template = PageTemplates::create('News article', 'A heading, an image and the story.');
        // Whatever it's saved with, it stays off the website.
        $template->update(['status' => 'published', 'slug' => 'news-article']);
        $page = Page::factory()->published()->create(['title' => 'About']);

        $this->assertSame('draft', $template->fresh()->status->value);
        $this->assertNull(Page::query()->find($template->id));
        $this->assertFalse(Page::query()->whereKey($template->id)->exists());
        $this->assertNull(PageTemplate::query()->find($page->id));
        $this->assertTrue(PageTemplate::query()->whereKey($template->id)->exists());
        Event::assertNotDispatched(PageSaved::class, fn (PageSaved $event): bool => $event->page->is($template));

        $this->get('/news-article')->assertNotFound();
        $this->assertNull(PublicApi::pageAt('/news-article'));
        $this->get('/sitemap.xml')->assertDontSee('news-article');
        CmsServer::actingAs($this->userWithRole('Editor'))->tool(ListPagesTool::class)->assertOk()->assertDontSee('News article');
    }

    public function test_a_new_page_starts_from_a_template_with_its_layout_blocks_and_translations(): void
    {
        $editor = $this->userWithRole('Editor');
        $landing = Layout::query()->where('handle', 'landing')->sole();
        $template = PageTemplates::create('Campaign', null, $landing->id);
        $template->update([
            'blocks' => [['id' => 'a', 'type' => 'heading', 'parent' => null, 'data' => ['level' => 'h1', 'text' => 'Spring sale']]],
            'block_translations' => ['nb' => ['a' => ['text' => 'Vårsalg']]],
        ]);

        // The prompt offers it after "Blank", and carries the choice on.
        $this->actingAs($editor);
        $picker = $this->pickerHtml();
        $this->assertLessThan(strpos($picker, 'Campaign'), strpos($picker, 'Blank'));
        $this->assertSame(2, substr_count($picker, '<iframe'));

        Livewire::actingAs($editor)
            ->test(ListPages::class)
            ->callAction(TestAction::make('create'), ['title' => 'Summer sale', 'template' => $template->id])
            ->assertRedirect(PageResource::getUrl('create', ['title' => 'Summer sale', 'template' => $template->id]));

        Livewire::withQueryParams(['title' => 'Summer sale', 'template' => $template->id])
            ->actingAs($editor)
            ->test(CreatePage::class)
            ->assertSchemaStateSet(['layout_id' => $landing->id], 'form')
            ->call('create')
            ->assertHasNoFormErrors();

        $page = Page::query()->whereSlug('summer-sale')->sole();
        $block = $page->blocks[0];

        $this->assertSame($template->id, $page->template_id);
        $this->assertSame($landing->id, $page->layout_id);
        $this->assertSame('Spring sale', $block['data']['text']);
        // Its own block ids, with the translations kept under them.
        $this->assertNotSame('a', $block['id']);
        $this->assertSame(['nb' => [$block['id'] => ['text' => 'Vårsalg']]], $page->block_translations);
        // The template is as it was.
        $this->assertSame('a', $template->fresh()->blocks[0]['id']);
    }

    public function test_blank_is_drawn_as_an_empty_page_in_the_default_layout(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        PageTemplates::create('Campaign');

        preg_match('#src="([^"]*/preview/blank/[^"]*)"#', $this->pickerHtml(), $match);
        $this->assertNotEmpty($match, 'Blank has no thumbnail of its own.');

        $this->get(html_entity_decode($match[1]))
            ->assertOk()
            ->assertSee('<html', false);

        $this->get(route('pages.preview-blank', ['site' => Site::default()->id]))->assertForbidden();
    }

    public function test_a_template_and_a_page_never_take_each_others_address(): void
    {
        $template = PageTemplates::create('About');
        $page = Page::factory()->published()->create(['title' => 'About']);
        $contact = Page::factory()->published()->create(['title' => 'Contact']);
        $other = PageTemplates::create('Contact');

        $this->assertSame('about', $template->fresh()->slug);
        $this->assertSame('about', $page->fresh()->slug);
        $this->assertSame('contact', $other->fresh()->slug);
        $this->assertTrue(Page::slugIsAvailable('about', except: $page->id));
        $this->get('/about')->assertOk();
        $this->get('/contact')->assertOk();

        // Templates among themselves still differ.
        $this->assertSame('about-1', PageTemplates::create('About')->fresh()->slug);
    }

    public function test_the_editor_offers_no_publish_for_a_template_or_a_page_with_nothing_to_publish(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));
        $template = PageTemplates::create('Campaign');
        $draft = Page::factory()->create();

        $this->assertStringNotContainsString("mountAction('publishChanges'", Livewire::test(DesignPageTemplate::class, ['record' => $template->getRouteKey()])->html());
        $this->assertStringNotContainsString("mountAction('publishChanges'", Livewire::test(DesignPage::class, ['record' => $draft->getRouteKey()])->html());
    }

    public function test_the_new_page_prompt_has_no_picker_without_templates(): void
    {
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->mountAction('create')
            ->assertFormFieldIsHidden('template');
    }

    public function test_only_those_allowed_manage_templates(): void
    {
        $editor = $this->userWithRole('Editor');
        $template = PageTemplates::create('News article');

        $this->actingAs($editor)->get(PageTemplateResource::getUrl('design', ['record' => $template]))->assertForbidden();
        Livewire::actingAs($editor)->test(ListPages::class)->assertActionHidden('pageTemplates');

        Role::findByName('Editor', 'web')->givePermissionTo('manage-templates Page');
        $editor = $editor->fresh();

        $this->actingAs($editor)->get(PageTemplateResource::getUrl('design', ['record' => $template]))->assertOk();
        Livewire::actingAs($editor)->test(ListPages::class)->assertActionVisible('pageTemplates');
    }

    public function test_templates_are_made_duplicated_and_deleted_in_settings(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));
        $page = Page::factory()->create(['title' => 'Made earlier']);

        Livewire::withQueryParams(['tab' => 'templates', 'section' => 'page-templates'])
            ->test(SiteSettings::class)
            ->assertSee(['Page templates', 'No page templates yet']);

        $list = Livewire::test(ListPages::class)->mountAction('pageTemplates');
        $list->callAction(TestAction::make('addPageTemplate')->schemaComponent('add-page-template', schema: 'mountedActionSchema0'), data: [
            'name' => 'News article', 'description' => 'The story.', 'layout_id' => Layout::default()->id,
        ]);

        $template = PageTemplate::query()->sole();
        $list->assertRedirect(PageTemplateResource::getUrl('design', ['record' => $template]));
        $this->assertSame('The story.', $template->excerpt);

        $copy = PageTemplates::duplicate($template);
        $this->assertSame('News article (copy)', $copy->title);

        $page->update(['template_id' => $template->id]);
        $template->forceDelete();

        $this->assertNull($page->fresh()->template_id);
        $this->assertSame(1, PageTemplate::query()->count());
    }

    public function test_a_template_is_created_from_a_page_in_its_menu_or_the_page_list(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));
        $page = Page::factory()->create([
            'title' => 'Our team',
            'blocks' => [['id' => 'a', 'type' => 'heading', 'parent' => null, 'data' => ['level' => 'h1', 'text' => 'Meet the team']]],
        ]);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callAction('createTemplateFromPage', ['name' => 'Team page', 'description' => 'People, by department.']);

        $template = PageTemplate::query()->sole();
        $this->assertSame('Team page', $template->title);
        $this->assertSame('Meet the team', $template->blocks[0]['data']['text']);
        $this->assertNotSame('a', $template->blocks[0]['id']);
        $this->assertSame('Meet the team', $page->fresh()->blocks[0]['data']['text']);

        // The same from the page's row in the page list.
        Livewire::test(ListPages::class)
            ->callAction(TestAction::make('createTemplateFromPage')->table($page), ['name' => 'Another team page']);

        $this->assertSame(['Team page', 'Another team page'], PageTemplate::query()->orderBy('created_at')->get()->pluck('title')->all());

        // Not for those who don't manage templates.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->assertActionHidden(TestAction::make('createTemplateFromPage')->table($page));
    }

    /**
     * The "Start from" field of the "New page" dialog, drawn: the dialog's content isn't part of
     * the page's own HTML in a test.
     */
    protected function pickerHtml(): string
    {
        View::share('errors', new ViewErrorBag);
        $list = Livewire::test(ListPages::class)->mountAction('create');

        return (fn () => $this->getMountedActionSchema(0))->call($list->instance())->getComponent('template')->toHtml();
    }
}
