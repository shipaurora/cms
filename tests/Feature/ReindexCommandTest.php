<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Support\CurrentSite;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReindexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reindexes_every_site_s_pages_from_their_content(): void
    {
        $about = Page::factory()->published()->create(['title' => 'About the fjord']);
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        $products = CurrentSite::using($shop, fn (): Page => Page::factory()->published()->create(['title' => 'Coffee beans']));

        // Changed outside the admin: the index has old text.
        Page::acrossSites()->toBase()->update(['search_text' => 'stale', 'indexed_at' => null]);

        $this->artisan('aurora:reindex')
            ->expectsOutputToContain('2 pages reindexed, 2 of them searchable')
            ->assertSuccessful();

        $this->assertStringContainsString('About the fjord', Page::acrossSites()->find($about->getKey())->search_text);
        $this->assertStringContainsString('Coffee beans', Page::acrossSites()->find($products->getKey())->search_text);
    }

    public function test_it_reindexes_one_site_and_says_which_sites_there_are(): void
    {
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        $about = Page::factory()->published()->create(['title' => 'About the fjord']);
        $products = CurrentSite::using($shop, fn (): Page => Page::factory()->published()->create(['title' => 'Coffee beans']));
        Page::acrossSites()->toBase()->update(['search_text' => 'stale']);

        $this->artisan('aurora:reindex', ['--site' => 'shop'])->assertSuccessful();

        $this->assertStringContainsString('Coffee beans', Page::acrossSites()->find($products->getKey())->search_text);
        $this->assertSame('stale', Page::acrossSites()->find($about->getKey())->search_text);

        $this->artisan('aurora:reindex', ['--site' => 'nope'])
            ->expectsOutputToContain('There is no site [nope]')
            ->assertFailed();
    }
}
