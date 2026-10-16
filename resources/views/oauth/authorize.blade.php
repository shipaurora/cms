{{--
    Passport's OAuth consent screen, shown when an MCP client (a Claude custom connector,
    Claude Code, an editor) asks to act on someone's behalf. It is rendered inside the
    admin panel's simple layout, the one the login page uses, so it looks like the CMS.

    Variables from Passport: $client, $user, $scopes, $request, $authToken.
--}}
@php
    filament()->setCurrentPanel('admin');
    filament()->bootCurrentPanel();

    $redirectHost = parse_url((string) collect($client->redirect_uris)->first(), PHP_URL_HOST)
        ?: parse_url((string) collect($client->redirect_uris)->first(), PHP_URL_SCHEME);
    $knownClient = in_array($redirectHost, ['claude.ai', 'claude.com', 'localhost', '127.0.0.1'], true) || in_array($redirectHost, ['claude', 'cursor', 'vscode'], true);
    $summary = \Aurora\Mcp\Capabilities::summary();
    $tools = count(\Aurora\Mcp\Capabilities::tools());
@endphp

<x-filament-panels::layout.simple>
    <section class="fi-simple-page">
        <header class="fi-simple-header">
            <x-filament-panels::logo />

            <h1 class="fi-simple-header-heading">
                Connect {{ $client->name }}
            </h1>

            <p class="fi-simple-header-subheading">
                {{ $client->name }} wants to work in {{ config('app.name') }} on your behalf.
            </p>
        </header>

        <div class="flex flex-col gap-y-6">
            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <x-filament-panels::avatar.user :user="$user" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $user->name }}</p>
                        <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $user->email }} · {{ $user->roleLabels()->implode(', ') }}</p>
                    </div>
                    <form method="POST" action="{{ filament()->getLogoutUrl() }}">
                        @csrf
                        <x-filament::link tag="button" type="submit" size="sm" color="gray">{{ __('Not you?') }}</x-filament::link>
                    </form>
                </div>
            </x-filament::section>

            <x-filament::section
                heading="It will be able to"
                :description="'Exactly what your own account can do: '.$tools.' tools, nothing more.'"
                icon="phosphor-shield-check"
                compact
            >
                <ul class="space-y-2.5">
                    @foreach ($summary as $item)
                        <li @class(['flex items-start gap-3 text-sm', 'text-gray-950 dark:text-white' => $item['allowed'], 'text-gray-400 line-through dark:text-gray-500' => ! $item['allowed']])>
                            <x-filament::icon
                                :icon="$item['allowed'] ? 'phosphor-check-circle-bold' : 'phosphor-x-circle-bold'"
                                @class(['size-5 shrink-0', 'text-success-500' => $item['allowed'], 'text-gray-300 dark:text-gray-600' => ! $item['allowed']])
                            />
                            <span>{{ $item['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>

            <x-filament::callout
                :icon="$knownClient ? 'phosphor-info' : 'phosphor-warning'"
                :color="$knownClient ? 'gray' : 'warning'"
                :heading="'Signs in via '.$redirectHost"
            >
                <x-slot name="description">
                    {{ __('Every change is saved in the page history under your name. You can disconnect at any time from') }}
                    <strong>{{ __('AI → Connect AI apps') }}</strong>.
                    @unless ($knownClient)
                        Only continue if you started this connection yourself.
                    @endunless
                </x-slot>
            </x-filament::callout>

            <div class="flex flex-col gap-3 sm:flex-row-reverse">
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-filament::button type="submit" size="lg" icon="phosphor-check-bold" class="w-full">
                        {{ __('Allow access') }}
                    </x-filament::button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-filament::button type="submit" size="lg" color="gray" outlined class="w-full">
                        {{ __('Cancel') }}
                    </x-filament::button>
                </form>
            </div>
        </div>
    </section>
</x-filament-panels::layout.simple>
