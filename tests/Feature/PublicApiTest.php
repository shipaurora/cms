<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\Api;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Livewire\ApiKeysTable;
use Aurora\Models\ApiKey;
use Aurora\Models\Form;
use Aurora\Models\Menu;
use Aurora\Models\MenuLocation;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\PublicApi;
use Aurora\Support\SettingsTabs;
use Aurora\Support\WebsiteAccess;
use Aurora\Tests\TestCase;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use GraphQL\Type\Introspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function switchOn(Api $api, bool $requiresKey = false): void
    {
        Setting::put([$api->setting() => ['enabled' => true, 'requires_key' => $requiresKey]]);
    }

    /**
     * The homepage, "About us" (with a heading block) and pages visitors may not see.
     */
    protected function pages(): Page
    {
        Page::factory()->homepage()->create(['title' => 'Home']);
        Page::factory()->create(['title' => 'Draft']);
        Page::factory()->published()->create(['title' => 'Members', 'members_only' => true]);
        Page::factory()->published()->create(['title' => 'Secret', 'password' => 'open sesame']);

        return Page::factory()->published()->create(['title' => 'About us', 'slug' => 'about-us', 'blocks' => BlockTreeBuilder::flatten([
            ['type' => 'heading', 'data' => ['text' => 'Small team', 'level' => 'h2']],
        ])]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, string>  $headers
     */
    protected function graphql(string $query, array $variables = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/aurora/graphql', ['query' => $query, 'variables' => $variables], $headers);
    }

    public function test_each_api_is_off_until_switched_on(): void
    {
        $this->getJson('/api/aurora/v1/pages')->assertNotFound();
        $this->graphql('{ settings }')->assertNotFound();

        // One on doesn't switch the other on.
        $this->switchOn(Api::Rest);
        $this->getJson('/api/aurora/v1/pages')->assertOk();
        $this->graphql('{ settings }')->assertNotFound();
    }

    public function test_rest_lists_and_shows_the_pages_visitors_may_see(): void
    {
        $this->switchOn(Api::Rest);
        $about = $this->pages();

        $this->getJson('/api/aurora/v1/pages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['title' => 'About us', 'path' => '/about-us'])
            ->assertJsonMissing(['title' => 'Draft'])
            ->assertJsonMissing(['title' => 'Members'])
            ->assertJsonMissing(['title' => 'Secret']);

        $this->getJson('/api/aurora/v1/pages/'.$about->id)
            ->assertOk()
            ->assertJsonPath('data.title', 'About us')
            ->assertJsonPath('data.layout', 'standard')
            ->assertJsonPath('data.blocks.0.type', 'heading')
            ->assertJsonPath('data.blocks.0.data.text', 'Small team');

        $this->getJson('/api/aurora/v1/page?path=/about-us')->assertOk()->assertJsonPath('data.id', $about->id);
        $this->getJson('/api/aurora/v1/page?path=/')->assertOk()->assertJsonPath('data.title', 'Home');
        $this->getJson('/api/aurora/v1/page?path=/nowhere')->assertNotFound();
    }

    public function test_rest_menus_and_public_settings(): void
    {
        $this->switchOn(Api::Rest);
        Setting::put(['site_name' => 'Nordlys', 'copilot' => ['provider' => 'anthropic']]);
        $menu = Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
        MenuLocation::query()->create(['menu_id' => $menu->id, 'location' => 'header']);
        $menu->menuItems()->create(['title' => 'Docs', 'url' => '/docs', 'order' => 1]);

        $this->getJson('/api/aurora/v1/menus/header')->assertOk()->assertJsonPath('data.items.0.title', 'Docs');
        $this->getJson('/api/aurora/v1/menus/nowhere')->assertNotFound();

        $this->getJson('/api/aurora/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'Nordlys')
            ->assertJsonMissingPath('data.copilot');
    }

    public function test_a_page_comes_with_what_its_blocks_point_at(): void
    {
        Storage::fake('public');
        $this->switchOn(Api::Rest);
        $image = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 200, 100));
        $image->update(['alt' => 'A fjord']);
        $about = Page::factory()->published()->create(['title' => 'About', 'slug' => 'about']);
        $draft = Page::factory()->create(['title' => 'Draft', 'slug' => 'draft']);
        $form = Form::factory()->create(['notify' => 'boss@example.com']);
        $page = Page::factory()->published()->create(['slug' => 'welcome', 'blocks' => BlockTreeBuilder::flatten([
            ['type' => 'hero', 'data' => ['headline' => 'Hi', 'primary_type' => 'page', 'primary_page' => $about->id, 'secondary_type' => 'page', 'secondary_page' => $draft->id]],
            ['type' => 'image', 'data' => ['src' => $image->id]],
            ['type' => 'rich-text', 'data' => ['body' => '<p>Hello <script>alert(1)</script><strong>world</strong></p>']],
            ['type' => 'form', 'data' => ['form_id' => $form->id]],
        ])]);

        $response = $this->getJson('/api/aurora/v1/pages/'.$page->id)->assertOk();
        $blocks = $response->json('data.blocks');

        // Links: the page, if anyone may see it.
        $this->assertSame('/about', $blocks[0]['data']['primary_page']['path']);
        $this->assertNull($blocks[0]['data']['secondary_page']);

        // Images: the file, its size and alt text.
        $this->assertSame(['A fjord', 200, 100], [$blocks[1]['data']['src']['alt'], $blocks[1]['data']['src']['width'], $blocks[1]['data']['src']['height']]);

        // Rich text: the website's cleaned HTML.
        $this->assertStringContainsString('<strong>world</strong>', $blocks[2]['data']['body']);
        $this->assertStringNotContainsString('<script', $blocks[2]['data']['body']);

        // Forms: their fields, never who's told about answers.
        $this->assertNotEmpty($blocks[3]['data']['form_id']['fields']);
        $this->assertStringNotContainsString('boss@example.com', $response->getContent());
    }

    public function test_media_shows_only_the_files_visible_content_uses(): void
    {
        Storage::fake('public');
        $this->switchOn(Api::Rest);
        $this->switchOn(Api::Graphql);
        [$used, $onDraft, $onMembersPage, $unused] = array_map(fn (string $name) => AssetLibrary::fromUpload(UploadedFile::fake()->image($name.'.jpg', 40, 40)), ['used', 'draft', 'members', 'unused']);
        $image = fn ($asset): array => BlockTreeBuilder::flatten([['type' => 'image', 'data' => ['src' => $asset->id]]]);
        Page::factory()->published()->create(['blocks' => $image($used)]);
        Page::factory()->create(['blocks' => $image($onDraft)]);
        Page::factory()->published()->create(['blocks' => $image($onMembersPage), 'members_only' => true]);

        $this->getJson('/api/aurora/v1/media')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $used->id);
        $this->getJson('/api/aurora/v1/media?type=pdf')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/aurora/v1/media/'.$used->id)->assertOk()->assertJsonPath('data.width', 40)->assertJsonMissingPath('data.folder_id')->assertJsonMissingPath('data.uploaded_by_id');

        foreach ([$onDraft, $onMembersPage, $unused] as $hidden) {
            $this->getJson('/api/aurora/v1/media/'.$hidden->id)->assertNotFound();
        }

        $this->graphql('query ($id: ID!) { mediaList { total } media(id: $id) { id } }', ['id' => $unused->id])
            ->assertJsonPath('data.mediaList.total', 1)
            ->assertJsonPath('data.media', null);
    }

    public function test_settings_show_only_what_a_tab_makes_public(): void
    {
        $this->switchOn(Api::Rest);
        $about = Page::factory()->published()->create(['slug' => 'about']);
        SettingsTabs::register('acme', fn (): Tab => Tab::make('Acme')->schema([
            TextInput::make('acme_label'),
            Select::make('acme_page'),
            TextInput::make('acme_token'),
        ]), siteKeys: ['acme_label', 'acme_page', 'acme_token'], publicKeys: ['acme_label', 'acme_page']);

        try {
            Setting::put([
                'site_name' => 'Nordlys',
                'acme_label' => 'Book a call',
                'acme_page' => $about->id,
                'acme_token' => 'secret-token',
                WebsiteAccess::SETTING => ['ips' => ['10.0.0.1']],
                'copilot' => ['provider' => 'anthropic'],
            ]);
            ApiKey::issue('App');

            $response = $this->getJson('/api/aurora/v1/settings')
                ->assertOk()
                ->assertJsonPath('data.site_name', 'Nordlys')
                ->assertJsonPath('data.acme_label', 'Book a call')
                ->assertJsonPath('data.acme_page.path', '/about')
                ->assertJsonMissingPath('data.acme_token')
                ->assertJsonMissingPath('data.'.WebsiteAccess::SETTING)
                ->assertJsonMissingPath('data.copilot')
                ->assertJsonMissingPath('data.rest_api');

            $this->assertStringNotContainsString('secret-token', $response->getContent());
            $this->assertStringNotContainsString('10.0.0.1', $response->getContent());
        } finally {
            SettingsTabs::forget('acme');
        }
    }

    public function test_graphql_answers_with_just_the_fields_asked_for(): void
    {
        $this->switchOn(Api::Graphql);
        $about = $this->pages();
        Setting::put(['site_name' => 'Nordlys', 'copilot' => ['provider' => 'anthropic']]);
        $menu = Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
        MenuLocation::query()->create(['menu_id' => $menu->id, 'location' => 'header']);
        $menu->menuItems()->create(['title' => 'Docs', 'url' => '/docs', 'order' => 1]);

        $this->graphql('{ pages { total items { title path } } }')
            ->assertOk()
            ->assertJsonPath('data.pages.total', 2)
            ->assertJsonFragment(['title' => 'About us', 'path' => '/about-us'])
            ->assertJsonMissing(['title' => 'Draft'])
            ->assertJsonMissing(['title' => 'Members'])
            ->assertJsonMissing(['title' => 'Secret']);

        $this->graphql('query ($id: ID) { page(id: $id) { title layout seo { title } blocks } }', ['id' => $about->id])
            ->assertOk()
            ->assertJsonPath('data.page.title', 'About us')
            ->assertJsonPath('data.page.layout', 'standard')
            ->assertJsonPath('data.page.seo.title', 'About us')
            ->assertJsonPath('data.page.blocks.0.data.text', 'Small team');

        $this->graphql('{ home: page(path: "/") { title } nowhere: page(path: "/nowhere") { title } }')
            ->assertJsonPath('data.home.title', 'Home')
            ->assertJsonPath('data.nowhere', null);

        $this->graphql('{ menu(location: "header") { items { title url } } settings }')
            ->assertJsonPath('data.menu.items.0.title', 'Docs')
            ->assertJsonPath('data.settings.site_name', 'Nordlys')
            ->assertJsonMissingPath('data.settings.copilot');

        // Variables that aren't an object count as none, not as an error.
        $this->postJson('/api/aurora/graphql', ['query' => '{ settings }', 'variables' => '5'])->assertOk()->assertJsonPath('data.settings.site_name', 'Nordlys');

        // Read-only, and deep nesting is turned away.
        $this->graphql('mutation { deletePage }')->assertJsonPath('data', null);
        $nested = '{ menu(location: "header") { items { '.str_repeat('children { ', 10).'title'.str_repeat(' }', 10).' } } }';
        $this->assertStringStartsWith('Max query depth should be 10', $this->graphql($nested)->json('errors.0.message'));
    }

    public function test_tools_read_the_whole_schema_but_content_stays_as_shallow_as_before(): void
    {
        $this->switchOn(Api::Graphql);

        // The introspection GraphiQL and other tools send: deeper than content may go.
        $schema = $this->graphql(Introspection::getIntrospectionQuery())->assertOk();
        $this->assertNull($schema->json('errors'));
        $this->assertSame('Query', $schema->json('data.__schema.queryType.name'));

        // Content beside it doesn't get the schema's depth.
        $mixed = '{ __typename menu(location: "header") { items { '.str_repeat('children { ', 10).'title'.str_repeat(' }', 10).' } } }';
        $this->assertStringStartsWith('Max query depth should be 10', $this->graphql($mixed)->json('errors.0.message'));
    }

    public function test_with_a_key_needed_any_api_key_opens_both_apis_until_it_is_revoked(): void
    {
        $this->switchOn(Api::Rest, requiresKey: true);
        $this->switchOn(Api::Graphql, requiresKey: true);
        [$frontend, $key] = ApiKey::issue('Website frontend');
        [, $other] = ApiKey::issue('Mobile app');

        $this->getJson('/api/aurora/v1/pages')->assertUnauthorized();
        $this->getJson('/api/aurora/v1/pages', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->getJson('/api/aurora/v1/pages', ['Authorization' => 'Bearer '.$key])->assertOk();
        $this->getJson('/api/aurora/v1/pages', ['Authorization' => 'Bearer '.$other])->assertOk();

        // Never in the address, where it would end up in logs.
        $this->getJson('/api/aurora/v1/pages?key='.$other)->assertUnauthorized();

        $this->graphql('{ settings }')->assertUnauthorized()->assertJsonPath('errors.0.message', 'A valid API key is needed: Authorization: Bearer <key>.');
        $this->graphql('{ settings }', headers: ['Authorization' => 'Bearer '.$key])->assertOk();
        $this->assertNotNull($frontend->refresh()->last_used_at);

        // Revoked: that key stops working, the other goes on.
        $frontend->delete();
        $this->getJson('/api/aurora/v1/pages', ['Authorization' => 'Bearer '.$key])->assertUnauthorized();
        $this->getJson('/api/aurora/v1/pages', ['Authorization' => 'Bearer '.$other])->assertOk();
    }

    public function test_search_finds_what_the_website_s_search_finds(): void
    {
        $this->switchOn(Api::Rest);
        $this->switchOn(Api::Graphql);
        Page::factory()->homepage()->create();
        $fjords = Page::factory()->published()->create(['title' => 'Fjord tours', 'slug' => 'fjord-tours', 'excerpt' => 'Boat trips along the Norwegian fjords, all summer.']);
        Page::factory()->create(['title' => 'Fjord draft', 'slug' => 'fjord-draft']);
        Page::factory()->published()->create(['title' => 'Mountains', 'slug' => 'mountains']);

        $this->getJson('/api/aurora/v1/search?q=fjord')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fjords->id)
            ->assertJsonPath('data.0.path', '/fjord-tours');
        $this->assertStringContainsString('fjords', $this->getJson('/api/aurora/v1/search?q=fjord')->json('data.0.snippet'));
        $this->getJson('/api/aurora/v1/search')->assertUnprocessable();

        $this->graphql('{ search(query: "fjord") { total items { snippet page { title path } } } }')
            ->assertJsonPath('data.search.total', 1)
            ->assertJsonPath('data.search.items.0.page.title', 'Fjord tours');
        $this->graphql('{ search(query: "f") { total } }')->assertJsonPath('errors.0.message', 'Search for at least 2 characters.');
    }

    public function test_a_key_only_does_what_its_scopes_allow(): void
    {
        $this->switchOn(Api::Rest, requiresKey: true);
        $this->switchOn(Api::Graphql, requiresKey: true);
        $about = $this->pages();
        [, $key] = ApiKey::issue('Page list only', scopes: ['pages.list']);
        $headers = ['Authorization' => 'Bearer '.$key];

        $this->getJson('/api/aurora/v1/pages', $headers)->assertOk();
        $this->getJson('/api/aurora/v1/pages/'.$about->id, $headers)->assertForbidden()->assertJsonPath('message', 'This API key can\'t do this: it needs the "pages.read" scope.');
        $this->getJson('/api/aurora/v1/search?q=about', $headers)->assertForbidden();
        $this->getJson('/api/aurora/v1/settings', $headers)->assertForbidden();

        $this->graphql('{ pages { total } }', headers: $headers)->assertJsonPath('data.pages.total', 2);
        $this->graphql('{ settings }', headers: $headers)->assertJsonPath('errors.0.message', 'This API key can\'t do this: it needs the "settings.read" scope.');
    }

    public function test_keys_are_named_shown_once_and_revoked_in_settings(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $this->actingAs($admin);

        $keys = Livewire::test(ApiKeysTable::class)
            ->callTableAction('createApiKey', data: ['name' => 'Website frontend', 'scopes' => ['pages.list', 'search']])
            ->assertActionMounted('showApiKey');

        $key = ApiKey::query()->sole();
        $shown = $keys->instance()->getMountedAction()->getArguments()['key'];
        $this->assertSame('Website frontend', $key->name);
        $this->assertSame(['pages.list', 'search'], $key->scopes);

        // What it may do changes later; the key stays the same.
        Livewire::test(ApiKeysTable::class)->callTableAction('edit', $key, data: ['name' => 'Website frontend', 'scopes' => ['pages.list', 'pages.read']]);
        $this->assertSame(['pages.list', 'pages.read'], $key->fresh()->scopes);
        $this->assertTrue($key->creator->is($admin));
        $this->assertSame(ApiKey::hash($shown), $key->key_hash);

        Livewire::test(ApiKeysTable::class)->callTableAction('delete', $key);
        $this->assertSame(0, ApiKey::query()->count());

        // Each API's tab opens the same keys in a dialog.
        foreach (['rest-api', 'graphql'] as $section) {
            Livewire::withQueryParams(['tab' => 'integrations', 'section' => $section])
                ->test(SiteSettings::class)
                ->assertSee('Manage keys');
        }

        // And they're kept under Security.
        Livewire::withQueryParams(['tab' => 'security', 'section' => 'api-keys'])
            ->test(SiteSettings::class)
            ->assertSeeLivewire(ApiKeysTable::class);

        // Only for people who manage settings.
        Livewire::actingAs($this->userWithRole('Editor'))->test(ApiKeysTable::class)->assertForbidden();
    }

    public function test_each_api_is_switched_on_in_its_own_settings_tab(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'integrations', 'section' => 'graphql'])
            ->test(SiteSettings::class)
            ->assertSee('GraphQL API')
            ->fillForm([Api::Graphql->setting() => ['enabled' => true, 'requires_key' => false]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(PublicApi::enabled(Api::Graphql));
        $this->assertFalse(PublicApi::enabled(Api::Rest));
    }
}
