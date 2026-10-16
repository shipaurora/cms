@php
    /** @var \Illuminate\Support\Collection<int, \Aurora\Models\AuthenticatorApp> $apps */
    $actions = collect($getChildSchema()->getComponents())->keyBy(fn ($component) => $component->getKey(isAbsolute: false));
@endphp

{{-- Your authenticator apps, listed like your passkeys (livewire/manage-passkeys). --}}
@if ($apps->isNotEmpty())
    <ul class="divide-y divide-gray-950/5 overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:divide-white/10 dark:ring-white/10">
        @foreach ($apps as $app)
            <li wire:key="authenticator-app-{{ $app->id }}" class="flex items-center gap-3 px-4 py-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                    <x-filament::icon icon="phosphor-device-mobile" class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $app->name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Added :date', ['date' => $app->created_at->translatedFormat('j M Y')]) }}
                        · {{ $app->last_used_at ? __('Last used :time', ['time' => $app->last_used_at->diffForHumans()]) : __('Not used yet') }}
                    </p>
                </div>
                {{ $actions->get("authenticator-app-{$app->id}") }}
            </li>
        @endforeach
    </ul>
@else
    <div class="flex items-center gap-3 rounded-xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
        <x-filament::icon icon="phosphor-device-mobile" class="size-5 shrink-0 text-gray-400" />
        {{ __('No authenticator apps yet. Add one, like Google Authenticator, 1Password or Authy, to sign in with a code from your phone as well as your password.') }}
    </div>
@endif
