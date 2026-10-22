<?php

namespace Aurora\Tests\Feature;

use Aurora\Database\Seeders\RolesAndPermissionsSeeder;
use Aurora\Filament\Pages\Auth\AcceptInvitation;
use Aurora\Filament\Resources\Users\Pages\ListUsers;
use Aurora\Filament\Resources\Users\Widgets\PendingInvitations;
use Aurora\Models\Invitation;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Notifications\InvitedToCms;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_can_be_invited_with_a_role(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $existing = User::factory()->create(['email' => 'already@example.com']);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callAction('invite', [
                'emails' => ['new@example.com', 'Second@Example.com', 'already@example.com'],
                'role_id' => Role::findByName('Editor')->id,
                'message' => 'Welcome!',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Sent 2 invitations');

        $this->assertSame(['new@example.com', 'second@example.com'], Invitation::query()->orderBy('email')->pluck('email')->all());
        Notification::assertSentTo(new AnonymousNotifiable, InvitedToCms::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com');
        Notification::assertSentTimes(InvitedToCms::class, 2);
    }

    public function test_only_super_admins_can_invite_super_admins(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::findOrCreate('People manager', 'web')->givePermissionTo(['view-any User', 'create User']);
        $manager = tap(User::factory()->create())->assignRole('People manager');

        Livewire::actingAs($manager)
            ->test(ListUsers::class)
            ->callAction('invite', ['emails' => ['boss@example.com'], 'role_id' => Role::findByName(User::SUPER_ADMIN)->id])
            ->assertHasActionErrors(['role_id']);

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_an_invitee_joins_with_their_own_name_and_password(): void
    {
        $invitation = $this->invitation();

        $this->get($invitation->url())->assertOk()->assertSee('Join')->assertSee('Author');

        Livewire::test(AcceptInvitation::class, ['invitation' => $invitation])
            ->fillForm(['name' => 'Nora New', 'password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1'])
            ->call('accept')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/main');

        $user = User::query()->where('email', 'invitee@example.com')->sole();
        $this->assertSame('Nora New', $user->name);
        $this->assertTrue($user->hasRole('Author'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($invitation->refresh()->accepted_at);
    }

    public function test_links_are_single_use_expiring_and_tamper_proof(): void
    {
        $invitation = $this->invitation();
        $url = $invitation->url();

        $this->get($url.'x')->assertForbidden();

        $invitation->forceFill(['accepted_at' => now()])->save();
        $this->get($url)->assertOk()->assertSee('can no longer be used');

        Livewire::test(AcceptInvitation::class, ['invitation' => $invitation])
            ->call('accept')
            ->assertStatus(410);

        $this->travel(Invitation::VALID_FOR_DAYS + 1)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_pending_invitations_can_be_resent_and_revoked(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $invitation = $this->invitation();

        Livewire::actingAs($admin)
            ->test(PendingInvitations::class)
            ->assertCanSeeTableRecords([$invitation])
            ->callTableAction('resend', $invitation)
            ->callTableAction('delete', $invitation);

        Notification::assertSentTimes(InvitedToCms::class, 1);
        $this->assertModelMissing($invitation);
    }

    public function test_the_invitation_email_wears_the_cms_look_and_escapes_what_people_typed(): void
    {
        $invitation = $this->invitation();
        $invitation->update([
            'invited_by_id' => User::factory()->create(['name' => 'Tom & <b>Jerry</b>'])->id,
            'message' => 'Welcome <script>alert(1)</script>',
        ]);

        $html = (string) (new InvitedToCms($invitation->fresh()))->toMail($invitation)->render();

        $this->assertStringContainsString(config('aurora.name'), $html);
        $this->assertStringContainsString('brand-mark.png', $html);
        $this->assertStringContainsString('background-color: #7c3aed', $html, 'The CMS theme, inlined.');
        $this->assertStringContainsString('Tom &amp; &lt;b&gt;Jerry&lt;/b&gt; invited you', $html);
        $this->assertStringContainsString('<blockquote', $html, 'The personal message is quoted.');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html, 'Escaped once, not twice.');
        $this->assertStringNotContainsString('Regards', $html, 'No sign-off.');
    }

    protected function invitation(): Invitation
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return Invitation::query()->create([
            'email' => 'invitee@example.com',
            'role_id' => Role::findByName('Author')->id,
            'expires_at' => now()->addDays(Invitation::VALID_FOR_DAYS),
        ]);
    }
}
