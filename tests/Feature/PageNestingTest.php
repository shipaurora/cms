<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\CreatePage as CreatePageTool;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Page;
use Aurora\Support\PageRedirects;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class PageNestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_to_a_subpage_goes_to_its_whole_address(): void
    {
        $docs = Page::factory()->published()->create(['slug' => 'docs']);
        $start = Page::factory()->published()->create(['slug' => 'get-started', 'parent_id' => $docs->id]);
        $install = Page::factory()->published()->create(['slug' => 'installation', 'parent_id' => $start->id]);
        $gone = Page::factory()->published()->create(['slug' => 'gone']);
        $gone->delete();

        // A card's or a button's page link (Fields::link()), as link_href() reads it.
        $this->assertSame('/docs/get-started/installation', link_href(['link_type' => 'page', 'link_page' => $install->id]));
        $this->assertSame('/docs', link_href(['link_type' => 'page', 'link_page' => $docs->id]));
        $this->assertNull(link_href(['link_type' => 'page', 'link_page' => $gone->id]));
    }

    public function test_the_pages_list_is_in_address_order_until_another_column_is_sorted(): void
    {
        $this->actingAs($this->userWithRole('Editor'));

        // Made out of order, and titled unlike their addresses.
        $us = Page::factory()->create(['title' => 'Alpha', 'slug' => 'about-us']);
        $team = Page::factory()->create(['title' => 'Bravo', 'slug' => 'team']);
        $about = Page::factory()->create(['title' => 'Delta', 'slug' => 'about']);
        $team->update(['parent_id' => $about->id]);
        $home = Page::factory()->homepage()->create(['title' => 'Echo']);
        $contact = Page::factory()->create(['title' => 'Charlie', 'slug' => 'Contact']);

        // /, /about, /about/team, /about-us, /Contact: each page followed by the ones under it.
        $this->assertSame([$home->id, $about->id, $team->id, $us->id, $contact->id], Page::idsInPathOrder());

        Livewire::test(ListPages::class)
            ->assertCanSeeTableRecords([$home, $about, $team, $us, $contact], inOrder: true)
            // The title still sorts by title.
            ->sortTable('title')
            ->assertCanSeeTableRecords([$us, $team, $contact, $about, $home], inOrder: true);

        // In another language, by its addresses there: /nb/kontakt before /nb/om-oss.
        $this->withEnglishAndNorwegian();
        $about->setTranslation('slug', 'nb', 'om-oss')->save();
        $contact->setTranslation('slug', 'nb', 'kontakt')->save();
        $us->setTranslation('slug', 'nb', 'om-oss-alle')->save();
        $team->setTranslation('slug', 'nb', 'laget')->save();

        $this->assertSame([$home->id, $contact->id, $about->id, $team->id, $us->id], Page::idsInPathOrder('nb'));
    }

    public function test_a_subpage_is_found_under_its_parents_address(): void
    {
        $docs = Page::factory()->published()->create(['slug' => 'docs']);
        $guides = Page::factory()->published()->create(['slug' => 'guides', 'parent_id' => $docs->id]);
        $start = Page::factory()->published()->create(['slug' => 'getting-started', 'parent_id' => $guides->id]);

        $this->assertSame('/docs/guides/getting-started', $start->path());
        $this->get('/docs/guides/getting-started')->assertOk();

        // An address that's out of date (or never was) leads to where the page is now.
        $this->get('/getting-started')->assertRedirect('/docs/guides/getting-started')->assertStatus(301);
        $this->get('/elsewhere/getting-started')->assertRedirect('/docs/guides/getting-started');
    }

    public function test_moving_or_renaming_a_parent_keeps_its_subpages_old_addresses_working(): void
    {
        $docs = Page::factory()->published()->create(['slug' => 'docs']);
        $start = Page::factory()->published()->create(['slug' => 'getting-started', 'parent_id' => $docs->id]);

        $docs->update(['slug' => 'documentation']);

        $this->assertSame('/documentation/getting-started', $start->fresh()->path());
        $this->get('/docs/getting-started')->assertRedirect('/documentation/getting-started');
    }

    public function test_a_page_under_the_homepage_or_in_another_language_gets_the_right_address(): void
    {
        $this->withEnglishAndNorwegian();
        $home = Page::factory()->homepage()->create();
        $docs = Page::factory()->published()->create(['title' => ['en' => 'Docs', 'nb' => 'Dokumentasjon'], 'slug' => ['en' => 'docs', 'nb' => 'dokumentasjon']]);
        $start = Page::factory()->published()->create(['title' => ['en' => 'Getting started', 'nb' => 'Kom i gang'], 'slug' => ['en' => 'getting-started', 'nb' => 'kom-i-gang'], 'parent_id' => $docs->id]);
        $about = Page::factory()->published()->create(['slug' => 'about', 'parent_id' => $home->id]);

        $this->assertSame('/nb/dokumentasjon/kom-i-gang', $start->path('nb'));
        $this->get('/nb/dokumentasjon/kom-i-gang')->assertOk();
        $this->assertSame('/about', $about->path('en'), 'The homepage adds nothing to its pages\' addresses.');
    }

    public function test_a_subpage_without_its_own_address_in_a_language_answers_under_its_translated_parent(): void
    {
        $this->withEnglishAndNorwegian();
        $docs = Page::factory()->published()->create(['title' => ['en' => 'Docs', 'nb' => 'Dokumentasjon'], 'slug' => ['en' => 'docs', 'nb' => 'dokumentasjon']]);
        $install = Page::factory()->published()->create(['title' => 'Installation', 'slug' => 'installation', 'parent_id' => $docs->id]);

        $this->assertSame('/nb/dokumentasjon/installation', $install->path('nb'));
        $this->get('/nb/dokumentasjon/installation')->assertOk();

        // Its English address under the Norwegian prefix: on to its Norwegian one.
        $this->get('/nb/docs/installation')->assertRedirect('/nb/dokumentasjon/installation');
    }

    public function test_drafts_are_not_given_away_by_a_redirect(): void
    {
        $docs = Page::factory()->published()->create(['slug' => 'docs']);
        Page::factory()->create(['slug' => 'secret-plans', 'parent_id' => $docs->id]);

        $this->get('/elsewhere/secret-plans')->assertNotFound();
    }

    public function test_a_page_cannot_sit_under_itself_or_one_of_its_subpages(): void
    {
        $docs = Page::factory()->create(['slug' => 'docs']);
        $guides = Page::factory()->create(['slug' => 'guides', 'parent_id' => $docs->id]);

        foreach ([$docs->id, $guides->id] as $parent) {
            try {
                $docs->update(['parent_id' => $parent]);
                $this->fail('A loop of parents was saved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
            }
        }

        $this->assertNull($docs->fresh()->parent_id);
    }

    public function test_renaming_a_subpage_redirects_its_full_old_address(): void
    {
        $docs = Page::factory()->published()->create(['slug' => 'docs']);
        $start = Page::factory()->published()->create(['slug' => 'getting-started', 'parent_id' => $docs->id]);

        $changes = PageRedirects::changes($start, ['en' => 'start-here']);

        $this->assertSame(['from' => '/docs/getting-started', 'to' => '/docs/start-here'], $changes['en']);
    }

    public function test_a_subpage_is_created_from_the_page_list(): void
    {
        $editor = $this->userWithRole('Editor');
        $docs = Page::factory()->create(['title' => 'Docs', 'slug' => 'docs']);
        $start = Page::factory()->create(['slug' => 'getting-started', 'parent_id' => $docs->id]);

        Livewire::actingAs($editor)
            ->test(ListPages::class)
            ->callAction(TestAction::make('createSubpage')->table($docs), ['title' => 'Installation'])
            ->assertRedirect(PageResource::getUrl('create', ['title' => 'Installation', 'parent' => $docs->id]));

        Livewire::withQueryParams(['title' => 'Installation', 'parent' => $docs->id])
            ->actingAs($editor)
            ->test(CreatePage::class)
            ->assertSchemaStateSet(['parent_id' => $docs->id, 'slug' => 'installation'], 'form')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('/docs/installation', Page::query()->whereSlug('installation')->sole()->path());

        // A page can't be moved under its own subpages: they aren't offered.
        $options = Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $docs->getRouteKey()])
            ->instance()->form->getComponent('parent_id')->getOptions();

        $this->assertArrayNotHasKey($docs->id, $options);
        $this->assertArrayNotHasKey($start->id, $options);
    }

    public function test_ai_assistants_can_create_and_move_subpages(): void
    {
        $editor = $this->userWithRole('Editor');
        $docs = Page::factory()->create(['slug' => 'docs']);
        $guides = Page::factory()->create(['slug' => 'guides']);

        CmsServer::actingAs($editor)->tool(CreatePageTool::class, ['title' => 'Installation', 'parent' => 'docs'])->assertOk();

        $installation = Page::query()->whereSlug('installation')->sole();
        $this->assertTrue($installation->parent->is($docs));

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => 'installation', 'parent' => $guides->id])->assertOk();
        $this->assertTrue($installation->fresh()->parent->is($guides));

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => 'guides', 'parent' => 'installation'])->assertHasErrors();
        $this->assertNull($guides->fresh()->parent_id);

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => 'installation', 'parent' => ''])->assertOk();
        $this->assertNull($installation->fresh()->parent_id);
    }

    public function test_the_first_page_is_the_homepage_and_has_nothing_to_sit_under(): void
    {
        $author = $this->userWithRole('Author');

        // An author's first page: the homepage, as a draft, with no parent to pick.
        Livewire::actingAs($author)
            ->test(CreatePage::class)
            ->assertSchemaStateSet(['is_homepage' => true], 'form')
            ->assertFormFieldHidden('parent_id')
            ->fillForm(['title' => 'Welcome', 'slug' => 'welcome'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Page::query()->sole()->is_homepage);

        // Someone who may publish starts the first page published, and lands on its settings.
        Page::query()->forceDelete();
        $editor = $this->userWithRole('Editor');

        $created = Livewire::actingAs($editor)
            ->test(CreatePage::class)
            ->assertSchemaStateSet(['is_homepage' => true, 'status' => PageStatus::Published], 'form')
            ->fillForm(['title' => 'Home', 'slug' => 'home'])
            ->call('create')
            ->assertHasNoFormErrors();

        $home = Page::query()->sole();
        $this->assertTrue($home->is_homepage && $home->isLive());
        $created->assertRedirect(PageResource::getUrl('edit', ['record' => $home]));

        // The next one is an ordinary page; it can't sit under the homepage, so there is still
        // nothing to pick until there is another page.
        Livewire::actingAs($author)
            ->test(CreatePage::class)
            ->assertSchemaStateSet(['is_homepage' => false], 'form')
            ->assertFormFieldHidden('parent_id');

        Page::factory()->create(['slug' => 'docs']);

        Livewire::actingAs($author)->test(CreatePage::class)->assertFormFieldVisible('parent_id');
    }
}
