<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\Api;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\OpenApiSpec;
use Aurora\Support\PublicApi;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Livewire;

class ApiExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_people_who_may_change_the_settings_open_the_explorers(): void
    {
        foreach (['aurora.explorer.rest', 'aurora.explorer.openapi', 'aurora.explorer.graphql'] as $route) {
            $this->get(route($route))->assertRedirect();
            $this->actingAs($this->userWithRole('Editor'))->get(route($route))->assertForbidden();
            $this->actingAs($this->userWithRole(User::SUPER_ADMIN))->get(route($route))->assertOk();
            auth()->logout();
        }
    }

    public function test_the_openapi_document_has_every_endpoint_of_the_rest_api_as_its_routes_say(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Setting::put([Api::Rest->setting() => ['enabled' => true, 'requires_key' => true]]);

        $document = $this->getJson(route('aurora.explorer.openapi'))->assertOk()->json();

        // Every route of the API, and nothing else.
        $expected = collect(OpenApiSpec::routes())->map(fn ($route): string => '/'.ltrim(Str::after($route->uri(), PublicApi::REST_PREFIX), '/'))->sort()->values()->all();
        $this->assertSame($expected, array_keys($document['paths']));
        $this->assertSame(url(PublicApi::REST_PREFIX), $document['servers'][0]['url']);

        // What the route, the controller's docblock and its parameters say.
        $show = $document['paths']['/pages/{page}']['get'];
        $this->assertSame('One live page anyone may see', $show['summary']);
        $this->assertStringContainsString('`pages.read`', $show['description']);
        $this->assertSame(['name' => 'page', 'in' => 'path', 'required' => true, 'description' => "The page's id.", 'schema' => ['type' => 'string', 'format' => 'uuid']], $show['parameters'][0]);
        $this->assertArrayHasKey('403', $show['responses']);

        $search = collect($document['paths']['/search']['get']['parameters'])->keyBy('name');
        $this->assertTrue($search['q']['required']);
        $this->assertSame('integer', $search['page']['schema']['type']);
        $this->assertContains('image', collect($document['paths']['/media']['get']['parameters'])->keyBy('name')['type']['schema']['enum']);

        // A key is asked for only while the API needs one.
        $this->assertSame([['apiKey' => []]], $document['security']);
        Setting::put([Api::Rest->setting() => ['enabled' => true, 'requires_key' => false]]);
        $this->assertArrayNotHasKey('security', $this->getJson(route('aurora.explorer.openapi'))->json());
    }

    public function test_a_new_endpoint_is_documented_without_anything_else_to_change(): void
    {
        Route::get(PublicApi::REST_PREFIX.'/forms/{form}', fn () => [])->name(OpenApiSpec::ROUTE_PREFIX.'forms.show');
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $this->assertArrayHasKey('/forms/{form}', $this->getJson(route('aurora.explorer.openapi'))->json('paths'));
    }

    public function test_the_explorers_load_the_api_s_own_documentation(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Setting::put([Api::Graphql->setting() => ['enabled' => true, 'requires_key' => true]]);

        $this->get(route('aurora.explorer.rest'))
            ->assertSee('swagger-ui-dist@5.17.14/swagger-ui-bundle.js', false)
            ->assertSee(Js::from(route('aurora.explorer.openapi'))->toHtml(), false)
            ->assertSee('The REST API is switched off');

        $this->get(route('aurora.explorer.graphql'))
            ->assertSee('graphiql@3.7.1/graphiql.min.js', false)
            ->assertSee(Js::from(url(PublicApi::GRAPHQL_PATH))->toHtml(), false)
            ->assertSee('The API needs a key')
            ->assertDontSee('The GraphQL API is switched off');
    }

    public function test_the_api_tabs_in_settings_link_to_their_explorer_once_the_api_is_on(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        $tabs = ['rest-api' => [Api::Rest, 'Open the API explorer', 'aurora.explorer.rest'], 'graphql' => [Api::Graphql, 'Open the GraphQL playground', 'aurora.explorer.graphql']];

        foreach ($tabs as $section => [$api, $label, $route]) {
            // Off: nothing to explore (the API answers "not found").
            Livewire::withQueryParams(['tab' => 'integrations', 'section' => $section])
                ->test(SiteSettings::class)
                ->assertDontSee($label);

            Setting::put([$api->setting() => ['enabled' => true, 'requires_key' => true]]);

            Livewire::withQueryParams(['tab' => 'integrations', 'section' => $section])
                ->test(SiteSettings::class)
                ->assertSee($label)
                ->assertSee(route($route), false);
        }
    }
}
