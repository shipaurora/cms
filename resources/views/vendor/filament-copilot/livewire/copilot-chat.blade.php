<div x-data="{
    open: @entangle('isOpen'),
    sidebarOpen: false,
    sidebarLoading: false,
    conversationLoading: false,
    streamingEnabled: true,
    isStreaming: false,
    droppingImages: false,
    streamedContent: '',
    pendingComplete: false,
    pendingApprovals: [],
    toolCalls: [],
    _cleanups: [],
    _abortController: null,
    _pendingNavigateUrl: null,
    init() {
        const wireComponentId = $wire.__instance.id;
        this.$nextTick(() => this.watchConversation());

        this.$watch('open', value => {
            if (value) {
                // Opening (or resuming) a chat starts at the latest message, once the panel
                // has finished appearing and the messages have their height.
                this.followConversation = true;
                this.$nextTick(() => {
                    this.scrollToBottom();
                    this.focusInput();
                });
                requestAnimationFrame(() => this.scrollToBottom());
                setTimeout(() => this.scrollToBottom(), 350);
            } else {
                this.sidebarOpen = false;
            }
        });

        // SPA-safe: store cleanup functions for all global hooks
        this._cleanups.push(
            Livewire.hook('morph.updated', ({ el, component }) => {
                // Only react to morphs from our own Livewire component
                if (component.id !== wireComponentId) return;
                if (el.id === 'copilot-messages') {
                    if (this.pendingComplete) {
                        // Immediately hide the overlay via style before Alpine reactivity
                        // to guarantee zero-flash when server message is already in DOM
                        const overlay = this.$el.querySelector('[data-streaming-overlay]');
                        if (overlay) overlay.style.display = 'none';

                        this.pendingComplete = false;
                        this.streamedContent = '';
                        this.toolCalls = [];
                        this.isStreaming = false;
                    }
                    this.conversationLoading = false;
                    this.$nextTick(() => this.scrollToBottom());
                }
            })
        );

        this._cleanups.push(
            Livewire.on('copilot-send-stream', (data) => {
                this.startStreaming(data[0] || data);
            })
        );

        // A new conversation, or another one from the history: start at its latest message,
        // ready to type.
        this._cleanups.push(
            Livewire.on('copilot-conversation-changed', () => {
                this.followConversation = true;
                this.$nextTick(() => {
                    this.scrollToBottom();
                    this.focusInput();
                });
                setTimeout(() => this.scrollToBottom(), 150);
            })
        );

        this._cleanups.push(
            Livewire.on('copilot-load-conversation', () => {
                this.conversationLoading = true;
                this.sidebarOpen = false;
            })
        );
    },
    destroy() {
        // SPA-safe: deregister all global Livewire hooks/listeners
        this._cleanups.forEach(fn => fn());
        this._cleanups = [];

        // Abort any in-flight SSE stream
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
    },
    // Follow the conversation: new messages, streamed words and re-renders scroll to the end,
    // unless the person has scrolled up to read something earlier.
    followConversation: true,
    // The message list sits inside the chat bubble's own Alpine component, where this
    // component's $refs can't reach, so it is found by its id.
    messagesElement() {
        return document.getElementById('copilot-messages');
    },
    scrollToBottom() {
        const container = this.messagesElement();
        if (container) {
            // Instant: the list scrolls smoothly, and a smooth scroll gets cut short when the
            // panel opens or the conversation re-renders, leaving it near the top.
            container.scrollTo({ top: container.scrollHeight, behavior: 'instant' });
        }
    },
    watchConversation() {
        const container = this.messagesElement();
        if (! container || container._cmsFollowing) return;
        container._cmsFollowing = true;

        container.addEventListener('scroll', () => {
            this.followConversation = container.scrollHeight - container.scrollTop - container.clientHeight < 80;
        }, { passive: true });

        const observer = new MutationObserver(() => {
            if (this.followConversation) requestAnimationFrame(() => this.scrollToBottom());
        });
        observer.observe(container, { childList: true, subtree: true, characterData: true });
        this._cleanups.push(() => {
            observer.disconnect();
            container._cmsFollowing = false;
        });
    },
    focusInput() {
        const ta = this.$el.querySelector('textarea');
        if (ta) ta.focus();
    },
    handleKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!this.isStreaming && !$wire.isLoading) {
                $wire.sendMessage();
            }
        }
    },
    autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    },
    toggleSidebar() {
        this.sidebarOpen = !this.sidebarOpen;
        if (this.sidebarOpen) {
            this.sidebarLoading = true;
            $wire.dispatch('copilot-refresh-sidebar');
            setTimeout(() => { this.sidebarLoading = false; }, 600);
        }
    },
    async startStreaming(params) {
        // A new question: follow the answer as it arrives.
        this.followConversation = true;
        this.$nextTick(() => this.scrollToBottom());

        // Abort any previous in-flight stream
        if (this._abortController) {
            this._abortController.abort();
        }
        this._abortController = new AbortController();

        this.isStreaming = true;
        this.streamedContent = '';
        this.toolCalls = [];
        this.pendingApprovals = [];
        this.pendingComplete = false;

        try {
            const response = await fetch(params.streamUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-CSRF-TOKEN': params.csrfToken,
                },
                    body: JSON.stringify({
                    message: params.message,
                    images: params.images || undefined,
                    conversation_id: params.conversationId,
                    panel_id: params.panelId,
                    decisions: params.decisions || undefined,
                }),
                signal: this._abortController.signal,
            });

            if (!response.ok) {
                throw new Error('Stream request failed: ' + response.status);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let newConversationId = null;
            let assistantMessageId = null;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                let currentEvent = null;

                for (const line of lines) {
                    if (line.startsWith('event: ')) {
                        currentEvent = line.substring(7).trim();
                    } else if (line.startsWith('data: ') && currentEvent) {
                        try {
                            const data = JSON.parse(line.substring(6));

                            switch (currentEvent) {
                                case 'conversation':
                                    newConversationId = data.id;
                                    // The editor may reload before this reply finishes. Remember the
                                    // conversation now, so the reloaded chat still has it.
                                    if (! $wire.conversationId && data.id) {
                                        $wire.rememberConversation(data.id);
                                    }
                                    break;
                                case 'chunk':
                                    this.streamedContent += data.text;
                                    this.$nextTick(() => this.scrollToBottom());
                                    break;
                                case 'tool_call':
                                    this.toolCalls.push({
                                        id: data.tool_id,
                                        name: data.tool_name,
                                        arguments: data.arguments,
                                        status: 'running',
                                        result: null,
                                    });
                                    this.$nextTick(() => this.scrollToBottom());
                                    break;
                                case 'tool_result': {
                                    const idx = this.toolCalls.findIndex(t =>
                                        (data.tool_id && t.id === data.tool_id) ||
                                        (!data.tool_id && t.name === data.tool_name)
                                    );
                                    if (idx !== -1) {
                                        this.toolCalls[idx].status = data.success ? 'done' : 'error';
                                        this.toolCalls[idx].result = data.result;
                                        this.toolCalls[idx].error = data.error;
                                    }
                                }
                                this.$nextTick(() => this.scrollToBottom());
                                break;
                                case 'tool_approval_request':
                                this.pendingApprovals = data.approvals || [];
                                this.isStreaming = false;
                                this.$nextTick(() => this.scrollToBottom());
                                break;
                                case 'navigate':
                                    // Queue navigation URL to execute after stream completes
                                    this._pendingNavigateUrl = data.url;
                                    break;
                                case 'error':
                                    $wire.dispatchSelf('copilot-stream-error', { error: data.message });
                                    this.isStreaming = false;
                                    this.streamedContent = '';
                                    this.toolCalls = [];
                                    break;
                                case 'done':
                                    // Absent on error streams, and on servers
                                    // running an older package release.
                                    assistantMessageId = data.message_id || null;
                                    if (data.approvals) this.pendingApprovals = data.approvals;
                                    break;
                            }
                        } catch (e) {
                            // Skip malformed JSON
                        }

                        currentEvent = null;
                    }
                }
            }

            this._abortController = null;

            if (this.pendingApprovals.length) {
                this.isStreaming = false;
                return;
            } else if (this.streamedContent || this.toolCalls.length) {
                this.pendingComplete = true;
                this.isStreaming = false;

                // Keep streamedContent visible — overlay stays on screen until the Livewire morph replaces it.
                // Wait until the conversation is stored, then tell the editor it may reload.
                try {
                    await $wire.handleStreamComplete(
                        this.streamedContent,
                        newConversationId,
                        this.toolCalls.length ? JSON.parse(JSON.stringify(this.toolCalls)) : null,
                        assistantMessageId,
                    );
                } finally {
                    window.dispatchEvent(new CustomEvent('cms-copilot-persisted'));
                }

                // Execute pending navigation after stream completes
                if (this._pendingNavigateUrl) {
                    const url = this._pendingNavigateUrl;
                    this._pendingNavigateUrl = null;
                    // Small delay to let the completion dispatch process first
                    setTimeout(() => {
                        Livewire.navigate(url);
                    }, 300);
                }
            } else {
                // Execute pending navigation even if no streamed content
                if (this._pendingNavigateUrl) {
                    const url = this._pendingNavigateUrl;
                    this._pendingNavigateUrl = null;
                    setTimeout(() => {
                        Livewire.navigate(url);
                    }, 300);
                }
                this.isStreaming = false;
                this.toolCalls = [];
            }
        } catch (error) {
            this._abortController = null;

            // Silently handle intentional aborts (navigation, new stream, destroy)
            if (error.name === 'AbortError') {
                this.isStreaming = false;
                this.streamedContent = '';
                this.toolCalls = [];
                return;
            }

            $wire.dispatchSelf('copilot-stream-error', { error: error.message });
            this.isStreaming = false;
            this.streamedContent = '';
            this.toolCalls = [];
        }
    },
    submitApprovals(action) {
        const decisions = {};
        this.pendingApprovals.forEach(approval => {
            decisions[approval.id] = { action };
        });
        this.startStreaming({
            message: null,
            decisions,
            conversationId: this.conversationId,
            panelId: @js(\Filament\Facades\Filament::getCurrentPanel()?->getId()),
            streamUrl: @js(route('filament-copilot.stream')),
            csrfToken: @js(csrf_token()),
        });
    },
    // A drag only counts when it is carrying files. Text and links pass through.
    draggedFiles(event) {
        return Array.from(event.dataTransfer?.types || []).includes('Files');
    },
    dragImagesOver(event) {
        if (! this.draggedFiles(event)) return;
        event.preventDefault();
        if (this.isStreaming || this.$wire.isLoading) {
            event.dataTransfer.dropEffect = 'none';
            return;
        }
        event.dataTransfer.dropEffect = 'copy';
        this.droppingImages = true;
    },
    dragImagesLeave(event) {
        if (event.relatedTarget && event.currentTarget.contains(event.relatedTarget)) return;
        this.droppingImages = false;
    },
    dropImages(event) {
        if (! this.draggedFiles(event)) return;
        event.preventDefault();
        this.droppingImages = false;
        if (this.isStreaming || this.$wire.isLoading) return;
        const input = this.$refs.imageUploads;
        if (! input || input.disabled || event.dataTransfer.files.length === 0) return;
        input.files = event.dataTransfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}" x-cloak @copilot-open.window="open = true" @copilot-close-sidebar.window="sidebarOpen = false"
    @copilot-load-conversation.window="sidebarOpen = false" @keydown.escape.window="if(open) open = false">

    {{-- CMS override: a chat bubble in the corner instead of the package's slide-over.
         Clicking anywhere outside the bubble and the chat closes it. --}}
    <div x-on:click.outside="if (open && $event.target.isConnected && ! $event.target.closest('.fi-modal, .fi-no-notification')) open = false"
        x-data="{
            init() {
                // Reopen the chat after a reload if it was open (per browser tab).
                try { this.open = sessionStorage.getItem('cms-assistant-open') === '1' } catch (error) {}
                this.$watch('open', (value) => {
                    try { sessionStorage.setItem('cms-assistant-open', value ? '1' : '0') } catch (error) {}
                })
            },
        }">
    <button type="button" x-on:click="open = ! open"
        class="fixed bottom-5 end-5 z-50 grid size-14 place-items-center rounded-full bg-primary-600 text-white shadow-lg shadow-primary-600/30 ring-4 ring-white transition hover:scale-105 hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-primary-300 dark:ring-gray-950"
        x-bind:aria-expanded="open" aria-controls="cms-assistant"
        title="{{ __('filament-copilot::filament-copilot.open_copilot') }} (Ctrl+Shift+K)">
        {{-- Both icons share one grid cell and cross-fade with a quarter turn, so nothing moves. --}}
        <span class="col-start-1 row-start-1 transition duration-200 ease-out"
            x-bind:class="open ? 'opacity-0 rotate-90 scale-75' : 'opacity-100 rotate-0 scale-100'">
            <x-filament::icon icon="phosphor-chat-circle-dots-fill" class="size-7" />
        </span>
        <span class="col-start-1 row-start-1 transition duration-200 ease-out"
            x-bind:class="open ? 'opacity-100 rotate-0 scale-100' : 'opacity-0 -rotate-90 scale-75'">
            <x-filament::icon icon="phosphor-caret-down-bold" class="size-7" />
        </span>
        <span class="sr-only">{{ __('filament-copilot::filament-copilot.title') }}</span>
    </button>

    <div id="cms-assistant" x-show="open" role="dialog" aria-label="{{ __('filament-copilot::filament-copilot.title') }}"
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100" x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100" x-transition:leave-end="opacity-0 translate-y-4 scale-95"
        x-on:dragenter="dragImagesOver($event)" x-on:dragover="dragImagesOver($event)"
        x-on:dragleave="dragImagesLeave($event)" x-on:drop="dropImages($event)"
        class="copilot-chat-widget fixed bottom-24 end-5 z-50 flex origin-bottom-right flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        style="width: min(26rem, calc(100vw - 2.5rem)); height: min(40rem, calc(100dvh - 8rem));">

        <div x-show="droppingImages" x-cloak x-transition.opacity.duration.150ms
            class="pointer-events-none absolute inset-0 z-30 flex items-center justify-center bg-white/80 backdrop-blur-sm dark:bg-gray-900/80">
            <div class="flex flex-col items-center gap-3 px-6 text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 dark:bg-primary-900/20">
                    <x-filament::icon icon="phosphor-paperclip" class="h-8 w-8 text-primary-400 dark:text-primary-500" />
                </div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('Drop files to attach them') }}</p>
            </div>
        </div>

        {{-- Header (sticky, inside the chat bubble) --}}
        <div
            class="sticky top-0 z-10 flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center">
                    <x-filament::icon icon="phosphor-sparkle"
                        class="w-5 h-5 text-primary-600 dark:text-primary-400" />
                </div>
                <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ __('filament-copilot::filament-copilot.title') }}</h2>
            </div>
            <div class="flex items-center gap-3">
                <button x-show="$wire.conversationId" wire:click="exportConversation" wire:loading.attr="disabled"
                    type="button"
                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 fi-color-gray w-8 h-8"
                    title="{{ __('filament-copilot::filament-copilot.export') }}">
                    <x-filament::icon icon="phosphor-download-simple" class="w-5 h-5 text-gray-400 dark:text-gray-400"
                        wire:loading.class="animate-pulse" wire:target="exportConversation" />
                </button>
                <button @click="toggleSidebar()" type="button"
                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 w-8 h-8"
                    :class="sidebarOpen ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' :
                        'hover:bg-gray-500/5 dark:hover:bg-gray-400/5 text-gray-400 dark:text-gray-400'"
                    title="{{ __('filament-copilot::filament-copilot.history') }}">
                    <x-filament::icon icon="phosphor-clock" class="w-5 h-5" />
                </button>
                <button wire:click="newConversation" type="button"
                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 fi-color-gray w-8 h-8"
                    title="{{ __('filament-copilot::filament-copilot.new_conversation') }}">
                    <x-filament::icon icon="phosphor-plus" class="w-5 h-5 text-gray-400 dark:text-gray-400" />
                </button>
                <button @click="open = false" type="button"
                    class="fi-icon-btn fi-modal-close-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 fi-color-gray w-8 h-8"
                    title="{{ __('filament-copilot::filament-copilot.close') ?? 'Close' }}">
                    <x-filament::icon icon="phosphor-x" class="w-5 h-5 text-gray-400 dark:text-gray-400" />
                </button>
            </div>
        </div>

        {{-- Content area with sidebar overlay --}}
        <div class="flex-1 flex relative overflow-hidden min-h-0">

            {{-- Conversation Sidebar --}}
            <div x-show="sidebarOpen" x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-x-4" x-transition:enter-end="opacity-100 translate-x-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-x-0" x-transition:leave-end="opacity-0 -translate-x-4"
                x-cloak class="absolute inset-0 z-10 bg-white dark:bg-gray-900 flex flex-col">

                {{-- Sidebar loading skeleton --}}
                <div x-show="sidebarLoading" class="flex flex-col h-full">
                    <div
                        class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                        <div class="flex gap-1">
                            <div class="h-7 w-7 bg-gray-200 dark:bg-gray-700 rounded-lg animate-pulse"></div>
                            <div class="h-7 w-7 bg-gray-200 dark:bg-gray-700 rounded-lg animate-pulse"></div>
                        </div>
                    </div>
                    <div class="flex-1 p-2 space-y-2">
                        <template x-for="i in 5" :key="i">
                            <div class="px-3 py-3 rounded-lg">
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-2"
                                    :style="'width: ' + (60 + Math.random() * 30) + '%'"></div>
                                <div class="h-3 w-24 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"></div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Actual sidebar content --}}
                <div x-show="!sidebarLoading" class="flex flex-col h-full">
                    @livewire('filament-copilot-sidebar', ['activeConversationId' => $conversationId])
                </div>
            </div>

            {{-- Conversation loading overlay --}}
            <div x-show="conversationLoading" x-transition.opacity.duration.200ms x-cloak
                class="absolute inset-0 z-20 bg-white/80 dark:bg-gray-900/80 backdrop-blur-sm flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin w-7 h-7 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <span
                        class="text-xs text-gray-500 dark:text-gray-400">{{ __('filament-copilot::filament-copilot.loading') }}...</span>
                </div>
            </div>

            {{-- Messages --}}
            <div id="copilot-messages" x-ref="messages" class="flex-1 overflow-y-auto px-4 py-4 space-y-4">
                @if (empty($messages))
                    <div
                        class="flex flex-col items-center justify-center h-full text-center text-gray-400 dark:text-gray-400 gap-4 px-4">
                        <div
                            class="w-14 h-14 rounded-2xl bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center">
                            <x-filament::icon icon="phosphor-sparkle"
                                class="w-8 h-8 text-primary-400 dark:text-primary-500" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                                {{ __('filament-copilot::filament-copilot.welcome_message') }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-400 mt-1">
                                {{ __('filament-copilot::filament-copilot.input_placeholder') }}</p>
                        </div>

                        {{-- Quick Actions --}}
                        {{-- CMS override: suggestions for the screen the chat was opened on. --}}
                        @php($quickActions = \Aurora\Support\CopilotContext::suggestionsFor(request()->hasHeader('X-Livewire') ? request()->headers->get('referer') : request()->fullUrl()) ?: $quickActions)
                        @if (!empty($quickActions))
                            <div class="flex flex-wrap justify-center gap-2 mt-1 max-w-sm">
                                @foreach ($quickActions as $action)
                                    <button
                                        wire:click="$dispatch('copilot-quick-action', { prompt: {{ Js::from($action['prompt'] ?? $action) }} })"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-xl border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                                        @if (isset($action['icon']))
                                            <x-filament::icon :icon="$action['icon']" class="w-3.5 h-3.5" />
                                        @endif
                                        {{ $action['label'] ?? $action }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    @foreach ($messages as $msg)
                        @continue(in_array($msg['role'] ?? null, ['tool', 'tool_call', 'tool_result'], true))
                        @include('filament-copilot::components.chat-message', ['msg' => $msg])
                    @endforeach
                @endif

                {{-- Tool calls stay out of the conversation: while the assistant looks things up or
                     makes changes, it just shows that it is thinking (below). --}}

                {{-- Human approval requests --}}
                <div x-show="pendingApprovals.length" x-cloak
                    class="mx-1 rounded-xl border border-warning-200 bg-warning-50 p-3 dark:border-warning-800 dark:bg-warning-950/30">
                    <div class="flex items-center gap-2 text-sm font-medium text-warning-700 dark:text-warning-300">
                        <x-filament::icon icon="phosphor-shield-warning" class="h-4 w-4" />
                        <span>{{ __('Approval required') }}</span>
                    </div>
                    <template x-for="approval in pendingApprovals" :key="approval.id">
                        <div class="mt-2 text-xs text-warning-800 dark:text-warning-200">
                            <div class="font-medium" x-text="approval.tool"></div>
                            <div x-show="approval.reason" x-text="approval.reason"></div>
                            <pre class="mt-1 max-h-20 overflow-y-auto whitespace-pre-wrap break-all"
                                x-text="JSON.stringify(approval.arguments, null, 2)"></pre>
                        </div>
                    </template>
                    <div class="mt-3 flex gap-2">
                        <button type="button" @click="submitApprovals('approve')"
                            class="rounded-lg bg-success-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-success-500">
                            {{ __('Approve') }}
                        </button>
                        <button type="button" @click="submitApprovals('reject')"
                            class="rounded-lg bg-danger-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-danger-500">
                            {{ __('Reject') }}
                        </button>
                    </div>
                </div>

                {{-- SSE Streaming content (stays visible until server-rendered message replaces it) --}}
                <div x-show="(isStreaming || pendingComplete) && streamedContent" x-cloak wire:ignore.self
                    data-streaming-overlay class="flex items-start gap-2.5">
                    <div
                        class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
                        <x-filament::icon icon="phosphor-sparkle"
                            class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div
                        class="min-w-0 max-w-[85%] rounded-2xl rounded-tl-md px-3.5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                        <div
                            class="text-sm leading-relaxed prose prose-sm dark:prose-invert max-w-none wrap-break-word">
                            <p x-text="streamedContent" class="whitespace-pre-wrap"></p>
                        </div>
                        <span x-show="isStreaming"
                            class="inline-block w-1.5 h-4 bg-primary-500 animate-pulse rounded-sm ml-0.5 align-text-bottom"></span>
                    </div>
                </div>

                {{-- Loading indicator (synchronous fallback) --}}
                <div wire:loading wire:target="sendMessage" x-show="!isStreaming" class="flex items-start gap-2.5">
                    <div
                        class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
                        <x-filament::icon icon="phosphor-sparkle"
                            class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div
                        class="flex items-center gap-1.5 py-2.5 px-3.5 bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-tl-md">
                        <span class="w-1.5 h-1.5 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce"
                            style="animation-delay: 0ms"></span>
                        <span class="w-1.5 h-1.5 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce"
                            style="animation-delay: 150ms"></span>
                        <span class="w-1.5 h-1.5 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce"
                            style="animation-delay: 300ms"></span>
                    </div>
                </div>

                {{-- Thinking: before the first words arrive, and while a tool is running. --}}
                <template x-if="isStreaming && (!streamedContent || toolCalls.some(tool => tool.status === 'running'))">
                    <div class="flex items-start gap-2.5">
                        <div
                            class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
                            <x-filament::icon icon="phosphor-sparkle"
                                class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                        </div>
                        <div
                            class="flex items-center gap-1.5 py-2.5 px-3.5 bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-tl-md">
                            <span class="w-1.5 h-1.5 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce"
                                style="animation-delay: 0ms"></span>
                            <span class="w-1.5 h-1.5 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce"
                                style="animation-delay: 150ms"></span>
                            <span class="w-1.5 h-1.5 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce"
                                style="animation-delay: 300ms"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Input (sticky footer of the chat bubble) --}}
        <div
            class="sticky bottom-0 border-t border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shrink-0 px-6 py-4">
            <form wire:submit="sendMessage"
                class="rounded-xl ring-1 ring-gray-300 dark:ring-gray-600 bg-white dark:bg-gray-800 p-2 focus-within:ring-2 focus-within:ring-primary-500 transition-shadow">
                @if ($attachedImages !== [])
                    <div class="mb-2 flex flex-wrap gap-2 px-1">
                        @foreach ($attachedImages as $image)
                            <div class="relative">
                                @if (filled($image['url'] ?? null))
                                    <img src="{{ $image['url'] }}" alt="{{ $image['title'] }}"
                                        class="rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10"
                                        style="width: 3.5rem; height: 3.5rem">
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-2 text-xs text-gray-700 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10"
                                        style="height: 3.5rem; max-width: 12rem">
                                        <x-filament::icon :icon="$image['icon'] ?? 'phosphor-file'" class="size-4 shrink-0 text-gray-400" />
                                        <span class="truncate">{{ $image['title'] }}</span>
                                    </span>
                                @endif
                                <button type="button" wire:click="detachImage('{{ $image['id'] }}')"
                                    class="absolute -end-1.5 -top-1.5 grid size-5 place-items-center rounded-full bg-gray-900 text-white dark:bg-white dark:text-gray-900"
                                    title="{{ __('Remove file') }}">
                                    <x-filament::icon icon="phosphor-x-bold" class="size-3" />
                                    <span class="sr-only">{{ __('Remove file') }}</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="flex items-center gap-2">
                    <label
                        class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-500/5 hover:text-gray-600 dark:hover:bg-gray-400/5 dark:hover:text-gray-200"
                        title="{{ __('Attach a file, or drop it on the chat. Images, PDFs, and text or CSV files.') }}"
                        wire:loading.class="opacity-40 pointer-events-none" wire:target="imageUploads">
                        <input type="file" x-ref="imageUploads" wire:model="imageUploads" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,application/pdf,text/plain,text/csv,.csv,.txt,.pdf"
                            multiple class="sr-only"
                            x-on:livewire-upload-finish="$el.value = ''"
                            :disabled="$wire.isLoading || isStreaming">
                        <x-filament::icon icon="phosphor-paperclip" class="size-5" wire:loading.remove wire:target="imageUploads" />
                        <svg wire:loading wire:target="imageUploads" class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg"
                            fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <span class="sr-only">{{ __('Attach files') }}</span>
                    </label>
                    <textarea wire:model="message" @keydown="handleKeydown($event)" @input="autoResize($event.target)" rows="1"
                        class="copilot-textarea flex-1 resize-none bg-transparent text-sm text-gray-900 dark:text-white py-1 px-2 border-none shadow-none outline-none placeholder-gray-400 dark:placeholder-gray-500"
                        style="max-height: 120px" placeholder="{{ __('filament-copilot::filament-copilot.input_placeholder') }}"
                        :disabled="$wire.isLoading || isStreaming"></textarea>
                    <button type="submit"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary-600 hover:bg-primary-500 active:bg-primary-700 text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed shrink-0"
                        :disabled="$wire.isLoading || isStreaming">
                        <x-filament::icon icon="phosphor-paper-plane-right-fill" class="w-4 h-4" x-show="!isStreaming && !$wire.isLoading" />
                        <svg x-show="isStreaming || $wire.isLoading" x-cloak class="animate-spin w-4 h-4"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </button>
                </div>
            </form>

            {{-- Keyboard shortcut hint (desktop only) --}}
            <div class="hidden sm:flex items-center justify-center mt-2">
                <span class="text-[10px] text-gray-400 dark:text-gray-400">
                    <kbd
                        class="px-1 py-0.5 rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 font-mono text-[10px]">{{ __('Enter') }}</kbd>
                    {{ __('send ·') }}
                    <kbd
                        class="px-1 py-0.5 rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 font-mono text-[10px]">{{ __('Shift+Enter') }}</kbd>
                    {{ __('new line') }}
                </span>
            </div>
        </div>
    </div>
    </div>

    {{-- Dialogs (deleting a conversation) live outside the chat panel, and above it, so they
         cover the whole window, chat included. --}}
    <div class="cms-assistant-dialogs">
        <x-filament-actions::modals />
    </div>
</div>
