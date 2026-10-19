<?php

namespace Aurora\Http\Controllers;

use Aurora\Models\User;
use Aurora\Support\Demo;
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
        // Only the website's demo accounts (Demo::register()), and only while they're active.
        abort_unless(Demo::isAccount($email), 404);
        $user = User::query()->where('email', $email)->where('is_active', true)->firstOrFail();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $to = $request->string('to')->toString();

        return redirect(SafeUrl::isSafe($to) && str_starts_with($to, '/') ? $to : '/admin');
    }
}
