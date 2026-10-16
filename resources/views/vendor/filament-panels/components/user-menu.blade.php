@props([
    'position' => null,
])

{{--
    CMS override of Filament's user menu: a header card (avatar, name, email, role) that
    opens your profile, the menu items from AdminPanelProvider::userMenuItems(), a labelled
    appearance switch, and sign out on its own at the bottom.
--}}

@php
    use Filament\Actions\Action;
    use Filament\Enums\UserMenuPosition;
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $user = filament()->auth()->user();
    $userName = filament()->getUserName($user);
    $items = collect($this->getUserMenuItems());
    $logout = $items->pull('logout');
    $profile = $items->get('profile');
    $role = method_exists($user, 'roleLabels') ? $user->roleLabels()->implode(', ') : null;
    $position ??= filament()->getUserMenuPosition();
    $hasThemeSwitcher = filament()->hasDarkMode() && (! filament()->hasDarkModeForced()) && filament()->hasThemeSwitcher();
@endphp

{{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE) }}

<x-filament::dropdown
    :placement="($position === UserMenuPosition::Topbar) ? 'bottom-end' : 'top-end'"
    :teleport="$position === UserMenuPosition::Topbar"
    width="xs"
    :attributes="
        \Filament\Support\prepare_inherited_attributes($attributes)
            ->class(['fi-user-menu', 'cms-user-menu'])
    "
>
    <x-slot name="trigger">
        <button
            aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
            type="button"
            class="fi-user-menu-trigger"
        >
            <x-filament-panels::avatar.user :user="$user" loading="lazy" />
        </button>
    </x-slot>

    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

    <a href="{{ $profile?->getUrl() }}" class="cms-user-menu-card">
        <x-filament-panels::avatar.user :user="$user" size="lg" />
        <span class="min-w-0 flex-1">
            <span class="cms-user-menu-name">{{ $userName }}</span>
            <span class="cms-user-menu-email">{{ $user->email }}</span>
            @if (filled($role))
                <span class="cms-user-menu-role">{{ $role }}</span>
            @endif
        </span>
    </a>

    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}

    @if ($items->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($items as $item)
                {{ $item }}
            @endforeach
        </x-filament::dropdown.list>
    @endif

    @if ($hasThemeSwitcher)
        <div
            x-data="{ theme: null }"
            x-init="
                $watch('theme', () => $dispatch('theme-changed', theme))
                theme = localStorage.getItem('filament:theme:mode') || @js(filament()->getDefaultThemeMode()->value)
            "
            class="cms-user-menu-appearance"
        >
            <p class="cms-user-menu-label">{{ __('Appearance') }}</p>
            <div class="cms-user-menu-segment" role="radiogroup" aria-label="{{ __('Appearance') }}">
                @foreach (['light' => ['phosphor-sun-bold', 'Light'], 'dark' => ['phosphor-moon-bold', 'Dark'], 'system' => ['phosphor-desktop-bold', 'System']] as $mode => [$icon, $label])
                    <button
                        type="button"
                        role="radio"
                        x-on:click="theme = @js($mode)"
                        x-bind:aria-checked="theme === @js($mode) ? 'true' : 'false'"
                        x-bind:class="{ 'cms-active': theme === @js($mode) }"
                    >
                        <x-filament::icon :icon="$icon" class="size-4" />
                        {{ __($label) }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($logout)
        <x-filament::dropdown.list>
            {{ $logout }}
        </x-filament::dropdown.list>
    @endif
</x-filament::dropdown>

{{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_AFTER) }}
