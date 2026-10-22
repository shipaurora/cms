<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Auth\AuthenticatorApps;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Models\AuthenticatorApp;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Livewire\SimpleUserMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_add_several_authenticator_apps_on_their_profile(): void
    {
        $user = $this->userWithRole('Editor');

        $this->addAppOnProfile($user);
        $this->addAppOnProfile($user);

        $this->assertSame(['Authenticator app', 'Authenticator app 2'], $user->authenticatorApps()->pluck('name')->all());
        $this->assertCount(8, $user->fresh()->getAppAuthenticationRecoveryCodes());

        [$first, $second] = $user->authenticatorApps()->get();

        Livewire::actingAs($user)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSeeInOrder(['Authenticator app', 'Authenticator app 2'])
            ->callAction(TestAction::make('renameAuthenticatorApp')->arguments(['app' => $first->id])->schemaComponent("authenticator-app-{$first->id}", schema: 'form'), ['name' => 'Work phone'])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('removeAuthenticatorApp')->arguments(['app' => $second->id])->schemaComponent("authenticator-app-{$second->id}", schema: 'form'));

        $this->assertSame(['Work phone'], $user->authenticatorApps()->pluck('name')->all());
        $this->assertNotNull($user->fresh()->getAppAuthenticationRecoveryCodes(), 'Still on with one app left.');

        Livewire::actingAs($user)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->callAction(TestAction::make('removeAuthenticatorApp')->arguments(['app' => $first->id])->schemaComponent("authenticator-app-{$first->id}", schema: 'form'));

        $this->assertFalse($user->authenticatorApps()->exists());
        $this->assertNull($user->fresh()->getAppAuthenticationRecoveryCodes(), 'Off without apps: the recovery codes go too.');
    }

    public function test_signing_in_takes_a_code_from_any_of_the_apps(): void
    {
        $user = $this->userWithRole('Editor', ['email' => 'two@example.com']);
        AuthenticatorApp::factory()->for($user)->create();
        $phone = AuthenticatorApp::factory()->for($user)->create(['name' => 'Phone']);

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => 'two@example.com', 'password' => 'password'])
            ->call('authenticate');

        $this->assertGuest();

        $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
        $this->assertGuest();

        $login->set('data.multiFactor.app.code', (new Google2FA)->getCurrentOtp($phone->secret))->call('authenticate');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($phone->fresh()->last_used_at);
    }

    public function test_when_required_people_without_an_app_must_add_one_first(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $editor = $this->userWithRole('Editor');
        $setUpUrl = route('filament.admin.auth.multi-factor-authentication.set-up-required');

        $this->actingAs($editor)->get('/admin/main')->assertOk();

        Livewire::actingAs($admin)
            ->withQueryParams(['tab' => 'security'])
            ->test(SiteSettings::class)
            ->assertSee('2 people have no authenticator app yet')
            ->fillForm(['security' => ['require_two_factor' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(AuthenticatorApps::isRequired());
        $this->actingAs($editor)->get('/admin/main')->assertRedirect($setUpUrl);

        // The set-up page is outside any site, as it is straight after signing in.
        Filament::setTenant(null, isQuiet: true);
        $this->actingAs($editor)->get($setUpUrl)->assertOk()
            ->assertSee('Add authenticator app')
            ->assertSeeLivewire(SimpleUserMenu::class)
            ->assertDontSee('Your profile')
            ->assertDontSeeLivewire(DatabaseNotifications::class);

        // With an app, on as usual; it can't remove its last one while it's required.
        $app = AuthenticatorApp::factory()->for($editor)->create();
        $this->actingAs($editor)->get('/admin/main')->assertOk();

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertActionDisabled(TestAction::make('removeAuthenticatorApp')->arguments(['app' => $app->id])->schemaComponent("authenticator-app-{$app->id}", schema: 'form'));
    }

    public function test_someone_who_manages_people_can_reset_another_persons_apps(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $editor = $this->userWithRole('Editor');
        AuthenticatorApp::factory()->for($editor)->count(2)->create();
        $editor->saveAppAuthenticationRecoveryCodes(['hashed']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertDontSee('Two-factor sign-in', 'Only on your own profile.')
            ->callAction('resetTwoFactor');

        $this->assertFalse($editor->authenticatorApps()->exists());
        $this->assertNull($editor->fresh()->getAppAuthenticationRecoveryCodes());
    }

    /**
     * Filament's set-up dialog: scan (here: read) the secret, type its code and your password.
     */
    protected function addAppOnProfile(User $user): void
    {
        $profile = Livewire::actingAs($user)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent('authenticator-app-actions', schema: 'form'));

        $secret = decrypt($profile->get('mountedActions.0.arguments.encrypted'))['secret'];

        $profile
            ->set('mountedActions.0.data.code', (new Google2FA)->getCurrentOtp($secret))
            ->set('mountedActions.0.data.password', 'password')
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertDontSee('No authenticator apps yet');
    }
}
