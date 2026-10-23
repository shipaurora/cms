<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Publishing;
use Aurora\Models\Asset;
use Aurora\Models\Concerns\HasPublishing;
use Aurora\Models\Layout;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Support\AdminPanel;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Aurora\Support\Layouts;
use Aurora\Support\Menus;
use Aurora\Support\Sitemap;
use Aurora\Tests\TestCase;
use Filament\Navigation\NavigationGroup;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What a website built on Aurora uses from its own code: its admin screens, publishing for
 * its models, its menus' links, its sitemap entries and importing its content.
 */
class WebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sitemap::forget();
        Menus::forgetLocalized();

        parent::tearDown();
    }

    public function test_the_website_s_resources_aren_t_per_site_and_its_groups_go_among_aurora_s(): void
    {
        AdminPanel::resources([EventResource::class]);
        $this->assertFalse(EventResource::isScopedToTenant());
        $this->assertContains(EventResource::class, AdminPanel::registeredResources());

        AdminPanel::navigationGroup('Events');
        AdminPanel::navigationGroup('Albums', after: 'Events');
        AdminPanel::navigationGroup('Shop', after: 'AI');

        $groups = AdminPanel::navigationGroups([
            'Content' => NavigationGroup::make(),
            'AI' => NavigationGroup::make(),
            'Administration' => NavigationGroup::make(),
        ]);

        $this->assertSame(['Content', 'Events', 'Albums', 'AI', 'Shop', 'Administration'], array_keys($groups));
    }

    public function test_a_website_s_model_is_published_like_a_page(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            Event::publishingColumns($table);
            $table->timestamps();
        });

        $live = Event::query()->create(['name' => 'Live', 'status' => PageStatus::Published]);
        $scheduled = Event::query()->create(['name' => 'Soon', 'status' => PageStatus::Published, 'published_at' => now()->addDay()]);
        $ended = Event::query()->create(['name' => 'Over', 'status' => PageStatus::Published, 'unpublish_at' => now()->subHour()]);
        Event::query()->create(['name' => 'Draft']);

        $this->assertSame(['Live'], Event::query()->live()->pluck('name')->all());
        $this->assertSame(['Over'], Event::query()->ended()->pluck('name')->all());
        $this->assertTrue($scheduled->isScheduled());
        $this->assertTrue($ended->hasEnded());
        $this->assertTrue($live->isLive());

        // The admin's pieces, as the pages have them.
        $this->assertSame('status', Publishing::column()->getName());
        $this->assertSame('Publishing', Publishing::section()->getHeading());
        $this->assertSame('status', Publishing::filter()->getName());
    }

    public function test_menu_links_to_the_website_s_own_sections_follow_the_language(): void
    {
        $this->withEnglishAndNorwegian();
        Menus::localized(['/events']);

        $menu = Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
        $events = MenuItem::query()->create(['menu_id' => $menu->id, 'title' => 'Events', 'url' => '/events/2026']);
        $files = MenuItem::query()->create(['menu_id' => $menu->id, 'title' => 'Sitemap', 'url' => '/sitemap.xml']);

        $this->assertSame('/events/2026', $events->fresh()->url);

        ContentLocale::set('nb');
        $this->assertSame('/nb/events/2026', $events->fresh()->url);
        $this->assertSame('/sitemap.xml', $files->fresh()->url);
    }

    public function test_the_sitemap_lists_the_website_s_own_addresses_too(): void
    {
        Page::factory()->homepage()->published()->create();
        Sitemap::add(fn (): array => ['/events', ['url' => '/events/2026-spring', 'updated_at' => now()->setDate(2026, 3, 1), 'priority' => 0.6]]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>'.url('/events').'</loc>', false)
            ->assertSee('<loc>'.url('/events/2026-spring').'</loc>', false)
            ->assertSee('<priority>0.6</priority>', false);
    }

    public function test_an_import_runs_again_without_copies_and_writes_layout_fields_by_handle(): void
    {
        Storage::fake('public');
        $id = (string) Str::uuid7();
        $file = tempnam(sys_get_temp_dir(), 'img').'.png';
        imagepng(imagecreatetruecolor(20, 10), $file);

        $first = AssetLibrary::fromFile($file, ['id' => $id, 'title' => 'Poster']);
        $again = AssetLibrary::fromFile($file, ['id' => $id, 'title' => 'Poster']);

        $this->assertSame($id, $first->id);
        $this->assertTrue($again->is($first));
        $this->assertSame(1, Asset::query()->count());

        // A layout's fields, by its handle; and a plain save from code goes straight on a live page.
        Layouts::schema('aurora::layouts.landing', fn (): array => []);
        $page = Page::factory()->published()->create(['title' => 'Before']);
        $page->update(['title' => 'After', 'settings' => $page->settingsWithLayoutValues(['poster' => $id], 'landing')]);
        Layouts::forgetSchema('aurora::layouts.landing');

        $this->assertSame(['layouts' => [Layouts::settingsKey(Layout::cached()->firstWhere('handle', 'landing')->view) => ['poster' => $id]]], $page->fresh()->settings);
        $this->assertSame('After', $page->fresh()->title);
        $this->assertFalse($page->fresh()->hasWorkingCopy());
    }
}

class Event extends Model
{
    use HasPublishing;

    protected $guarded = [];
}

class EventResource extends Resource
{
    protected static ?string $model = Event::class;
}
