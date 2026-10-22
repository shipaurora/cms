<?php

namespace Aurora\Tests\Feature;

use Aurora\Livewire\PasskeyPrompt;
use Aurora\Tests\TestCase;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

class PasskeyPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_page_after_signing_in_offers_a_passkey_once_but_not_the_first_time(): void
    {
        $user = $this->userWithRole('Editor');
        $this->actingAs($user);

        // The very first sign-in is kept quiet.
        event(new Login('web', $user, false));

        $this->assertNotNull($user->fresh()->last_signed_in_at);
        $this->get('/admin/main')->assertOk()->assertDontSee('Sign in with a passkey next time?');

        // From the next one on: offered on the first page, once.
        event(new Login('web', $user->fresh(), false));

        $this->get('/admin/main')->assertOk()->assertSee('Sign in with a passkey next time?');
        $this->get('/admin/main')->assertOk()->assertDontSee('Sign in with a passkey next time?');
    }

    public function test_dont_show_this_again_is_kept_on_the_user(): void
    {
        $user = $this->userWithRole('Editor');

        Livewire::actingAs($user)->test(PasskeyPrompt::class)->call('dismiss', true);

        $this->assertNotNull($user->fresh()->passkey_prompt_dismissed_at);

        $this->actingAs($user->fresh());
        session()->put(PasskeyPrompt::SESSION_KEY, true);

        $this->assertFalse(PasskeyPrompt::shouldOffer());
    }

    public function test_not_now_asks_again_next_time(): void
    {
        $user = $this->userWithRole('Editor');

        Livewire::actingAs($user)->test(PasskeyPrompt::class)->call('dismiss', false);

        $this->assertNull($user->fresh()->passkey_prompt_dismissed_at);

        session()->put(PasskeyPrompt::SESSION_KEY, true);

        $this->assertTrue(PasskeyPrompt::shouldOffer());
    }

    public function test_people_with_a_passkey_are_not_asked(): void
    {
        $user = $this->userWithRole('Editor');
        DB::table('passkeys')->insert(['id' => (string) Str::uuid(), 'authenticatable_id' => $user->id, 'name' => 'Mac', 'credential_id' => 'abc', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        session()->put(PasskeyPrompt::SESSION_KEY, true);

        $this->assertFalse(PasskeyPrompt::shouldOffer());
    }
}
