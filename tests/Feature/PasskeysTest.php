<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\Auth\PasskeyTwoFactorChallenge;
use Aurora\Filament\Pages\Auth\SetUpRequiredPasskey;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Livewire\ManagePasskeys;
use Aurora\Models\AuthenticatorApp;
use Aurora\Models\Passkey;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Livewire\SimpleUserMenu;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;

class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_offers_passkey_sign_in(): void
    {
        $this->get('https://localhost/admin/login')->assertOk()->assertSee('Authenticate using Passkey');
    }

    public function test_passkeys_are_only_offered_over_https(): void
    {
        $user = $this->userWithRole('Editor');
        Setting::put(['security' => ['require_passkey' => true]]);

        $this->get('http://localhost/admin/login')->assertOk()->assertDontSee('Authenticate using Passkey');

        // Nothing to add one with, so nothing to require, and no section for it.
        $this->actingAs($user)->get('http://localhost/admin/main')->assertOk();
        $this->actingAs($user)
            ->get(str_replace('https://', 'http://', UserResource::getUrl('edit', ['record' => $user])))
            ->assertOk()
            ->assertDontSeeLivewire(ManagePasskeys::class);

        $this->actingAs($user)->get('https://localhost/admin/main')->assertRedirect(route(SetUpRequiredPasskey::ROUTE_NAME));
    }

    public function test_passkeys_are_managed_on_your_own_profile_only(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $author = $this->userWithRole('Author');

        $this->actingAs($admin)
            ->get(UserResource::getUrl('edit', ['record' => $admin]))
            ->assertOk()
            ->assertSeeLivewire(ManagePasskeys::class);

        $this->actingAs($admin)
            ->get(UserResource::getUrl('edit', ['record' => $author]))
            ->assertOk()
            ->assertDontSeeLivewire(ManagePasskeys::class);
    }

    public function test_the_login_page_explains_a_failed_passkey_prompt(): void
    {
        $this->get('https://localhost/admin/login')
            ->assertOk()
            ->assertSee('Couldn’t sign in with a passkey', false)
            ->assertSee('js/shipaurora/cms/aurora.js', false);
    }

    public function test_adding_a_passkey_prepares_a_challenge_for_this_site_and_suggests_a_name(): void
    {
        $user = $this->userWithRole('Author');

        $component = Livewire::actingAs($user)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'])
            ->test(ManagePasskeys::class)
            ->assertSee('No passkeys yet');

        $options = $component->instance()->registrationOptions();

        $this->assertNotEmpty($options['challenge']);
        $this->assertSame('aurora.test', $options['rp']['id']);
        $this->assertSame($user->email, $options['user']['name']);
        $this->assertNotNull(session('passkey-registration-options'));
    }

    public function test_a_passkey_that_fails_verification_is_not_stored_and_says_why(): void
    {
        $user = $this->userWithRole('Author');

        Livewire::actingAs($user)
            ->test(ManagePasskeys::class)
            ->call('registrationOptions')
            ->call('createPasskey', 'My laptop', '{"id":"not-a-real-credential"}')
            ->assertNotified('No passkey was added')
            ->call('createPasskey', '   ', '{}')
            ->assertNotified('No passkey was added');

        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_passkeys_are_listed_and_can_be_revoked(): void
    {
        $user = $this->userWithRole('Author');
        $other = $this->userWithRole('Author');
        $laptop = $this->passkeyFor($user, 'Safari on Mac');
        $theirs = $this->passkeyFor($other, 'Their phone');

        Livewire::actingAs($user)
            ->test(ManagePasskeys::class)
            ->assertSee('Safari on Mac')
            ->assertSee('Not used yet')
            ->assertDontSee('Their phone')
            ->callAction('revoke', arguments: ['passkey' => $laptop->id])
            ->assertNotified('Passkey revoked')
            ->assertDontSee('Safari on Mac')
            ->callAction('revoke', arguments: ['passkey' => $theirs->id]);

        $this->assertModelMissing($laptop);
        $this->assertModelExists($theirs);
    }

    public function test_when_required_people_without_a_passkey_must_add_one_first(): void
    {
        $user = $this->userWithRole('Editor');
        Setting::put(['security' => ['require_passkey' => true]]);
        $setUpUrl = route(SetUpRequiredPasskey::ROUTE_NAME);

        $this->actingAs($user)->get('/admin/main')->assertRedirect($setUpUrl);

        // As straight after signing in: outside any site; the user menu, but no notifications,
        // and no offer of a passkey on top (nor after).
        Filament::setTenant(null, isQuiet: true);
        event(new Login('web', $user, false));

        $this->get($setUpUrl)
            ->assertOk()
            ->assertSee('Add a passkey')
            ->assertSeeLivewire(ManagePasskeys::class)
            ->assertSeeLivewire(SimpleUserMenu::class)
            ->assertDontSeeLivewire(DatabaseNotifications::class)
            ->assertDontSee('Sign in with a passkey next time?');

        $page = Livewire::actingAs($user)->test(SetUpRequiredPasskey::class)->assertDontSee('Continue');

        // Added below (ManagePasskeys says so): on to the admin.
        $passkey = $this->passkeyFor($user, 'Safari on Mac');
        $page->dispatch('passkeys-changed')->assertSee('Continue');

        Livewire::actingAs($user)->test(SetUpRequiredPasskey::class)->assertRedirect();
        Livewire::actingAs($user)->test(ManagePasskeys::class)->assertActionDisabled(TestAction::make('revoke')->arguments(['passkey' => $passkey->id]));

        Filament::setTenant(Site::default(), isQuiet: true);
        $this->actingAs($user)->get('/admin/main')->assertOk()->assertDontSee('Sign in with a passkey next time?');
    }

    public function test_signing_in_with_a_passkey_still_asks_for_the_authenticator_apps_code(): void
    {
        $user = $this->userWithRole('Editor');
        $app = AuthenticatorApp::factory()->for($user)->create();
        $passkey = $this->passkeyFor($user, 'Safari on Mac');
        $this->browserPresents($passkey);

        $this->post(route('passkeys.login'), ['start_authentication_response' => '{}'])
            ->assertRedirect(route(PasskeyTwoFactorChallenge::ROUTE_NAME));

        $this->assertGuest();

        Livewire::test(PasskeyTwoFactorChallenge::class)
            ->set('data.code', '000000')
            ->call('authenticate')
            ->assertHasErrors('data.code');

        $this->assertGuest();

        Livewire::test(PasskeyTwoFactorChallenge::class)
            ->set('data.code', (new Google2FA)->getCurrentOtp($app->secret))
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_without_an_authenticator_app_a_passkey_signs_straight_in(): void
    {
        $user = $this->userWithRole('Editor');
        $this->browserPresents($this->passkeyFor($user, 'Safari on Mac'));

        $this->post(route('passkeys.login'), ['start_authentication_response' => '{}'])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->get(route(PasskeyTwoFactorChallenge::ROUTE_NAME))->assertRedirect();
    }

    /**
     * The WebAuthn ceremony can't run here: the browser "presents" this passkey.
     */
    protected function browserPresents(Passkey $passkey): void
    {
        PresentedPasskey::$passkey = $passkey;
        config(['passkeys.actions.find_passkey' => PresentedPasskey::class]);
        session(['passkey-authentication-options' => '{}']);
    }

    protected function passkeyFor(User $user, string $name): Passkey
    {
        // The model only accepts a real WebAuthn credential, so the row goes in directly.
        $id = (string) Str::uuid();

        DB::table('passkeys')->insert([
            'id' => $id,
            'authenticatable_id' => $user->id,
            'name' => $name,
            'credential_id' => bin2hex(random_bytes(16)),
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Passkey::query()->findOrFail($id);
    }

    public function test_the_authentication_challenge_is_issued(): void
    {
        $this->get(route('passkeys.authentication_options'))->assertOk()->assertJsonStructure(['challenge']);
    }

    public function test_passkeys_belong_to_users_by_uuid(): void
    {
        $user = $this->userWithRole('Author');
        $passkey = $user->passkeys()->getRelated();

        $this->assertInstanceOf(Passkey::class, $passkey);
        $this->assertSame('string', $passkey->getKeyType());
        $this->assertFalse($passkey->getIncrementing());
    }
}

class PresentedPasskey extends FindPasskeyToAuthenticateAction
{
    public static ?Passkey $passkey = null;

    public function execute(string $publicKeyCredentialJson, string $passkeyOptionsJson): ?Passkey
    {
        return static::$passkey;
    }
}
