<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\RelationManagers\UsagesRelationManager;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Widgets\WelcomeWidget;
use Aurora\Livewire\SitesTable;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\SettingsTabs;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class MultisiteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SettingsTabs::forget('opening-hours');

        parent::tearDown();
    }

    protected Site $main;

    protected Site $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withEnglishAndNorwegian();
        $this->main = Site::default();
        $this->shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop', 'domain' => 'shop.example.com', 'default_locale' => 'nb', 'settings' => ['site_name' => 'Fjordbutikken']]);
    }

    protected function homepage(Site $site, string $title): Page
    {
        return CurrentSite::using($site, fn () => Page::factory()->homepage()->create(['title' => $title, 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'data' => ['text' => $title.' heading', 'level' => 'h1']],
        ]]));
    }

    public function test_each_domain_shows_its_own_site_and_the_default_answers_everywhere_else(): void
    {
        $this->homepage($this->main, 'Main home');
        $this->homepage($this->shop, 'Butikk');

        $this->get('http://shop.example.com/')->assertOk()->assertSee('Butikk heading')->assertSee('<html lang="nb"', false)->assertSee('Fjordbutikken');
        $this->get('http://anything-else.test/')->assertOk()->assertSee('Main home heading')->assertDontSee('Butikk');
    }

    public function test_sites_keep_their_own_pages_addresses_and_settings(): void
    {
        $about = Page::factory()->published()->create(['title' => 'About']);
        $shopAbout = CurrentSite::using($this->shop, fn () => Page::factory()->published()->create(['title' => 'About']));

        // The same address on two sites: each has its own.
        $this->assertSame('about', $about->slug);
        $this->assertSame('about', $shopAbout->slug);
        $this->assertSame([$about->id], Page::query()->pluck('id')->all());

        CurrentSite::using($this->shop, fn () => Setting::put(['site_name' => 'Butikken', 'copilot' => ['instructions' => 'Be brief.']]));

        $this->assertSame('Main site', Setting::get('site_name'));
        $this->assertSame('Butikken', CurrentSite::using($this->shop->fresh(), fn () => Setting::get('site_name')));
        $this->assertSame('Be brief.', Setting::get('copilot.instructions'), 'The AI assistant settings are the CMS’s.');
        $this->assertSame('nb', CurrentSite::using($this->shop, fn () => ContentLocale::default()));
    }

    public function test_people_only_work_on_the_sites_they_were_given(): void
    {
        $editor = $this->userWithRole('Editor');
        $editor->sites()->attach($this->shop);

        $this->actingAs($editor)->get('/admin/shop/pages')->assertOk();
        $this->actingAs($editor)->get('/admin/main/pages')->assertNotFound();

        $admin = $this->userWithRole(User::SUPER_ADMIN);

        $this->assertSame(['main', 'shop'], $admin->getTenants(Filament::getPanel('admin'))->pluck('handle')->all());
        $this->assertSame(['main'], $this->userWithRole('Author')->getTenants(Filament::getPanel('admin'))->pluck('handle')->all(), 'Given no site: the default one.');
    }

    public function test_pages_in_the_admin_are_the_picked_sites(): void
    {
        $this->homepage($this->main, 'Main home');
        $this->homepage($this->shop, 'Butikk');

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->get('/admin/shop/pages')
            ->assertOk()
            ->assertSee('Butikk')
            ->assertDontSee('Main home');
    }

    public function test_sites_are_added_under_settings(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::test(SitesTable::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Blog', 'handle' => 'blog', 'domain' => 'https://Blog.Example.com/', 'locales' => ['en']])
            ->assertHasNoActionErrors()
            ->assertDispatched('refresh-sidebar');

        $blog = Site::query()->where('handle', 'blog')->sole();

        $this->assertSame('blog.example.com', $blog->domain);
        $this->assertFalse($blog->is_default);

        Livewire::actingAs($this->userWithRole('Editor'))->test(SitesTable::class)->assertForbidden();
    }

    public function test_addresses_are_the_sites_domain_or_app_url_never_the_admins_host(): void
    {
        config(['app.url' => 'https://cms.example.com']);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        // Opened on another address, the admin still shows each site's own.
        $this->get('https://admin.elsewhere.test/admin/main');

        $this->assertSame('https://shop.example.com/kontakt', $this->shop->url('/kontakt'));
        $this->assertSame('https://cms.example.com/about', $this->main->url('/about'));
        $this->assertSame('https://cms.example.com', $this->main->url('/'));
        $this->assertSame('https://shop.example.com/admin', Site::adminUrl('shop.example.com'));

        // The admin address in the site dialog follows the domain being typed.
        $sites = Livewire::test(SitesTable::class)->mountAction(TestAction::make('create')->table());
        $prefix = fn (): string => (string) $sites->instance()->getSchema($sites->instance()->getMountedActionSchemaName())->getComponent('handle')->getPrefixLabel();

        $this->assertSame('cms.example.com/admin/', $prefix());

        $sites->set('mountedActions.0.data.domain', 'blog.example.com');

        $this->assertSame('blog.example.com/admin/', $prefix());
    }

    public function test_a_page_previews_as_its_own_site(): void
    {
        $page = CurrentSite::using($this->shop, fn () => Page::factory()->create(['title' => 'Sommerjobb', 'status' => PageStatus::Draft]));

        $this->get($page->previewUrl())->assertOk()->assertSee('Fjordbutikken');

        // Another language stays in the preview: the site may have no address of its own.
        $this->assertMatchesRegularExpression('#/preview/'.$page->id.'\?[^"]*locale=en#', $page->previewUrl(locale: 'en'));
        $this->get($page->previewUrl(locale: 'en'))->assertOk()->assertSee('<html lang="en">', false);
    }

    public function test_the_site_switcher_only_shows_with_more_than_one_site_to_pick(): void
    {
        $editor = $this->userWithRole('Editor');

        $this->actingAs($editor)->get('/admin/main/pages')->assertOk()->assertDontSee('fi-tenant-menu');

        $editor->sites()->attach([$this->main->id, $this->shop->id]);

        $this->actingAs($editor->fresh())->get('/admin/main/pages')->assertOk()->assertSee('fi-tenant-menu');
    }

    public function test_site_settings_are_edited_per_site(): void
    {
        Setting::put(['tagline' => 'Main tagline']);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setTenant($this->shop);
        CurrentSite::set($this->shop);

        Livewire::test(SiteSettings::class)
            ->assertSchemaStateSet(['site_name' => 'Fjordbutikken', 'tagline' => null], 'form')
            ->fillForm(['tagline' => 'Butikkens slagord', 'brand_color' => '#ff0000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Butikkens slagord', $this->shop->fresh()->setting('tagline'));
        $this->assertSame('Main tagline', CurrentSite::using($this->main->fresh(), fn () => Setting::get('tagline')));
        $this->assertNull($this->main->fresh()->setting('brand_color'));
    }

    public function test_a_website_adds_its_own_settings_tabs_saved_per_site(): void
    {
        SettingsTabs::register('opening-hours', fn (): Tab => Tab::make('Opening hours')->schema([
            TextInput::make('opening_hours'),
        ]), siteKeys: ['opening_hours']);

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setTenant($this->shop);
        CurrentSite::set($this->shop);

        // The block library's (its footer), and one added here: each a section of its own.
        Livewire::test(SiteSettings::class)
            ->assertSee('Opening hours');

        Livewire::withQueryParams(['tab' => 'website'])
            ->test(SiteSettings::class)
            ->fillForm(['footer_note' => 'Butikkens fotnote'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::withQueryParams(['tab' => 'opening-hours'])
            ->test(SiteSettings::class)
            ->fillForm(['opening_hours' => '10–18'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Butikkens fotnote', $this->shop->fresh()->setting('footer_note'));
        $this->assertSame('10–18', $this->shop->fresh()->setting('opening_hours'));
        $this->assertNull($this->main->fresh()->setting('opening_hours'));
    }

    public function test_media_shows_where_it_is_used_on_every_site(): void
    {
        Storage::fake('public');
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 200, 100));
        $page = CurrentSite::using($this->shop, fn () => Page::factory()->create(['title' => 'Butikkside', 'og_image' => $asset->id]));

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setTenant($this->main);

        Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => EditAsset::class])
            ->assertSee('Butikkside')
            // On the page's own site's domain.
            ->assertSee('Shop · '.str($this->shop->url('/butikkside'))->after('://'))
            ->assertSee('/admin/shop/pages/'.$page->id.'/edit', false);
    }

    public function test_links_into_the_admin_from_outside_it_name_the_pages_site(): void
    {
        $page = $this->homepage($this->shop, 'Butikk');

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->get('http://shop.example.com/')
            ->assertOk()
            ->assertSee('Butikk');

        $this->assertStringContainsString('/admin/shop/pages/'.$page->id.'/edit', PageResource::settingsUrl($page));
        $this->assertStringContainsString('/admin/shop/', PageResource::editorUrl($page));
    }

    public function test_forms_belong_to_their_site(): void
    {
        $shopForm = CurrentSite::using($this->shop, fn () => Form::factory()->create(['name' => 'Bestilling']));

        $this->assertSame([], Form::query()->pluck('id')->all(), 'Not on the main site.');
        $this->assertSame($this->shop->id, $shopForm->site_id);

        $answers = ['name' => 'Kari', 'email' => 'kari@example.com', 'message' => 'Hei', 'consent' => '1', '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp)];

        $this->post('http://anything-else.test/forms/'.$shopForm->id, $answers)->assertNotFound();
        $this->post('http://shop.example.com/forms/'.$shopForm->id, $answers)->assertRedirect();
        $this->assertSame(1, $shopForm->submissions()->count());
    }

    public function test_the_welcome_names_the_site_when_there_is_more_than_one(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setTenant($this->shop);

        Livewire::test(WelcomeWidget::class)->assertSeeHtml('You are signed in to <strong class="text-white">Shop</strong> as');

        $this->shop->delete();
        Filament::setTenant($this->main);

        Livewire::test(WelcomeWidget::class)->assertSee('You are signed in as')->assertDontSee('signed in to');
    }

    public function test_the_site_being_worked_on_cannot_be_deleted(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setTenant($this->shop);

        Livewire::test(SitesTable::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->shop))
            ->assertActionHidden(TestAction::make('delete')->table($this->main));
    }

    public function test_a_site_s_own_avatar_is_its_icon_in_the_site_switcher(): void
    {
        Storage::fake('public');
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        // Without one: its initial, drawn.
        $this->assertStringStartsWith('data:image/svg+xml', $this->shop->getFilamentAvatarUrl());

        Livewire::test(SitesTable::class)
            ->callAction(TestAction::make('edit')->table($this->shop), ['avatar' => UploadedFile::fake()->image('shop.png', 128, 128)])
            ->assertHasNoActionErrors();

        $avatar = $this->shop->fresh()->avatar;

        $this->assertStringStartsWith('avatars/', $avatar);
        Storage::disk('public')->assertExists($avatar);
        $this->assertSame(Storage::disk('public')->url($avatar), $this->shop->fresh()->getFilamentAvatarUrl());
    }

    public function test_renaming_the_site_being_worked_on_goes_on_to_its_new_address(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setTenant($this->shop);
        CurrentSite::set($this->shop);

        Livewire::test(SitesTable::class)
            ->callAction(TestAction::make('edit')->table($this->shop), ['handle' => 'butikken'])
            ->assertHasNoActionErrors()
            ->assertRedirect(SiteSettings::getUrl(['tab' => 'languages-sites', 'section' => 'sites'], tenant: $this->shop->fresh()));

        $this->assertStringContainsString('/butikken/', SiteSettings::getUrl(['tab' => 'languages-sites', 'section' => 'sites'], tenant: $this->shop->fresh()));

        // Another site: nowhere to go, the list just shows it.
        Livewire::test(SitesTable::class)
            ->callAction(TestAction::make('edit')->table($this->main), ['name' => 'Hovedsiden'])
            ->assertNoRedirect()
            ->assertDispatched('refresh-sidebar');
    }
}
