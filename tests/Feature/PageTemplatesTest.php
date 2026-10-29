<?php

namespace Aurora\Tests\Feature;

use Aurora\Events\PageSaved;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Filament\Resources\PageTemplates\PageTemplateResource;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\ListPages as ListPagesTool;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\PageTemplate;
use Aurora\Models\Role;
use Aurora\Support\PageTemplates;
use Aurora\Support\PublicApi;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
        Livewire::actingAs($editor)
            ->test(ListPages::class)
            ->mountAction('create')
            ->assertFormFieldIsVisible('template')
            ->assertSeeInOrder(['Blank', 'Campaign']);

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

    public function test_a_page_is_saved_as_a_template(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));
        $page = Page::factory()->create([
            'title' => 'Our team',
            'blocks' => [['id' => 'a', 'type' => 'heading', 'parent' => null, 'data' => ['level' => 'h1', 'text' => 'Meet the team']]],
        ]);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callAction('saveAsPageTemplate', ['name' => 'Team page', 'description' => 'People, by department.']);

        $template = PageTemplate::query()->sole();
        $this->assertSame('Team page', $template->title);
        $this->assertSame('Meet the team', $template->blocks[0]['data']['text']);
        $this->assertNotSame('a', $template->blocks[0]['id']);
        $this->assertSame('Meet the team', $page->fresh()->blocks[0]['data']['text']);
    }
}
