@php
    /** @var \Aurora\Models\Page $page */
    $error = session('errors')?->getBag('page_password')->first('password');
@endphp
{{-- What a password-protected page shows until the visitor types its password. A plain page
     to start from: make it the site's own with `php artisan vendor:publish --tag=aurora-views`
     (resources/views/vendor/aurora/page-password.blade.php). The form posts the password to
     route('pages.unlock', $page); a wrong one comes back in the 'page_password' error bag. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $page->seoTitle() }}</title>
    @include('aurora::partials.brand-palette')
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; box-sizing: border-box; font-family: system-ui, sans-serif; background: #f4f4f5; color: #18181b; }
        .aurora-page-password { width: 100%; max-width: 24rem; padding: 2rem; border-radius: 1rem; background: #fff; box-shadow: 0 10px 30px rgb(0 0 0 / 0.06); }
        .aurora-page-password h1 { margin: 0 0 0.5rem; font-size: 1.375rem; }
        .aurora-page-password p { margin: 0 0 1.5rem; color: #52525b; }
        .aurora-page-password label { display: block; margin-bottom: 0.375rem; font-size: 0.875rem; font-weight: 600; }
        .aurora-page-password input { display: block; width: 100%; box-sizing: border-box; padding: 0.75rem 1rem; border: 1px solid #d4d4d8; border-radius: 0.75rem; font: inherit; }
        .aurora-page-password input[aria-invalid] { border-color: #e11d48; }
        .aurora-page-password .aurora-page-password-error { margin: 0.375rem 0 0; color: #e11d48; font-size: 0.875rem; }
        .aurora-page-password button { width: 100%; margin-top: 1rem; padding: 0.75rem 1rem; border: 0; border-radius: 999px; background: var(--color-brand-600, #4f46e5); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <main class="aurora-page-password">
        <h1>{{ __('This page is password protected') }}</h1>
        <p>{{ __('Enter the password to see it.') }}</p>

        <form method="POST" action="{{ route('pages.unlock', $page) }}">
            @csrf
            <label for="page-password">{{ __('Password') }}</label>
            <input
                id="page-password"
                type="password"
                name="password"
                required
                autofocus
                autocomplete="current-password"
                @if ($error) aria-invalid="true" aria-describedby="page-password-error" @endif
            >
            @if ($error)
                <p id="page-password-error" class="aurora-page-password-error" role="alert">{{ $error }}</p>
            @endif
            <button type="submit">{{ __('Show the page') }}</button>
        </form>
    </main>
</body>
</html>
