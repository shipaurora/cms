@php
    use CarlJanzell\FilamentPageBuilder\PageBuilder;

    $endpoint = $this->endpoint();
    $server = $this->serverKey();
    $token = $this->plainToken ?? 'YOUR_TOKEN';
    $tools = $this->tools();
@endphp

<x-filament-panels::page>
    @if ($this->plainToken)
        <x-filament::section icon="phosphor-check-circle" icon-color="success" :heading="__('Your new access token')" :description="__('Copy it now. For your security it will never be shown again.')">
            <div class="flex items-center gap-2" x-data="{ copied: false }">
                <code class="block min-w-0 flex-1 truncate rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900 ring-1 ring-gray-200 dark:bg-white/5 dark:text-white dark:ring-white/10">{{ $this->plainToken }}</code>
                <x-filament::button size="sm" color="gray" icon="phosphor-clipboard-bold" x-on:click="navigator.clipboard.writeText(@js($this->plainToken)); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                </x-filament::button>
            </div>
            <p class="mt-3 text-sm text-gray-500">{{ PageBuilder::lineWithMarkup('Use it as :header in clients that cannot sign in through the browser, such as scripts and CI.', ['header' => '<code>Authorization: Bearer …</code>']) }}</p>
        </x-filament::section>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-filament::section icon="phosphor-link" :heading="__('1. Connect')" :description="__('Add the server to your assistant. You sign in with your CMS account in the browser: no tokens to copy.')">
                <div x-data="{ tab: 'claude', copied: null }">
                    <x-filament::tabs class="mb-5">
                        <x-filament::tabs.item alpine-active="tab === 'claude'" x-on:click="tab = 'claude'" icon="phosphor-puzzle-piece-bold">{{ __('Claude Desktop & claude.ai') }}</x-filament::tabs.item>
                        <x-filament::tabs.item alpine-active="tab === 'code'" x-on:click="tab = 'code'" icon="phosphor-terminal-window-bold">{{ __('Claude Code') }}</x-filament::tabs.item>
                        <x-filament::tabs.item alpine-active="tab === 'editors'" x-on:click="tab = 'editors'" icon="phosphor-code-bold">{{ __('VS Code / Cursor') }}</x-filament::tabs.item>
                    </x-filament::tabs>

                    {{-- Claude Desktop and claude.ai: a custom connector, signed in with OAuth. --}}
                    <div x-show="tab === 'claude'" class="space-y-4">
                        <ol class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                            <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">1</span> <span>{{ PageBuilder::lineWithMarkup('In Claude, open :settings and choose :add.', ['settings' => '<strong>Settings → Connectors</strong>', 'add' => '<strong>Add custom connector</strong>']) }}</span></li>
                            <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">2</span> <span>{{ PageBuilder::lineWithMarkup('Name it :name and paste this URL:', ['name' => '<strong>'.e($this->serverName()).'</strong>']) }}</span></li>
                        </ol>
                        <div class="flex items-center gap-2">
                            <code class="block min-w-0 flex-1 truncate rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900 ring-1 ring-gray-200 dark:bg-white/5 dark:text-white dark:ring-white/10">{{ $endpoint }}</code>
                            <x-filament::button size="sm" color="gray" icon="phosphor-clipboard-bold" x-on:click="navigator.clipboard.writeText(@js($endpoint)); copied = 'url'; setTimeout(() => copied = null, 2000)">
                                <span x-text="copied === 'url' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                            </x-filament::button>
                        </div>
                        <ol start="3" class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                            <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">3</span> <span>{{ PageBuilder::lineWithMarkup('Click :connect, sign in to :app and approve. That\'s it.', ['connect' => '<strong>Connect</strong>', 'app' => e(config('aurora.name'))]) }}</span></li>
                        </ol>

                        @if ($this->endpointIsPrivate())
                            <x-filament::callout icon="phosphor-warning" color="warning" :heading="__('This address is only reachable from your computer')">
                                <x-slot name="description">
                                    {{ PageBuilder::lineWithMarkup('Claude connects to custom connectors from Anthropic\'s cloud, so it needs a public HTTPS address. In development, run :share and set :variable in :file to the URL it prints. Claude Code and code editors connect from your machine and work as is.', ['share' => '<code>herd share</code>', 'variable' => '<code>MCP_PUBLIC_URL</code>', 'file' => '<code>.env</code>']) }}
                                </x-slot>
                            </x-filament::callout>
                        @endif
                    </div>

                    {{-- Claude Code: HTTP transport, signs in with OAuth through the browser. --}}
                    <div x-show="tab === 'code'" x-cloak class="space-y-3">
                        <p class="text-sm text-gray-500">{{ PageBuilder::lineWithMarkup('Run this in your terminal, then type :command in Claude Code and choose :authenticate to sign in.', ['command' => '<code>/mcp</code>', 'authenticate' => '<strong>Authenticate</strong>']) }}</p>
                        @include('aurora::filament.pages.partials.snippet', ['id' => 'code', 'code' => "claude mcp add --transport http {$server} ".url('/mcp')])
                    </div>

                    {{-- Editors: HTTP server entry; they open the browser to sign in. --}}
                    <div x-show="tab === 'editors'" x-cloak class="space-y-3">
                        <p class="text-sm text-gray-500">{{ PageBuilder::lineWithMarkup('Add to :vscode (VS Code) or :cursor (Cursor). The editor opens your browser to sign in the first time.', ['vscode' => '<code>.vscode/mcp.json</code>', 'cursor' => '<code>~/.cursor/mcp.json</code>']) }}</p>
                        @include('aurora::filament.pages.partials.snippet', ['id' => 'editors', 'code' => json_encode(['servers' => [$server => ['type' => 'http', 'url' => url('/mcp')]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)])
                    </div>

                    <details class="group mt-6 rounded-xl border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
                        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-300">{{ __('Advanced: connect with an access token instead') }}</summary>
                        <div class="mt-3 space-y-3 text-gray-500">
                            <p>{{ __('For clients that cannot sign in through a browser (scripts, CI, older versions). Create a token with the button at the top of the page; it is filled in below.') }}</p>
                            @include('aurora::filament.pages.partials.snippet', ['id' => 'token-code', 'code' => "claude mcp add --transport http {$server} ".url('/mcp')." \\\n  --header \"Authorization: Bearer {$token}\""])
                            @include('aurora::filament.pages.partials.snippet', ['id' => 'token-desktop', 'code' => json_encode(['mcpServers' => [$server => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', url('/mcp'), '--header', "Authorization: Bearer {$token}"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)])
                        </div>
                    </details>
                </div>
            </x-filament::section>

            <x-filament::section icon="phosphor-chats" :heading="__('2. Ask')" :description="__('Some things to try once connected.')">
                <ul class="grid gap-3 md:grid-cols-2">
                    @foreach ([
                        'Create a landing page for our spring workshops, using the hero, a 3-card grid and a call to action. Keep it as a draft and send me the preview.',
                        'Rewrite the pricing FAQ to be friendlier, and add a question about refunds.',
                        'Add the Careers page to the main menu, after About us.',
                        'Which pages are waiting for review, and what changed on the homepage this week?',
                    ] as $example)
                        <li class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">“{{ __($example) }}”</li>
                    @endforeach
                </ul>
            </x-filament::section>

            {{ $this->table }}
        </div>

        <div class="space-y-6">
            <x-filament::section icon="phosphor-shield-check" :heading="__('What your assistant can do')" :description="trans_choice('As :name (:roles): :count tool.|As :name (:roles): :count tools.', count($tools), ['name' => auth()->user()->name, 'roles' => auth()->user()->roleLabels()->implode(', ')])">
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($tools as $tool)
                        <li class="flex items-start gap-3 py-2.5">
                            <x-filament::icon :icon="$tool['writes'] ? 'phosphor-note-pencil-bold' : 'phosphor-eye-bold'" @class(['mt-0.5 size-4 shrink-0', 'text-primary-500' => $tool['writes'], 'text-gray-400' => ! $tool['writes']]) />
                            <div class="min-w-0">
                                <p class="font-mono text-xs font-semibold text-gray-950 dark:text-white">{{ $tool['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ __($tool['description']) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>

            <x-filament::section icon="phosphor-clock" :heading="__('Your recent AI activity')" compact>
                @forelse ($this->recentActivity() as $activity)
                    <div class="flex items-start gap-3 py-2">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-success-500' => $activity->succeeded, 'bg-danger-500' => ! $activity->succeeded])></span>
                        <div class="min-w-0 text-sm">
                            <p class="truncate text-gray-950 dark:text-white">{{ $activity->summary ?? $activity->tool }}</p>
                            <p class="text-xs text-gray-500"><span class="font-mono">{{ $activity->tool }}</span> · {{ $activity->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('Nothing yet. Connect an assistant and ask it something.') }}</p>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
