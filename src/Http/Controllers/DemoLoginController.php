<?php

namespace Aurora\Http\Controllers;

use Aurora\Database\Seeders\Demo\UserSeeder;
use Aurora\Models\User;
use Aurora\Support\SafeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click sign-in as a demo account, so a presenter can switch roles mid-demo.
 *
 * Only registered when AURORA_DEMO_LOGIN=true (see routes/web.php). Never enable in production.
 */
class DemoLoginController
{
    public function __invoke(Request $request, string $email): RedirectResponse
    {
        // Only the seeded demo accounts, and only while they're active.
        abort_unless(in_array($email, array_column(UserSeeder::USERS, 1), true), 404);
        $user = User::query()->where('email', $email)->where('is_active', true)->firstOrFail();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $to = $request->string('to')->toString();

        return redirect(SafeUrl::isSafe($to) && str_starts_with($to, '/') ? $to : '/admin');
    }
}
