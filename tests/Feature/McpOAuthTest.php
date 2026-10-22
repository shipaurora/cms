<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\AiAssistant;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Livewire\Livewire;

class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ClientRepository::class)->createPersonalAccessGrantClient('Personal access tokens', 'users');
    }

    /**
     * @return array<string, string>
     */
    protected function mcpHeaders(?string $token = null): array
    {
        return array_filter([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    protected function whoami(?string $token = null): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => []]], $this->mcpHeaders($token));
    }

    public function test_unauthenticated_clients_are_pointed_at_oauth_discovery(): void
    {
        $this->whoami()
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_only_trusted_redirect_domains_can_register(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertSuccessful();
        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/callback']])->assertStatus(400);
    }

    public function test_a_personal_access_token_is_a_passport_token_that_reaches_the_tools(): void
    {
        $author = $this->userWithRole('Author');
        $token = $author->createToken('Claude Code', ['mcp:use']);

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token->token->id, 'user_id' => $author->id]);
        $this->whoami($token->accessToken)->assertOk()->assertSee($author->email);

        $token->token->revoke();
        $this->app['auth']->forgetGuards();
        $this->whoami($token->accessToken)->assertUnauthorized();
    }

    public function test_the_consent_screen_shows_who_connects_and_what_they_may_do(): void
    {
        $author = $this->userWithRole('Author');
        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->json('client_id');

        $this->actingAs($author)
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'response_type' => 'code',
                'scope' => 'mcp:use',
                'state' => 'abc',
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
            ]))
            ->assertOk()
            ->assertSee('Connect Claude')
            ->assertSee($author->name)
            ->assertSee('Create pages and edit the ones you wrote')
            ->assertSee('Allow access')
            ->assertSee('fi-simple-layout', false);
    }

    public function test_connected_apps_can_be_listed_and_disconnected(): void
    {
        $editor = $this->userWithRole('Editor');
        $token = $editor->createToken('Claude Code', ['mcp:use']);

        Livewire::actingAs($editor)
            ->test(AiAssistant::class)
            ->assertCanSeeTableRecords([$token->token->id])
            ->callTableAction('disconnect', $token->token->id)
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$token->token->id]);

        $this->assertTrue(Token::query()->find($token->token->id)->revoked);
    }

    public function test_a_new_site_without_a_personal_access_client_still_makes_tokens(): void
    {
        // As after aurora:install: Passport's keys, but no client for personal access tokens.
        Client::query()->delete();
        $editor = $this->userWithRole('Editor');

        $page = Livewire::actingAs($editor)->test(AiAssistant::class);
        $page->callAction('createToken', ['name' => 'Laptop'])->assertHasNoActionErrors();
        $page->callAction('createToken', ['name' => 'Desktop'])->assertHasNoActionErrors();

        $this->assertSame(2, $editor->tokens()->count());
        $this->assertSame(1, Client::query()->count());
    }

    public function test_a_new_token_is_shown_once_on_the_page(): void
    {
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(AiAssistant::class)
            ->callAction('createToken', ['name' => 'Laptop'])
            ->assertSet('plainToken', fn (?string $token): bool => strlen((string) $token) > 100)
            ->assertSee('Laptop');

        $this->assertSame(1, $editor->tokens()->count());
    }
}
