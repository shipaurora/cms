<?php

namespace Aurora\Tests\Feature;

use Aurora\Tests\TestCase;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_offers_to_reset_a_forgotten_password(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee(Filament::getPanel('admin')->getRequestPasswordResetUrl(), escape: false);
    }

    public function test_a_forgotten_password_is_reset_from_a_link_sent_by_email(): void
    {
        Notification::fake();
        $editor = $this->userWithRole('Editor', ['email' => 'kari@example.com']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'kari@example.com'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($editor, ResetPasswordNotification::class);

        Livewire::test(ResetPassword::class, ['email' => 'kari@example.com', 'token' => Password::broker()->createToken($editor)])
            ->fillForm(['password' => 'a-new-secret-1', 'passwordConfirmation' => 'a-new-secret-1'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-new-secret-1', $editor->fresh()->password));
    }
}
