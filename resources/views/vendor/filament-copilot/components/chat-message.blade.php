@php
    $isUser = ($msg['role'] ?? '') === 'user';
    $isSystem = ($msg['role'] ?? '') === 'system';
    $isAssistant = ($msg['role'] ?? '') === 'assistant';
    $isTool = ($msg['role'] ?? '') === 'tool';
    $isToolCall = ($msg['role'] ?? '') === 'tool_call';
    $isToolResult = ($msg['role'] ?? '') === 'tool_result';
@endphp

@if ($isUser)
    @php
        $files = is_array($msg['files'] ?? null) ? $msg['files'] : (is_array($msg['images'] ?? null) ? $msg['images'] : []);
        $showText = filled($msg['content'] ?? null) && ! ($files !== [] && $msg['content'] === __('Use the attached files.'));
    @endphp
    <div class="flex items-start gap-2.5 justify-end">
        <div class="min-w-0 max-w-[85%] rounded-2xl rounded-tr-md px-3.5 py-2.5 bg-primary-600 text-white">
            @if ($files !== [])
                <div class="flex flex-wrap justify-end gap-1.5 {{ $showText ? 'mb-2' : '' }}">
                    @foreach ($files as $file)
                        @if (filled($file['url'] ?? null))
                            <img src="{{ $file['url'] }}" alt="{{ $file['title'] ?? '' }}"
                                class="rounded-lg object-cover ring-1 ring-white/30" style="width: 4rem; height: 4rem">
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-2 text-xs" style="height: 2.5rem; max-width: 12rem">
                                <x-filament::icon :icon="$file['icon'] ?? 'phosphor-file'" class="size-4 shrink-0" />
                                <span class="truncate">{{ $file['title'] ?? '' }}</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
            @if ($showText)
                <p class="text-sm whitespace-pre-wrap wrap-break-word leading-relaxed">{{ $msg['content'] }}</p>
            @endif
        </div>
        {{-- Your own avatar (or your initials): conversations belong to the person signed in. --}}
        <img src="{{ filament()->getUserAvatarUrl(auth()->user()) }}" alt="" loading="lazy"
            class="w-7 h-7 rounded-full object-cover shrink-0 mt-0.5 ring-1 ring-gray-950/10 dark:ring-white/10">
    </div>
@elseif($isToolCall || $isToolResult || $isTool)
    {{-- Collapsible tool call box --}}
    @php
        $toolName = $msg['tool_name'] ?? ($msg['name'] ?? 'Tool');
        $isSuccess = $msg['success'] ?? true;
        $hasResult = !empty($msg['result']);
        $hasError = !empty($msg['error']);
    @endphp
    <div class="flex items-start gap-2.5">
        <div class="w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 mt-0.5">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="w-4 h-4 text-gray-500 dark:text-gray-400" />
        </div>
        <div class="min-w-0 max-w-[85%] w-full" x-data="{ open: false }">
            <button @click="open = !open" type="button"
                class="flex items-center gap-2 px-3 py-2 w-full rounded-t-xl border transition-colors {{ $hasError ? 'bg-danger-50 dark:bg-danger-900/10 border-danger-200 dark:border-danger-800 hover:bg-danger-100 dark:hover:bg-danger-900/20' : 'bg-success-50 dark:bg-success-900/10 border-success-200 dark:border-success-800 hover:bg-success-100 dark:hover:bg-success-900/20' }}"
                :class="{ 'rounded-b-xl': !open }">
                <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 transition-transform duration-200" :class="{ 'rotate-90': open }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                @if ($hasError)
                    <x-filament::icon icon="heroicon-o-x-circle" class="w-3.5 h-3.5 text-danger-500" />
                @else
                    <x-filament::icon icon="heroicon-o-check-circle" class="w-3.5 h-3.5 text-success-500" />
                @endif
                <span class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate">{{ $toolName }}</span>
            </button>
            <div x-show="open" x-collapse
                class="px-3 py-2 border border-t-0 rounded-b-xl {{ $hasError ? 'bg-danger-50/50 dark:bg-danger-900/5 border-danger-200 dark:border-danger-800' : 'bg-success-50/50 dark:bg-success-900/5 border-success-200 dark:border-success-800' }}">
                @if (!empty($msg['arguments']))
                    <div class="mb-1">
                        <span
                            class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Arguments</span>
                        <pre
                            class="text-xs text-gray-600 dark:text-gray-400 font-mono whitespace-pre-wrap break-all mt-0.5 max-h-24 overflow-y-auto">{{ is_string($msg['arguments']) ? $msg['arguments'] : json_encode($msg['arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endif
                @if ($hasResult || !empty($msg['content']))
                    <div>
                        <span
                            class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Result</span>
                        <pre
                            class="text-xs text-gray-600 dark:text-gray-400 font-mono whitespace-pre-wrap break-all mt-0.5 max-h-24 overflow-y-auto">{{ $msg['result'] ?? $msg['content'] }}</pre>
                    </div>
                @endif
                @if ($hasError)
                    <div>
                        <span class="text-[10px] font-semibold text-danger-500 uppercase tracking-wider">Error</span>
                        <p class="text-xs text-danger-600 dark:text-danger-400 mt-0.5">{{ $msg['error'] }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
@elseif($isAssistant)
    {{-- Feedback thumbs. Only a persisted message carries an id, so the id gate
         doubles as the "is there something rateable to talk to" gate. --}}
    @php
        $messageId = $msg['id'] ?? null;
        $rating = $msg['rating'] ?? null;
        $showFeedback = $messageId !== null && config('filament-copilot.feedback.enabled', true);
    @endphp
    <div class="flex items-start gap-2.5">
        <div
            class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
            <x-filament::icon icon="phosphor-sparkle" class="w-4 h-4 text-primary-600 dark:text-primary-400" />
        </div>
        <div
            class="min-w-0 max-w-[85%] rounded-2xl rounded-tl-md px-3.5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            <div
                class="text-sm leading-relaxed prose prose-sm dark:prose-invert max-w-none wrap-break-word [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
                {{-- html_input=escape (the default) stops raw HTML/JS in model output
                     from executing in the panel: league/commonmark otherwise defaults
                     to html_input=ALLOW, so a prompt-injected <script> or event handler
                     in the assistant's reply would render live. See
                     config('filament-copilot.chat.html_input') for the opt-out. --}}
                {{-- Links show as buttons that open in a new tab; one that only shows its address gets a readable label. --}}
                {!! \Aurora\Support\CopilotContext::prepareLinks(\Illuminate\Support\Str::markdown($msg['content'] ?? '', [
                    'html_input' => config('filament-copilot.chat.html_input', 'escape'),
                    'allow_unsafe_links' => false,
                ], [new \League\CommonMark\Extension\Autolink\AutolinkExtension])) !!}
            </div>
        </div>
        @if ($showFeedback)
            @php
                $helpfulLabel = $rating === 'positive'
                    ? __('filament-copilot::filament-copilot.feedback_remove_helpful')
                    : __('filament-copilot::filament-copilot.feedback_helpful');
                $notHelpfulLabel = $rating === 'negative'
                    ? __('filament-copilot::filament-copilot.feedback_remove_not_helpful')
                    : __('filament-copilot::filament-copilot.feedback_not_helpful');
            @endphp
            <div class="flex items-center gap-0.5 shrink-0 self-end">
                <button type="button" wire:click="submitRating('{{ $messageId }}', 'positive')"
                    wire:loading.attr="disabled" wire:target="submitRating"
                    class="flex items-center justify-center w-7 h-7 rounded-md transition duration-75 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 {{ $rating === 'positive' ? 'text-success-600 dark:text-success-400' : 'text-gray-400 dark:text-gray-400' }}"
                    title="{{ $helpfulLabel }}" aria-label="{{ $helpfulLabel }}"
                    aria-pressed="{{ $rating === 'positive' ? 'true' : 'false' }}">
                    <x-filament::icon :icon="$rating === 'positive' ? 'heroicon-s-hand-thumb-up' : 'heroicon-o-hand-thumb-up'"
                        class="w-4 h-4" />
                </button>
                <button type="button" wire:click="submitRating('{{ $messageId }}', 'negative')"
                    wire:loading.attr="disabled" wire:target="submitRating"
                    class="flex items-center justify-center w-7 h-7 rounded-md transition duration-75 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 {{ $rating === 'negative' ? 'text-danger-600 dark:text-danger-400' : 'text-gray-400 dark:text-gray-400' }}"
                    title="{{ $notHelpfulLabel }}" aria-label="{{ $notHelpfulLabel }}"
                    aria-pressed="{{ $rating === 'negative' ? 'true' : 'false' }}">
                    <x-filament::icon :icon="$rating === 'negative' ? 'heroicon-s-hand-thumb-down' : 'heroicon-o-hand-thumb-down'"
                        class="w-4 h-4" />
                </button>
            </div>
        @endif
    </div>
@elseif($isSystem)
    <div class="flex justify-center px-4">
        <div
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-warning-50 dark:bg-warning-900/20 text-warning-700 dark:text-warning-300 max-w-full">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 shrink-0" />
            <span class="text-xs wrap-anywherebreak-word">{{ $msg['content'] }}</span>
        </div>
    </div>
@endif
