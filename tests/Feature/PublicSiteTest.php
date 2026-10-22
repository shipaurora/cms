<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Aurora\Support\PageRedirects;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_renders_its_blocks(): void
    {
        Page::factory()->homepage()->create(['blocks' => [
            ['id' => 'a', 'type' => 'hero', 'data' => ['headline' => 'Welcome to the *fjord*']],
        ]]);

        $this->get('/')->assertOk()->assertSee('Welcome to the')->assertSee('text-gradient', false);
    }

    public function test_without_a_default_site_requests_find_nothing_instead_of_failing(): void
    {
        Site::query()->update(['is_default' => false]);
        CurrentSite::forget();

        // The second request too: the first one's empty lookup isn't kept.
        $this->get('/about')->assertNotFound();
        $this->get('/about')->assertNotFound();
    }

    public function test_drafts_are_hidden_from_visitors(): void
    {
        $page = Page::factory()->create(['slug' => 'secret']);

        $this->get('/secret')->assertNotFound();
        $this->get($page->previewUrl())->assertOk()->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_scheduled_pages_go_live_at_their_publish_date(): void
    {
        Page::factory()->published()->create(['slug' => 'soon', 'published_at' => now()->addDay()]);

        $this->get('/soon')->assertNotFound();
        $this->travel(2)->days();
        $this->get('/soon')->assertOk();
    }

    public function test_a_renamed_page_redirects_its_old_address_only_when_asked_to(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'old-name']);
        $changes = PageRedirects::changes($page, ['en' => 'new-name']);

        $page->update(['slug' => 'new-name']);

        $this->get('/old-name')->assertNotFound();

        PageRedirects::keep($page, array_column($changes, 'from'));

        $this->get('/old-name')->assertRedirect('/new-name')->assertStatus(301);
        $this->get('/new-name')->assertOk();
    }

    public function test_reserved_slugs_are_never_used(): void
    {
        $page = Page::factory()->create(['title' => 'Admin']);

        $this->assertSame('admin-page', $page->slug);
    }

    public function test_a_taken_address_gets_the_next_free_number(): void
    {
        Page::factory()->create(['title' => 'Home']);
        $second = Page::factory()->create(['title' => 'Home']);

        $this->assertSame('home-1', $second->slug);

        $admin = $this->userWithRole(User::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(CreatePage::class)
            ->fillForm(['title' => 'Home'])
            ->assertSchemaStateSet(['slug' => 'home-2'], 'form');

        // Generating it again on a page keeps its own address.
        Livewire::actingAs($admin)
            ->test(EditPage::class, ['record' => $second->getRouteKey()])
            ->fillForm(['slug' => 'something-else'])
            ->callAction(TestAction::make('regenerateSlug')->schemaComponent('slug', schema: 'form'))
            ->assertSchemaStateSet(['slug' => 'home-1'], 'form');
    }

    public function test_changing_a_live_pages_address_asks_to_redirect_the_old_one(): void
    {
        $page = Page::factory()->published()->create(['title' => 'About us']);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'about-the-team'])
            ->call('save')
            ->assertActionMounted('keepOldAddresses')
            ->assertActionDataSet(['redirect' => ['en']])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame('about-the-team', $page->fresh()->slug);
        $this->get('/about-us')->assertRedirect('/about-the-team')->assertStatus(301);
    }

    public function test_the_sitemap_lists_live_indexable_pages(): void
    {
        Page::factory()->published()->create(['slug' => 'visible']);
        Page::factory()->published()->create(['slug' => 'hidden', 'noindex' => true]);
        Page::factory()->create(['slug' => 'draft']);

        $this->get('/sitemap.xml')->assertOk()->assertSee('/visible')->assertDontSee('/hidden')->assertDontSee('/draft');
    }

    public function test_an_address_that_tidies_into_a_taken_one_gets_a_number(): void
    {
        Page::factory()->create(['slug' => 'about-us']);
        $page = Page::factory()->create(['slug' => 'about_us']);

        $this->assertSame('about-us-1', $page->slug);
    }
}
