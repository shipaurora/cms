<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\AiChannel;
use Aurora\Enums\RevisionSource;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Livewire\AssistantChat;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\AddBlock;
use Aurora\Mcp\Tools\UpdateBlock;
use Aurora\Models\Asset;
use Aurora\Models\McpActivity;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Support\AssistantAgent;
use Aurora\Support\AssistantFiles;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\Copilot;
use Aurora\Support\CopilotContext;
use Aurora\Support\CopilotTools;
use Aurora\Tests\TestCase;
use EslamRedaDiv\FilamentCopilot\Models\CopilotConversation;
use EslamRedaDiv\FilamentCopilot\Services\ConversationManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request as ToolRequest;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CopilotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('assistant-files');

        parent::tearDown();
    }

    protected function configure(): void
    {
        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]);
        Copilot::storeApiKey('sk-ant-test-1234');
    }

    public function test_the_copilot_is_off_and_invisible_until_provider_model_and_key_are_set(): void
    {
        $editor = $this->userWithRole('Editor');

        $this->assertFalse(Copilot::isConfigured());
        $this->actingAs($editor)->get('/admin/main')->assertOk()->assertDontSee('filament-copilot-chat');
        $this->postJson(route('filament-copilot.stream'), ['message' => 'Hi', 'panel_id' => 'admin'])->assertForbidden();

        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]);
        $this->assertFalse(Copilot::isConfigured());

        Copilot::storeApiKey('sk-ant-test-1234');
        $this->assertTrue(Copilot::isConfigured());
        $this->actingAs($editor)->get('/admin/main')->assertOk()->assertSee('filament-copilot-chat');
    }

    public function test_only_people_allowed_to_use_the_ai_assistant_get_the_copilot(): void
    {
        $this->configure();

        $this->actingAs($this->userWithRole('Viewer'))->get('/admin/main')->assertOk()->assertDontSee('filament-copilot-chat');
    }

    public function test_the_assistant_is_switched_off_and_on_without_losing_its_key(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $this->configure();

        Livewire::actingAs($admin)
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->assertFormFieldExists('copilot.enabled')
            ->fillForm(['copilot' => ['enabled' => false]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(Copilot::isConfigured());
        $this->assertTrue(Copilot::isSetUp());
        $this->assertTrue(Copilot::hasApiKey(), 'Switching it off keeps the key.');
        $this->actingAs($admin)->get('/admin/main')->assertOk()->assertDontSee('filament-copilot-chat');

        Livewire::actingAs($admin)
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->assertSee('It is switched off')
            ->fillForm(['copilot' => ['enabled' => true]])
            ->call('save');

        $this->assertTrue(Copilot::isConfigured());

        // Without a key, switched off: a first key switches it on again.
        Copilot::storeApiKey(null);
        Setting::put(['copilot' => [...Setting::get('copilot'), 'enabled' => false]]);

        Livewire::actingAs($admin)
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->fillForm(['copilot_api_key_input' => 'sk-ant-new-key'])
            ->call('save');

        $this->assertTrue(Copilot::isConfigured());
    }

    public function test_the_settings_store_the_key_encrypted_and_never_send_it_back(): void
    {
        $admin = $this->userWithRole('Super Admin');

        Livewire::actingAs($admin)
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->assertSee(['Website', 'Templates', 'AI Assistant'])
            ->assertSee('The assistant is off')
            ->fillForm(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'], 'copilot_api_key_input' => 'sk-ant-secret-9876'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = Setting::query()->where('key', Copilot::KEY_SETTING)->value('value');

        $this->assertNotSame('sk-ant-secret-9876', $stored);
        $this->assertSame('sk-ant-secret-9876', Copilot::apiKey());
        $this->assertTrue(Copilot::isConfigured());

        Livewire::actingAs($admin)
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->assertSee('The assistant is on')
            ->assertSee('••••9876')
            ->assertDontSee('sk-ant-secret-9876')
            ->assertDontSee($stored)
            ->fillForm(['copilot_api_key_input' => ''])
            ->call('save');

        $this->assertSame('sk-ant-secret-9876', Copilot::apiKey(), 'Saving without a new key keeps the old one.');

        Copilot::applyConfig();
        $this->assertSame('sk-ant-secret-9876', config('ai.providers.anthropic.key'));
        $this->assertSame('claude-sonnet-5-5', config('filament-copilot.model'));
    }

    public function test_the_copilot_gets_the_cms_tools_the_users_role_allows(): void
    {
        $this->actingAs($this->userWithRole('Author'));
        $authorTools = collect(CopilotTools::forCurrentUser())->map->name();

        $this->actingAs($this->userWithRole('Editor'));
        $editorTools = collect(CopilotTools::forCurrentUser())->map->name();

        $this->assertContains('update_block', $authorTools);
        $this->assertNotContains('publish_page', $authorTools);
        $this->assertContains('publish_page', $editorTools);
    }

    public function test_a_tool_call_from_the_copilot_edits_the_page_as_the_user_with_a_revision(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create([
            'title' => 'Careers',
            'blocks' => BlockTreeBuilder::flatten([['type' => 'heading', 'data' => ['text' => 'Join us', 'level' => 'h2']]]),
        ]);
        $block = collect($page->blocks)->firstWhere('type', 'heading');

        $this->actingAs($editor);

        $tool = collect(CopilotTools::forCurrentUser())->first(fn (McpServerTool $tool): bool => $tool->name() === 'update_block');
        $result = $tool->handle(new ToolRequest(['page' => $page->id, 'block_id' => $block['id'], 'data' => ['text' => 'Work with us']]));

        $this->assertStringNotContainsStringIgnoringCase('error', $result);
        $this->assertSame('Work with us', collect($page->fresh()->blocks)->firstWhere('id', $block['id'])['data']['text']);
        $this->assertSame($editor->id, $page->revisions()->latest()->first()->user_id);
    }

    public function test_the_copilot_knows_which_page_the_user_has_open(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        $page = Page::factory()->create([
            'title' => 'Careers',
            'blocks' => BlockTreeBuilder::flatten([['type' => 'heading', 'data' => ['text' => 'Join the crew', 'level' => 'h2']]]),
        ]);
        $block = collect($page->blocks)->firstWhere('type', 'heading');

        $screen = CopilotContext::describeScreen(PageResource::getUrl('design', ['record' => $page]));

        $this->assertStringContainsString('“Careers” open in the visual editor', $screen);
        $this->assertStringContainsString($page->id, $screen);
        $this->assertStringContainsString('Join the crew', $screen);
        $this->assertStringContainsString('Do not ask which page', $screen);
        $this->assertStringContainsString("The main headline is block {$block['id']}, field `text`", $screen);
        $this->assertStringContainsString('Do not ask which headline', $screen);

        $this->assertStringContainsString('pages index screen', CopilotContext::describeScreen(PageResource::getUrl('index')));
        $this->assertNull(CopilotContext::describeScreen('https://evil.example/admin/pages'));
    }

    public function test_a_chat_message_streams_a_reply_with_the_open_page_in_the_instructions(): void
    {
        $this->configure();
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['title' => 'Careers']);
        $design = PageResource::getUrl('design', ['record' => $page]);
        AssistantAgent::fake(['Done: the heading now says “Work with us”.']);

        // The stream is not a tenant route. The site has to come from the page the chat was opened on.
        Filament::setTenant(null, isQuiet: true);

        $response = $this->actingAs($editor)
            ->withHeader('Referer', $design)
            ->post(route('filament-copilot.stream'), ['message' => 'Change the heading to Work with us', 'panel_id' => 'admin']);

        $response->assertOk();
        $stream = $this->captureStream($response->baseResponse);

        $this->assertStringContainsString('event: chunk', $stream);
        $this->assertStringContainsString('"text":" heading"', $stream);
        $this->assertStringContainsString('event: done', $stream);
        $this->assertSame(1, $editor->copilotConversations()->count());
        $this->assertSame(Site::default()->getKey(), $editor->copilotConversations()->value('tenant_id'));
        $this->assertStringNotContainsString('event: error', $stream);

        AssistantAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->agent->instructions(), '“Careers” open in the visual editor'));
    }

    /**
     * The copilot's SSE stream drops one output buffer before it starts writing, which
     * empties the usual streamedContent() capture; a handler-backed buffer survives it.
     */
    protected function captureStream(StreamedResponse $response): string
    {
        $captured = '';
        $level = ob_get_level();

        ob_start(function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        });
        ob_start();

        $response->sendContent();

        while (ob_get_level() > $level) {
            ob_end_flush();
        }

        return $captured;
    }

    public function test_suggestions_depend_on_the_screen(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        $page = Page::factory()->create();
        $labels = fn (string $url): array => array_column(CopilotContext::suggestionsFor($url), 'label');

        $this->assertContains('Sharpen the headline', $labels(PageResource::getUrl('design', ['record' => $page])));
        $this->assertContains('Draft a new page', $labels(PageResource::getUrl('index')));
        $this->assertNotContains('Sharpen the headline', $labels(PageResource::getUrl('index')));
        $this->assertContains('Missing alt text', $labels(AssetResource::getUrl('index')));
        $this->assertContains('What can you do?', $labels(url('/admin/main')));
    }

    public function test_suggestions_are_in_the_users_language(): void
    {
        $page = Page::factory()->create(['author_id' => $this->userWithRole('Editor')->id]);
        app()->setLocale('nb');

        $suggestion = CopilotContext::suggestionsFor(PageResource::getUrl('edit', ['record' => $page]))[0];

        $this->assertSame('Oppsummer denne siden', $suggestion['label']);
        $this->assertStringStartsWith('Gi meg en kort oversikt', $suggestion['prompt']);
    }

    public function test_the_instructions_are_set_in_site_settings_with_a_good_default(): void
    {
        $editor = $this->userWithRole('Editor', ['name' => 'Ingrid Editor']);
        $context = fn (): string => app(CopilotContext::class)->forUser($editor)->forPanel('admin')->build();

        $this->assertStringContainsString('You work for Ingrid Editor', $context());
        $this->assertStringContainsString('Never invent facts', $context());

        Setting::put(['copilot' => ['instructions' => 'You are {site}’s friendly pirate. Greet {user} with “Ahoy”.']]);

        $this->assertStringContainsString('You are '.config('app.name').'’s friendly pirate. Greet Ingrid Editor with “Ahoy”.', $context());
        $this->assertStringNotContainsString('Never invent facts', $context());
        $this->assertStringContainsString('list_block_types', $context());
    }

    public function test_the_assistant_replies_in_the_users_language(): void
    {
        $this->withEnglishAndNorwegian();
        $editor = $this->userWithRole('Editor', ['name' => 'Ingrid Editor', 'locale' => 'nb']);

        $context = app(CopilotContext::class)->forUser($editor)->forPanel('admin')->build();

        $this->assertStringContainsString('Ingrid Editor reads the admin panel in Norsk bokmål (nb). Reply in Norsk bokmål', $context);
    }

    public function test_links_in_replies_get_a_readable_label(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        $page = Page::factory()->create(['title' => 'About us', 'slug' => 'about']);
        $editor = PageResource::getUrl('design', ['record' => $page]);
        $render = fn (string $markdown): string => CopilotContext::prepareLinks(Str::markdown($markdown, [], [new AutolinkExtension]));

        $this->assertStringContainsString('>Open “About us” in the visual editor</a>', $render($editor));
        $this->assertStringContainsString('>View “About us” on the website</a>', $render('<'.url('/about').'>'));
        $this->assertStringContainsString('>example.com</a>', $render('See https://example.com/some/long/path'));
        $this->assertStringContainsString('>the editor</a>', $render('['.'the editor'.']('.$editor.')'));
        $this->assertStringContainsString('target="_blank" rel="noopener"', $render('[the editor]('.$editor.')'));
    }

    public function test_a_downloaded_conversation_is_named_after_the_assistant(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 15, 9, 30, 0));
        $editor = $this->userWithRole('Editor');
        // On the site being worked on: conversations are per site.
        $site = Site::default();
        $conversation = CopilotConversation::query()->create(['participant_type' => $editor->getMorphClass(), 'participant_id' => $editor->id, 'panel_id' => 'admin', 'tenant_type' => $site->getMorphClass(), 'tenant_id' => $site->id, 'title' => 'Help']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Hei']);

        Filament::setCurrentPanel('admin');

        Livewire::actingAs($editor)
            ->test(AssistantChat::class)
            ->set('conversationId', $conversation->id)
            ->call('exportConversation')
            ->assertFileDownloaded('ai-assistant-conversation-2026-10-15-093000.md');
    }

    public function test_the_assistant_opens_from_a_chat_bubble(): void
    {
        $this->configure();

        $this->actingAs($this->userWithRole('Editor'))
            ->get('/admin/main')
            ->assertOk()
            ->assertSee('id="cms-assistant"', false)
            ->assertSee('Open the assistant')
            ->assertDontSee('Open Copilot');
    }

    public function test_the_assistant_and_external_ai_apps_are_separate_permissions(): void
    {
        $this->configure();
        $page = Page::factory()->create([
            'title' => 'Careers',
            'blocks' => BlockTreeBuilder::flatten([['type' => 'heading', 'data' => ['text' => 'Join us', 'level' => 'h2']]]),
        ]);
        $block = collect($page->blocks)->firstWhere('type', 'heading');

        $mcpOnly = $this->userWithRole('Editor');
        $mcpOnly->roles->first()->revokePermissionTo('use-assistant');
        $mcpOnly->refresh();

        $this->actingAs($mcpOnly)->get('/admin/main')->assertDontSee('id="cms-assistant"', false);
        $denied = collect(CopilotTools::forCurrentUser())->first(fn (McpServerTool $tool): bool => $tool->name() === 'update_block')
            ->handle(new ToolRequest(['page' => $page->id, 'block_id' => $block['id'], 'data' => ['text' => 'Nope']]));
        $this->assertStringContainsString('not allowed to use the assistant in the admin panel', $denied);

        $assistantOnly = $this->userWithRole('Author');
        $assistantOnly->roles->first()->revokePermissionTo('use-mcp');
        $role = $assistantOnly->roles->first();
        $role->givePermissionTo('update-any Page');
        $assistantOnly->refresh();

        $this->actingAs($assistantOnly);
        $result = collect(CopilotTools::forCurrentUser())->first(fn (McpServerTool $tool): bool => $tool->name() === 'update_block')
            ->handle(new ToolRequest(['page' => $page->id, 'block_id' => $block['id'], 'data' => ['text' => 'Work with us']]));

        $this->assertStringNotContainsStringIgnoringCase('not allowed', $result);
        $this->assertSame(RevisionSource::Assistant, $page->revisions()->latest()->first()->source);
        $this->assertSame(AiChannel::Assistant, McpActivity::query()->latest()->first()->channel);

        CmsServer::actingAs($assistantOnly)
            ->tool(UpdateBlock::class, ['page' => $page->id, 'block_id' => $block['id'], 'data' => ['text' => 'Via MCP']])
            ->assertSee('Connect external AI apps');
    }

    public function test_a_custom_endpoint_can_emulate_another_providers_api(): void
    {
        Setting::put(['copilot' => ['provider' => 'custom', 'model' => 'meta-llama/llama-4', 'custom_api' => 'openai-compatible']]);
        Copilot::storeApiKey('sk-or-1234');
        $this->assertFalse(Copilot::isConfigured(), 'A custom provider needs an endpoint.');

        Setting::put(['copilot' => ['provider' => 'custom', 'model' => 'meta-llama/llama-4', 'custom_api' => 'openai-compatible', 'custom_url' => 'https://openrouter.ai/api/v1/']]);
        $this->assertTrue(Copilot::isConfigured());

        Copilot::applyConfig();

        $this->assertSame('custom', config('filament-copilot.provider'));
        $this->assertSame(['driver' => 'openai-compatible', 'key' => 'sk-or-1234', 'url' => 'https://openrouter.ai/api/v1'], config('ai.providers.custom'));
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, Ai::textProvider('custom'));
    }

    public function test_a_local_ollama_endpoint_needs_no_key(): void
    {
        Setting::put(['copilot' => ['provider' => 'custom', 'model' => 'llama3.2', 'custom_api' => 'ollama', 'custom_url' => 'http://localhost:11434']]);

        $this->assertTrue(Copilot::isConfigured());

        // The driver calls /api/chat itself, so an address that already ends in /api must not be doubled.
        Setting::put(['copilot' => ['provider' => 'custom', 'model' => 'llama3.2', 'custom_api' => 'ollama', 'custom_url' => 'http://localhost:11434/api']]);
        Copilot::applyConfig();

        $this->assertSame('http://localhost:11434', config('ai.providers.custom.url'));
    }

    public function test_switching_the_assistant_on_reloads_the_settings_so_the_bubble_appears(): void
    {
        Livewire::actingAs($this->userWithRole('Super Admin'))
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->fillForm(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'], 'copilot_api_key_input' => 'sk-ant-1234'])
            ->call('save')
            ->assertRedirect();

        Livewire::actingAs($this->userWithRole('Super Admin'))
            ->withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->fillForm(['copilot' => ['model' => 'claude-sonnet-5-5-renamed']])
            ->call('save')
            ->assertNoRedirect();
    }

    public function test_the_open_conversation_is_resumed_after_a_reload(): void
    {
        $this->configure();
        Filament::setCurrentPanel('admin');
        $editor = $this->userWithRole('Editor');
        $manager = app(ConversationManager::class);
        $conversation = $manager->create($editor, 'admin', Site::default());
        $manager->addUserMessage($conversation, 'Change the heading to Work with us');

        $this->actingAs($editor);

        Livewire::test(AssistantChat::class)
            ->assertSet('conversationId', null)
            ->call('loadConversation', $conversation->id);

        $this->assertSame($conversation->id, session(AssistantChat::SESSION_KEY));

        Livewire::test(AssistantChat::class)
            ->assertSet('conversationId', $conversation->id)
            ->assertSee('Change the heading to Work with us')
            ->call('newConversation');

        $this->assertNull(session(AssistantChat::SESSION_KEY));
    }

    public function test_someone_elses_conversation_is_never_resumed(): void
    {
        $this->configure();
        Filament::setCurrentPanel('admin');
        $theirs = app(ConversationManager::class)->create($this->userWithRole('Editor'), 'admin');

        session([AssistantChat::SESSION_KEY => $theirs->id]);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(AssistantChat::class)
            ->assertSet('conversationId', null);

        $this->assertNull(session(AssistantChat::SESSION_KEY));
    }

    public function test_a_chat_opened_on_another_host_is_not_filed_under_this_site(): void
    {
        $this->configure();
        $editor = $this->userWithRole('Editor');
        AssistantAgent::fake(['Done.']);
        Filament::setTenant(null, isQuiet: true);

        $this->actingAs($editor)
            ->withHeader('Referer', 'https://evil.example/admin/main/pages/1/design')
            ->post(route('filament-copilot.stream'), ['message' => 'Hi', 'panel_id' => 'admin'])
            ->assertOk();

        $this->assertNull($editor->copilotConversations()->value('tenant_id'));
    }

    public function test_a_chat_saved_without_a_site_is_resumed_after_a_reload(): void
    {
        $this->configure();
        Filament::setCurrentPanel('admin');
        $editor = $this->userWithRole('Editor');
        $manager = app(ConversationManager::class);
        $conversation = $manager->create($editor, 'admin');
        $manager->addUserMessage($conversation, 'Change the heading');

        session([AssistantChat::SESSION_KEY => $conversation->id]);

        Livewire::actingAs($editor)
            ->test(AssistantChat::class)
            ->assertSet('conversationId', $conversation->id)
            ->assertSee('Change the heading');

        $this->assertSame(Site::default()->getKey(), $conversation->fresh()->tenant_id);
    }

    public function test_the_open_conversation_is_remembered_before_its_reply_finishes(): void
    {
        $this->configure();
        Filament::setCurrentPanel('admin');
        $editor = $this->userWithRole('Editor');
        $conversation = app(ConversationManager::class)->create($editor, 'admin', Site::default());
        $someoneElses = app(ConversationManager::class)->create($this->userWithRole('Editor'), 'admin', Site::default());

        Livewire::actingAs($editor)
            ->test(AssistantChat::class)
            ->call('rememberConversation', $someoneElses->id)
            ->assertSet('conversationId', null)
            ->call('rememberConversation', $conversation->id)
            ->assertSet('conversationId', $conversation->id);

        $this->assertSame($conversation->id, session(AssistantChat::SESSION_KEY));
    }

    public function test_an_attached_file_stays_out_of_the_media_library(): void
    {
        $this->configure();
        Filament::setCurrentPanel('admin');
        $editor = $this->userWithRole('Editor');

        $component = Livewire::actingAs($editor)
            ->test(AssistantChat::class)
            ->set('imageUploads', [UploadedFile::fake()->image('fjord.jpg', 40, 30)]);

        $attached = $component->get('attachedImages');

        $this->assertSame(0, Asset::query()->count());
        $this->assertCount(1, $attached);
        $this->assertTrue(AssistantFiles::isTemporary($attached[0]['id']));
        $this->assertSame('Fjord', $attached[0]['title']);
        $this->assertSame('image', $attached[0]['kind']);
        $this->assertSame(route('assistant-files.show', ['file' => $attached[0]['id']]), $attached[0]['url']);

        $this->actingAs($editor)->get($attached[0]['url'])->assertOk();
        $this->actingAs($this->userWithRole('Editor'))->get($attached[0]['url'])->assertNotFound();

        $component->set('imageUploads', [
            UploadedFile::fake()->createWithContent('people.csv', "name,city\nAda,Bergen\n"),
            UploadedFile::fake()->createWithContent('notes.txt', "Meet at the fjord.\n"),
            UploadedFile::fake()->createWithContent('price-list.pdf', $this->pdf('Hello from the pdf')),
        ]);

        $attached = $component->get('attachedImages');

        $this->assertSame(['image', 'csv', 'txt', 'pdf'], array_column($attached, 'kind'));
        $this->assertSame([null, null, null], array_column(array_slice($attached, 1), 'url'));
        $this->assertSame(0, Asset::query()->count());

        $textId = $attached[2]['id'];

        $component->call('detachImage', $textId);

        $this->assertCount(3, $component->get('attachedImages'));
        $this->assertFalse(Storage::disk('local')->exists('assistant-files/'.$editor->getKey().'/'.$textId.'.txt'));

        Livewire::actingAs($editor)
            ->test(AssistantChat::class)
            ->set('imageUploads', [UploadedFile::fake()->create('notes.exe', 20, 'application/x-msdownload')])
            ->assertSet('attachedImages', [])
            ->assertNotified('Attach an image, a PDF, or a text or CSV file.');
    }

    public function test_an_attached_file_is_given_to_the_assistant(): void
    {
        $this->configure();
        $editor = $this->userWithRole('Editor');
        $this->actingAs($editor);

        $image = AssistantFiles::store(UploadedFile::fake()->image('fjord.jpg', 40, 30), $editor);
        $csv = AssistantFiles::store(UploadedFile::fake()->createWithContent('people.csv', "name,city\nAda,Bergen\n"), $editor);
        $pdf = AssistantFiles::store(UploadedFile::fake()->createWithContent('price-list.pdf', $this->pdf('Hello from the pdf')), $editor);
        AssistantAgent::fake(['I put it on the page.']);

        $response = $this->actingAs($editor)
            ->post(route('filament-copilot.stream'), [
                'message' => 'Add this to the hero',
                'images' => [$image['id'], $csv['id'], $pdf['id'], 'not-a-file'],
                'panel_id' => 'admin',
            ]);

        $response->assertOk();
        $this->captureStream($response->baseResponse);

        AssistantAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, $image['id'])
            && str_contains($prompt->prompt, 'Ada,Bergen')
            && str_contains($prompt->prompt, 'Hello from the pdf')
            && str_contains($prompt->prompt, 'not in the media library')
            && $prompt->attachments->isNotEmpty());

        $message = $editor->copilotConversations()->first()->messages()->where('role', 'user')->first();

        $this->assertSame($image['id'], $message->metadata['files'][0]['id']);
        $this->assertSame('csv', $message->metadata['files'][1]['kind']);
        $this->assertSame('pdf', $message->metadata['files'][2]['kind']);

        $response = $this->actingAs($editor)
            ->post(route('filament-copilot.stream'), [
                'message' => 'Summarise the spreadsheet',
                'images' => [$csv['id']],
                'panel_id' => 'admin',
            ]);

        $response->assertOk();
        $this->captureStream($response->baseResponse);

        AssistantAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Summarise the spreadsheet')
            && str_contains($prompt->prompt, 'Ada,Bergen')
            && $prompt->attachments->isEmpty());
    }

    public function test_an_attached_file_joins_the_media_library_when_a_page_uses_it(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->actingAs($editor);

        $image = AssistantFiles::store(UploadedFile::fake()->image('fjord.jpg', 40, 30), $editor);
        $pdf = AssistantFiles::store(UploadedFile::fake()->createWithContent('price-list.pdf', $this->pdf('Hello from the pdf')), $editor);
        $page = Page::factory()->create([
            'author_id' => $editor->id,
            'blocks' => [[
                'id' => 'picture',
                'type' => 'image',
                'data' => ['ratio' => 'auto'],
            ]],
        ]);

        CmsServer::actingAs($editor)->tool(UpdateBlock::class, [
            'page' => $page->id,
            'block_id' => 'picture',
            'data' => ['src' => $image['id']],
        ])->assertOk();

        $page->refresh();
        $src = collect($page->blocks)->firstWhere('id', 'picture')['data']['src'];
        $asset = Asset::query()->find($src);

        $this->assertFalse(AssistantFiles::isTemporary((string) $src));
        $this->assertSame('Fjord', $asset?->title);
        $this->assertTrue($asset?->isImage());

        CmsServer::actingAs($editor)->tool(AddBlock::class, [
            'page' => $page->id,
            'block' => [
                'type' => 'button',
                'data' => ['label' => 'Price list', 'link_type' => 'file', 'link_file' => $pdf['id']],
            ],
        ])->assertOk();

        $page->refresh();
        $fileId = collect($page->blocks)->firstWhere('type', 'button')['data']['link_file'];

        $this->assertSame('Price list', Asset::query()->find($fileId)?->title);
        $this->assertSame(2, Asset::query()->count());

        $someoneElse = $this->userWithRole('Editor');
        $theirs = AssistantFiles::store(UploadedFile::fake()->image('other.jpg', 20, 20), $someoneElse);

        CmsServer::actingAs($editor)->tool(UpdateBlock::class, [
            'page' => $page->id,
            'block_id' => 'picture',
            'data' => ['src' => $theirs['id']],
        ])->assertHasErrors(['no longer available']);

        $scan = AssistantFiles::store(UploadedFile::fake()->createWithContent('scan.pdf', $this->pdf('A scanned page')), $editor);

        CmsServer::actingAs($editor)->tool(UpdateBlock::class, [
            'page' => $page->id,
            'block_id' => 'picture',
            'data' => ['src' => $scan['id']],
        ])->assertHasErrors(['images only']);

        $this->assertSame(2, Asset::query()->count());

        Asset::query()->each(fn (Asset $asset) => $asset->delete());
    }

    protected function pdf(string $text): string
    {
        return implode("\n", [
            '%PDF-1.4',
            '1 0 obj << /Length 80 >> stream',
            'BT /F1 12 Tf ('.$text.') Tj ET',
            'endstream',
            'endobj',
            'trailer <<>>',
            '%%EOF',
            '',
        ]);
    }
}
