{{--
    Overrides the impersonation plugin's banner so it wears the panel's theme: the primary
    colour (Filament's --primary-* variables) in the admin, the site's brand colour on the
    public site, where Filament's stylesheet is not loaded.
--}}
@props(['display' => null])

@php
    use Filament\Facades\Filament;
    use STS\FilamentImpersonate\Facades\Impersonation;

    $impersonatorGuard = Impersonation::getImpersonatorGuardUsingName();
    $panelGuard = Filament::getAuthGuard();
    $show = Impersonation::isImpersonating() && $panelGuard && $impersonatorGuard === $panelGuard;
@endphp

@if ($show)
    @php
        $user = Filament::auth()->user();
        $display ??= $user ? Filament::getUserName($user) : __('(No user found)');
        $roles = $user?->roleLabels()->implode(', ');
    @endphp

    <style>
        :root { --impersonate-banner-height: 2.75rem; }
        body { margin-top: var(--impersonate-banner-height); }
        body.fi-body { min-height: calc(100dvh - var(--impersonate-banner-height)); }
        body.fi-body .fi-main-ctn { min-height: calc(100dvh - var(--impersonate-banner-height)); }
        body.fi-body-has-topbar .fi-main-ctn { min-height: calc(100dvh - 4rem - var(--impersonate-banner-height)); }
        body.fi-body .fi-sidebar { top: var(--impersonate-banner-height); height: calc(100dvh - var(--impersonate-banner-height)); }
        body.fi-body-has-topbar .fi-sidebar { top: calc(4rem + var(--impersonate-banner-height)); height: calc(100dvh - 4rem - var(--impersonate-banner-height)); }
        .fi-topbar-ctn { top: var(--impersonate-banner-height); }
        .fi-modal.fi-modal-slide-over > .fi-modal-window-ctn > .fi-modal-window { padding-top: var(--impersonate-banner-height); }

        #impersonate-banner {
            position: fixed; inset: 0 0 auto; z-index: 45; height: var(--impersonate-banner-height);
            display: flex; align-items: center; justify-content: center; gap: 0.875rem;
            padding: 0 1rem; font-size: 0.875rem; line-height: 1.25rem; color: #fff;
            background: var(--primary-600, var(--color-brand-600, #7c3aed));
            box-shadow: inset 0 -1px 0 rgb(0 0 0 / 0.12);
        }
        #impersonate-banner .impersonate-icon { width: 1.125rem; height: 1.125rem; opacity: 0.85; flex-shrink: 0; }
        #impersonate-banner .impersonate-role { opacity: 0.75; }
        #impersonate-banner a {
            display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.3rem 0.75rem;
            border-radius: 0.5rem; font-weight: 600; color: var(--primary-700, var(--color-brand-700, #6d28d9));
            background: #fff; transition: background-color 0.15s;
        }
        #impersonate-banner a:hover { background: var(--primary-50, var(--color-brand-50, #f5f3ff)); }
        #impersonate-banner a svg { width: 1rem; height: 1rem; }
        @media (max-width: 640px) { #impersonate-banner .impersonate-role { display: none; } }
        @media print { body { margin-top: 0; } #impersonate-banner { display: none; } }
    </style>

    <div id="impersonate-banner" role="status">
        {{ svg('phosphor-eye', 'impersonate-icon') }}
        <span>
            {{ __('filament-impersonate::banner.impersonating') }} <strong>{{ $display }}</strong>
            @if ($roles)<span class="impersonate-role"> · {{ $roles }}</span>@endif
        </span>
        <a href="{{ route('filament-impersonate.leave') }}">
            {{ svg('phosphor-arrow-u-up-left-bold') }}
            {{ __('filament-impersonate::banner.leave') }}
        </a>
    </div>
@endif
