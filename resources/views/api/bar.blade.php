{{-- The API explorers' bar: which explorer this is, a word when the API is off, and the way back. --}}
<style>
    .aurora-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1.25rem; background: #0f0b1f; color: #fff; font: 500 14px/1.4 system-ui, sans-serif; }
    .aurora-bar strong { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; }
    .aurora-bar img { width: 22px; height: 22px; border-radius: 6px; }
    .aurora-bar a { color: #c4b5fd; text-decoration: none; }
    .aurora-bar a:hover { text-decoration: underline; }
    .aurora-note { margin: 0; padding: 0.625rem 1.25rem; background: #fef3c7; color: #92400e; font: 14px/1.5 system-ui, sans-serif; }
</style>
<header class="aurora-bar">
    <strong><img src="{{ route('aurora.brand', 'brand-mark.svg') }}" alt=""> {{ $title }}</strong>
    <a href="{{ $settingsUrl }}">{{ __('Back to Settings') }}</a>
</header>
@unless ($enabled)
    <p class="aurora-note">{{ __('The :api is switched off, so trying it here answers “not found”. Switch it on under Settings › Integrations.', ['api' => $api]) }}</p>
@endunless
@isset ($hint)
    <p class="aurora-note" style="background: #ede9fe; color: #4c1d95">{{ $hint }}</p>
@endisset
