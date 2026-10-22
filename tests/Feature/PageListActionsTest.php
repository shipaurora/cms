<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class PageListActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_in_review_is_published_from_the_list(): void
    {
        $author = $this->userWithRole('Author');
        $page = Page::factory()->create(['status' => PageStatus::Review, 'author_id' => $author->id]);
        $draft = Page::factory()->create(['status' => PageStatus::Draft]);

        // Authors can't publish: no action for them.
        Livewire::actingAs($author)
            ->test(ListPages::class)
            ->assertActionHidden(TestAction::make('publishPage')->table($page));

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->assertActionHidden(TestAction::make('publishPage')->table($draft))
            ->callAction(TestAction::make('publishPage')->table($page))
            ->assertNotified();

        $page->refresh();

        $this->assertSame(PageStatus::Published, $page->status);
        $this->assertTrue($page->isLive());
    }

    public function test_deleting_refreshes_the_sidebar_so_trash_can_appear(): void
    {
        $page = Page::factory()->create();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->callAction(TestAction::make('delete')->table($page))
            ->assertDispatched('refresh-sidebar');
    }

    public function test_a_new_page_starts_from_its_title(): void
    {
        $this->withEnglishAndNorwegian();
        Page::factory()->create(['slug' => 'about-us']);
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(ListPages::class)
            ->callAction('create', ['title' => 'About us'])
            ->assertRedirect(PageResource::getUrl('create', ['title' => 'About us']));

        // In the default language, even when the switcher was last left on another one.
        session()->put('spatie_translatable_active_locale', 'nb');

        Livewire::withQueryParams(['title' => 'About us'])
            ->actingAs($editor)
            ->test(CreatePage::class)
            ->assertSet('activeLocale', 'en')
            ->assertSchemaStateSet(['title' => 'About us', 'slug' => 'about-us-1']);
    }

    public function test_pages_sort_by_their_title_in_the_content_language_ignoring_case(): void
    {
        $this->withEnglishAndNorwegian();
        $banana = Page::factory()->create(['title' => 'banana']);
        $apple = Page::factory()->create(['title' => 'Apple']);
        $cherry = Page::factory()->create(['title' => 'cherry']);
        $cherry->setTranslation('title', 'nb', 'Ananas')->save();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->sortTable('title')
            ->assertCanSeeTableRecords([$apple, $banana, $cherry], inOrder: true)
            ->sortTable('title', 'desc')
            ->assertCanSeeTableRecords([$cherry, $banana, $apple], inOrder: true);

        // In Norwegian, by the Norwegian title; pages without one by their English title.
        $this->assertSame(
            [$cherry->id, $apple->id, $banana->id],
            ContentLocale::using('nb', fn (): array => Page::query()->orderByTitle()->pluck('id')->all()),
        );
    }
}
