<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Events\FormSubmitted;
use Aurora\Events\PageDeleted;
use Aurora\Events\PagePublished;
use Aurora\Events\PageSaved;
use Aurora\Events\PageUnpublished;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Models\Webhook;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_tell_the_app_what_happens_to_them(): void
    {
        Event::fake([PageSaved::class, PagePublished::class, PageUnpublished::class, PageDeleted::class]);

        $page = Page::factory()->create();
        $page->update(['status' => PageStatus::Published]);
        $page->update(['status' => PageStatus::Draft]);
        $page->delete();

        Event::assertDispatched(PageSaved::class);
        Event::assertDispatchedTimes(PagePublished::class, 1);
        Event::assertDispatchedTimes(PageUnpublished::class, 1);
        Event::assertDispatched(PageDeleted::class, fn (PageDeleted $event): bool => $event->page->is($page));
    }

    public function test_a_webhook_gets_the_events_it_wants_signed_with_its_secret(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true])]);
        $webhook = Webhook::factory()->create(['url' => 'https://hooks.example.com/aurora', 'events' => ['page.published']]);
        Webhook::factory()->create(['url' => 'https://hooks.example.com/forms', 'events' => ['form.submitted']]);

        Page::factory()->published()->create(['title' => 'Launch']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.example.com/aurora'
            && $request['event'] === 'page.published'
            && $request['data']['title'] === 'Launch'
            && $request->header('X-Aurora-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), $webhook->secret));
        $this->assertSame(200, $webhook->fresh()->last_status);
        $this->assertTrue($webhook->fresh()->lastSucceeded());
    }

    public function test_a_failing_webhook_says_why_and_paused_ones_get_nothing(): void
    {
        Http::fake(['*' => Http::response('Nope', 500)]);
        $failing = Webhook::factory()->create(['events' => ['page.published']]);
        Webhook::factory()->create(['events' => ['page.published'], 'is_active' => false]);

        Page::factory()->published()->create();

        Http::assertSentCount(1);
        $this->assertFalse($failing->fresh()->lastSucceeded());
        $this->assertSame('HTTP 500', $failing->fresh()->last_error);
    }

    public function test_webhooks_are_set_up_and_tested_in_settings(): void
    {
        Http::fake();
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $settings = Livewire::withQueryParams(['tab' => 'integrations', 'section' => 'webhooks'])->test(SiteSettings::class);
        $settings->callAction(TestAction::make('addWebhook')->schemaComponent('section.webhooks.add-webhook', schema: 'form'), data: [
            'url' => 'https://hooks.example.com/aurora', 'events' => ['page.saved', 'form.submitted'], 'is_active' => true,
        ])->assertHasNoActionErrors()
            // Listed straight away.
            ->assertSee('page.saved, form.submitted');

        $webhook = Webhook::query()->sole();
        $this->assertSame(['page.saved', 'form.submitted'], $webhook->events);
        $this->assertStringStartsWith('whsec_', $webhook->secret);

        $settings->callAction(TestAction::make('testWebhook')->schemaComponent('section.webhooks.webhook-'.$webhook->id, schema: 'form'))
            ->assertNotified('The test went through');
        Http::assertSent(fn (Request $request): bool => $request['event'] === 'test');
    }

    public function test_a_sent_form_is_an_event_too(): void
    {
        Event::fake([FormSubmitted::class]);
        $form = Form::factory()->create();

        $this->post(route('forms.submit', $form), [
            'name' => 'Kari',
            'email' => 'kari@example.com',
            'budget' => '€10–50k',
            'message' => 'Hello!',
            'consent' => '1',
            '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ])->assertRedirect();

        Event::assertDispatched(FormSubmitted::class);
    }
}
