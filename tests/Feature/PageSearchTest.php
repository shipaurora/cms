<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\Actions\SearchIndexAction;
use Aurora\Filament\Resources\Pages\Actions\SearchPagesAction;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Http\Middleware\ResolveSite;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Aurora\Support\Search\DatabaseEngine;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Livewire\Livewire;

class PageSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A live page whose words are in a heading block (nested in a band) and its excerpt.
     */
    protected function page(array $attributes = []): Page
    {
        return Page::factory()->published()->create([
            'title' => 'Opening hours',
            'excerpt' => 'When the café is open.',
            'blocks' => [
                ['id' => 'band', 'type' => 'band', 'data' => [], 'children' => [
                    ['id' => 'heading', 'type' => 'heading', 'data' => ['text' => 'Fresh cinnamon buns every morning']],
                ]],
            ],
            ...$attributes,
        ]);
    }

    public function test_search_finds_a_live_page_by_what_its_blocks_say_out_of_the_box(): void
    {
        $this->assertSame('database', config('scout.driver'));
        $this->assertInstanceOf(DatabaseEngine::class, (new Page)->searchableUsing());

        $page = $this->page();

        $this->assertStringContainsString('Fresh cinnamon buns every morning', $page->search_text);
        $this->assertStringContainsString('When the café is open.', $page->search_text);
        $this->assertSame([$page->id], Page::search('cinnamon')->get()->modelKeys());
        $this->assertSame([], Page::search('lasagne')->get()->modelKeys());
    }

    public function test_drafts_pages_hidden_from_search_engines_trashed_pages_and_other_sites_are_left_out(): void
    {
        $this->page(['status' => PageStatus::Draft]);
        $this->page(['noindex' => true]);
        $this->page()->delete();
        $other = Site::query()->create(['name' => 'Other', 'handle' => 'other']);
        CurrentSite::using($other, fn () => $this->page());

        $this->assertSame([], Page::search('cinnamon')->get()->modelKeys());
    }

    public function test_on_the_website_search_finds_the_pages_of_the_site_the_address_is_for(): void
    {
        $main = $this->page();
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop', 'domain' => 'shop.example.com']);
        $shopPage = CurrentSite::using($shop, fn (): Page => $this->page());
        CurrentSite::forget();

        $searchOn = fn (string $url): array => (new ResolveSite)->handle(Request::create($url), fn (): JsonResponse => response()->json(Page::search('cinnamon')->get()->modelKeys()))->getData();

        $this->assertSame([$shopPage->id], $searchOn('http://shop.example.com/search'));
        $this->assertSame([$main->id], $searchOn('http://localhost/search'));

        // Another site's, named.
        $this->assertSame([$shopPage->id], Page::searchSite('cinnamon', site: $shop)->get()->modelKeys());
    }

    public function test_adding_to_the_search_s_query_keeps_drafts_and_other_sites_out(): void
    {
        $page = $this->page();
        $this->page(['status' => PageStatus::Draft]);

        $found = Page::search('cinnamon')->query(fn ($query) => $query->with('site'))->get();

        $this->assertSame([$page->id], $found->modelKeys());
        $this->assertTrue($found->first()->relationLoaded('site'));
    }

    public function test_a_page_is_indexed_again_on_save_and_on_reindex(): void
    {
        $page = $this->page();

        $page->update(['title' => 'Our bakery']);
        $this->assertSame([$page->id], Page::search('bakery')->get()->modelKeys());

        // Its search text gone stale (saved before search, say): Reindex, one page or several.
        $page->forceFill(['search_text' => null])->saveQuietly();
        $this->assertSame([], Page::search('bakery')->get()->modelKeys());

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->callAction('reindex')
            ->assertNotified('Reindexed for search');
        $this->assertSame([$page->id], Page::search('bakery')->get()->modelKeys());

        $page->forceFill(['search_text' => null])->saveQuietly();
        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->callAction(TestAction::make('reindex')->table()->bulk(), [$page]);
        $this->assertSame([$page->id], Page::search('bakery')->get()->modelKeys());
    }

    public function test_on_mysql_words_match_by_their_start(): void
    {
        $this->assertSame('photo* fjord*', DatabaseEngine::booleanQuery('photo, "fjord"!'));
        $this->assertSame('', DatabaseEngine::booleanQuery('+-~*'));
    }

    public function test_the_search_index_shows_what_is_indexed_and_tries_searches_against_the_page(): void
    {
        $page = $this->page();
        $this->page(['title' => 'Cinnamon facts']);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->mountAction('searchIndex')
            ->assertActionMounted('searchIndex');

        // What it indexes, by language, and how a search does against it.
        $this->assertStringContainsString('Fresh cinnamon buns every morning', implode("\n", SearchIndexAction::details($page, '')['texts']));
        $this->assertSame(['found' => true, 'position' => 2, 'total' => 2], SearchIndexAction::details($page, 'cinnam')['result']);
        $this->assertFalse(SearchIndexAction::details($page, 'lasagne')['result']['found']);

        // Nothing indexed shown until there's a search.
        $empty = view('aurora::filament.pages.search-index', SearchIndexAction::details($page, ''))->render();
        $this->assertStringNotContainsString('Fresh cinnamon buns', $empty);
        $this->assertStringContainsString('Type a search', $empty);

        // Then only the matching lines, the words marked.
        $html = view('aurora::filament.pages.search-index', SearchIndexAction::details($page, 'cinnam'))->render();
        $this->assertStringContainsString('<mark class="cms-search-mark">cinnamon</mark>', $html);
        $this->assertStringNotContainsString('When the café is open.', $html);
    }

    public function test_only_people_allowed_to_manage_search_see_its_actions(): void
    {
        $page = $this->page();

        // Nobody has "Manage search" by default: not even Editors.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertActionHidden('reindex')
            ->assertActionHidden('searchIndex');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->assertActionHidden(TestAction::make('reindex')->table()->bulk());
    }

    public function test_the_pages_list_tries_a_search_across_the_site_ranked_with_drafts_marked(): void
    {
        $live = $this->page();
        $draft = $this->page(['title' => 'Cinnamon plans', 'status' => PageStatus::Draft]);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->assertActionVisible('searchPages')
            ->mountAction('searchPages')
            ->assertActionMounted('searchPages');

        $results = SearchPagesAction::results('cinnamon');
        $this->assertEqualsCanonicalizing([$live->id, $draft->id], collect($results['pages'])->pluck('page.id')->all());
        $this->assertSame([1, 2], collect($results['pages'])->pluck('rank')->all());
        $this->assertSame('Draft', collect($results['pages'])->firstWhere('page.id', $draft->id)['hidden']);
        $this->assertNull(collect($results['pages'])->firstWhere('page.id', $live->id)['hidden']);
        $this->assertSame([], SearchPagesAction::results('')['pages'], 'Nothing until there is a search.');

        $html = view('aurora::filament.pages.search-results', $results)->render();
        $this->assertStringContainsString('Not in search: draft', $html);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->assertActionHidden('searchPages');
    }

    public function test_rich_text_is_indexed_as_separate_words(): void
    {
        $page = $this->page(['blocks' => [
            ['id' => 'text', 'type' => 'rich-text', 'data' => ['body' => '<h2>Content</h2><p>Add a language &amp; more</p><ul><li>Labels</li><li>Menus</li></ul>']],
        ]]);

        $this->assertStringContainsString('Content Add a language & more Labels Menus', $page->search_text);
    }

    public function test_reindex_all_indexes_every_page_of_the_site(): void
    {
        $pages = collect([$this->page(), $this->page(['title' => 'Cinnamon plans'])]);
        $pages->each(fn (Page $page) => $page->forceFill(['search_text' => null])->saveQuietly());

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->callAction('reindexAll')
            ->assertNotified('2 pages reindexed for search');

        $this->assertEqualsCanonicalizing($pages->pluck('id')->all(), Page::search('cinnamon')->get()->modelKeys());

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->assertActionHidden('reindexAll');
    }

    public function test_a_page_remembers_when_it_was_last_indexed(): void
    {
        $this->travelTo(now()->subHour());
        $page = $this->page();
        $this->assertEquals(now()->startOfSecond(), $page->fresh()->indexed_at);

        $this->travelBack();
        $page->reindex();

        $this->assertEquals(now()->startOfSecond(), $page->fresh()->indexed_at);
    }

    public function test_a_page_left_out_of_the_search_index_is_purged_from_it(): void
    {
        $page = $this->page();
        $this->assertSame([$page->id], Page::searchSite('cinnamon', liveOnly: false)->get()->modelKeys());

        $page->update(['exclude_from_search' => true]);

        $page->refresh();
        $this->assertNull($page->search_text);
        $this->assertNull($page->indexed_at);
        $this->assertFalse($page->shouldBeSearchable());
        $this->assertSame([], Page::searchSite('cinnamon', liveOnly: false)->get()->modelKeys());

        // Reindexing keeps it out; let back in, it's found again.
        $page->reindex();
        $this->assertNull($page->fresh()->search_text);

        $page->update(['exclude_from_search' => false]);
        $this->assertSame([$page->id], Page::search('cinnamon')->get()->modelKeys());
    }

    public function test_only_those_who_manage_search_leave_a_page_out_of_the_index(): void
    {
        $page = $this->page();
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertFormFieldHidden('exclude_from_search');

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertFormFieldVisible('exclude_from_search');

        CmsServer::actingAs($editor)
            ->tool(UpdatePage::class, ['page' => $page->id, 'exclude_from_search' => true])
            ->assertHasErrors(['Manage search']);
        $this->assertFalse($page->fresh()->exclude_from_search);
    }
}
