{{-- Aurora's copy of the page builder's editor (carljanzell/filament-page-builder), the same but
     for a block's card in the block list: its icon beside its name, and its picture (look for
     "Aurora:"). --}}
<x-filament-panels::page>
    <div
        class="fpb"
        x-data="pageBuilderCanvas()"
        :data-workspace="workspace"
        :data-narrow="narrow ? 'true' : 'false'"
        :data-wide="wideInspector ? 'true' : 'false'"
        data-fpb-i18n="{{ json_encode($this->canvasStrings(), JSON_UNESCAPED_UNICODE) }}"
        wire:key="fpb-{{ $this->getRecord()->getKey() }}"
    >
        <header class="fpb-chrome">
            <div class="fpb-chrome-start">
                @if ($exitUrl = $this->exitUrl())
                    <a href="{{ $exitUrl }}" class="fpb-back">{{ $this->exitLabel() }}</a>
                @endif

                <h1 class="fpb-chrome-title">{{ $this->getRecordTitle() }}</h1>

                @if ($this->selectionPath !== [])
                    <nav class="fpb-crumbs" aria-label="{{ __('page-builder::chrome.aria_selected_block') }}">
                        @foreach ($this->selectionPath as $crumb)
                            <button
                                type="button"
                                class="fpb-crumb"
                                @if ($crumb['id'] === $this->selectedId) data-current="true" @endif
                                wire:click="selectBlock('{{ $crumb['id'] }}')"
                            >{{ $crumb['label'] }}</button>
                            @unless ($loop->last)
                                <span class="fpb-crumb-sep" aria-hidden="true">/</span>
                            @endunless
                        @endforeach
                        <span class="fpb-crumb-field" x-show="editingField" x-cloak>
                            <span class="fpb-crumb-sep" aria-hidden="true">/</span>
                            <span x-text="editingField"></span>
                        </span>
                    </nav>
                @endif

                <span class="fpb-status" @class(['fpb-status-dirty' => $this->isDirty])>
                    {{ $this->isDirty ? __('page-builder::chrome.draft_not_saved') : __('page-builder::chrome.saved') }}
                </span>
                <span class="fpb-notice" x-show="notice" x-text="notice" x-cloak role="status"></span>
            </div>

            <div class="fpb-preview-toggle fpb-desktop-only" role="group" aria-label="{{ __('page-builder::chrome.aria_canvas_width') }}">
                <button type="button" class="fpb-preview-btn" :aria-pressed="preview === 'desktop'" :data-active="preview === 'desktop'" x-on:click="preview = 'desktop'">{{ __('page-builder::chrome.desktop') }}</button>
                <button type="button" class="fpb-preview-btn" :aria-pressed="preview === 'tablet'" :data-active="preview === 'tablet'" x-on:click="preview = 'tablet'">{{ __('page-builder::chrome.tablet') }} <span class="fpb-preview-px">768</span></button>
                <button type="button" class="fpb-preview-btn" :aria-pressed="preview === 'mobile'" :data-active="preview === 'mobile'" x-on:click="preview = 'mobile'">{{ __('page-builder::chrome.mobile') }} <span class="fpb-preview-px">390</span></button>
            </div>

            <div class="fpb-toolbar-actions">
                <x-filament::icon-button
                    icon="heroicon-m-arrow-uturn-left"
                    :label="__('page-builder::chrome.undo')"
                    color="gray"
                    size="sm"
                    wire:click="undo"
                    :disabled="! $this->canUndo"
                />

                <x-filament::icon-button
                    icon="heroicon-m-arrow-uturn-right"
                    :label="__('page-builder::chrome.redo')"
                    color="gray"
                    size="sm"
                    wire:click="redo"
                    :disabled="! $this->canRedo"
                />

                @if ($formEditorUrl = $this->formEditorUrl())
                    {{-- Blade does not compile directives inside a component's attributes, so
                         @js() here reached the browser as the text "@js(...)": the handler
                         failed to compile and the link opened with no warning. Encode it here
                         and echo it, which component attributes do support. --}}
                    @php
                        $formEditorColumnsWarning = \Illuminate\Support\Js::from(__('page-builder::chrome.form_editor_columns_warning'));
                    @endphp
                    <span class="fpb-desktop-only">
                        @if ($this->hasNestedBlocks())
                            {{-- Not an SPA link. On an SPA panel Filament gives the link
                                 wire:navigate, and Livewire navigates on mousedown and
                                 mouseup, before this click handler runs, so Cancel came too
                                 late and the form editor opened anyway. --}}
                            <x-filament::button
                                tag="a"
                                href="{{ $formEditorUrl }}"
                                :spa-mode="false"
                                color="gray"
                                size="sm"
                                x-on:click="if (! confirm({{ $formEditorColumnsWarning }})) { $event.preventDefault() }"
                            >
                                {{ __('page-builder::chrome.form_editor') }}
                            </x-filament::button>
                        @else
                            <x-filament::button
                                tag="a"
                                href="{{ $formEditorUrl }}"
                                color="gray"
                                size="sm"
                            >
                                {{ __('page-builder::chrome.form_editor') }}
                            </x-filament::button>
                        @endif
                    </span>
                @endif

                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="heroicon-m-eye"
                    x-on:click="openPreview()"
                    :title="__('page-builder::chrome.preview_hint')"
                >
                    {{ __('page-builder::chrome.preview') }}
                </x-filament::button>

                <x-filament::button
                    wire:click="save"
                    wire:loading.attr="disabled"
                    size="sm"
                >
                    {{ __('page-builder::chrome.save_layout') }}
                </x-filament::button>
            </div>
        </header>

        <aside class="fpb-panel fpb-palette" x-show="!narrow || workspace === 'blocks'">
            <div class="fpb-side-tabs" role="tablist" aria-label="{{ __('page-builder::chrome.aria_blocks_and_outline') }}">
                <button
                    type="button"
                    role="tab"
                    id="fpb-tab-blocks"
                    class="fpb-side-tab"
                    aria-controls="fpb-panel-blocks"
                    :aria-selected="sideTab === 'blocks'"
                    :data-active="sideTab === 'blocks'"
                    x-on:click="sideTab = 'blocks'"
                >{{ __('page-builder::chrome.blocks') }}</button>
                <button
                    type="button"
                    role="tab"
                    id="fpb-tab-structure"
                    class="fpb-side-tab"
                    aria-controls="fpb-panel-structure"
                    :aria-selected="sideTab === 'structure'"
                    :data-active="sideTab === 'structure'"
                    x-on:click="sideTab = 'structure'"
                >{{ __('page-builder::chrome.structure') }}</button>
            </div>

            <div id="fpb-panel-blocks" role="tabpanel" aria-labelledby="fpb-tab-blocks" x-show="sideTab === 'blocks'">
                {{-- The copy swaps with the selection once Alpine takes over, so both halves
                     are translated here rather than leaving an English fallback behind.
                     Held in variables instead of nested inside @js() for the same paren
                     balancing reason noted on the form editor button. --}}
                @php
                    $paletteHintSelected = __('page-builder::chrome.palette_hint_selected');
                    $paletteHintEmpty = __('page-builder::chrome.palette_hint_empty');
                @endphp
                <p class="fpb-panel-hint" x-text="$wire.selectedId
                    ? @js($paletteHintSelected)
                    : @js($paletteHintEmpty)">
                    {{ __('page-builder::chrome.palette_hint_static') }}
                </p>

                <label class="fpb-search">
                    <span class="sr-only">{{ __('page-builder::chrome.search_blocks') }}</span>
                    <input
                        type="search"
                        class="fpb-search-input"
                        placeholder="{{ __('page-builder::chrome.search_blocks') }}"
                        x-ref="paletteSearch"
                        x-model="paletteQuery"
                    >
                </label>

                @foreach ($this->paletteGroups as $group)
                    <div class="fpb-palette-group-wrap" x-show="[...$el.querySelectorAll('[data-fpb-search]')].some((item) => matchesPalette(item.dataset.fpbSearch))">
                        <h3 class="fpb-palette-group">{{ $group['label'] }}</h3>
                        <ul class="fpb-palette-list">
                            @foreach ($group['items'] as $item)
                                <li
                                    data-fpb-search="{{ strtolower($item['label'].' '.$item['type'].' '.($item['description'] ?? '')) }}"
                                    x-show="matchesPalette($el.dataset.fpbSearch)"
                                >
                                    <button
                                        type="button"
                                        class="fpb-palette-item"
                                        draggable="true"
                                        data-type="{{ $item['type'] }}"
                                        :draggable="!narrow"
                                        x-on:dragstart="startInsert($event, '{{ $item['type'] }}')"
                                        x-on:dragend="clearDrag()"
                                        x-on:click="insertFromPalette('{{ $item['type'] }}')"
                                    >
                                        {{-- Aurora: the icon on the name's line, so the card's full width is the picture's. --}}
                                        <span class="fpb-palette-copy">
                                            <span class="cms-palette-name">
                                                @if ($item['icon'])
                                                    <x-filament::icon :icon="$item['icon']" class="fpb-palette-icon" />
                                                @endif
                                                <span>{{ $item['label'] }}</span>
                                            </span>
                                            {{-- Aurora: the block's picture (Aurora\Blocks\Blocks::preview()), under its name. --}}
                                            @if (filled($item['preview'] ?? null))
                                                <img src="{{ $item['preview'] }}" alt="" loading="lazy" draggable="false" class="cms-palette-preview">
                                            @endif
                                            @if ($item['description'])
                                                <span class="fpb-palette-desc">{{ $item['description'] }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <div id="fpb-panel-structure" role="tabpanel" aria-labelledby="fpb-tab-structure" x-show="sideTab === 'structure'" x-cloak>
                <h2 class="fpb-panel-title">{{ __('page-builder::chrome.document') }}</h2>
                <p class="fpb-panel-hint">{{ __('page-builder::chrome.structure_hint') }}</p>

                @if ($this->structure === [])
                    <p class="fpb-panel-hint">{{ __('page-builder::chrome.no_blocks_yet') }}</p>
                @else
                    <ol class="fpb-structure">
                        @foreach ($this->structure as $node)
                            @include('page-builder::structure-node', ['node' => $node, 'depth' => 0])
                        @endforeach
                    </ol>
                @endif

                @if ($this->ghosts !== [])
                    <div class="fpb-ghosts">
                        <h3 class="fpb-palette-group">{{ __('page-builder::chrome.hidden', ['count' => count($this->ghosts)]) }}</h3>
                        <p class="fpb-panel-hint">{{ __('page-builder::chrome.ghosts_hint') }}</p>
                        <ul class="fpb-ghost-list">
                            @foreach ($this->ghosts as $ghost)
                                <li>
                                    <span>{{ $ghost['label'] }}</span>
                                    <button type="button" class="fpb-ghost-reveal" wire:click="revealGhost('{{ $ghost['id'] }}')" x-on:click="afterRevealOnCanvas()">
                                        {{ $ghost['reason'] === 'orphan' ? __('page-builder::chrome.ghost_orphan') : __('page-builder::chrome.ghost_hidden_slot') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <dl class="fpb-shortcuts fpb-desktop-only">
                <dt x-text="isMac ? '⌘S' : 'Ctrl+S'">Ctrl+S</dt><dd>{{ __('page-builder::chrome.shortcut_save') }}</dd>
                <dt x-text="isMac ? '⌘Z' : 'Ctrl+Z'">Ctrl+Z</dt><dd>{{ __('page-builder::chrome.shortcut_undo') }}</dd>
                <dt x-text="isMac ? '⇧⌘Z' : 'Ctrl+Shift+Z'">Ctrl+Shift+Z</dt><dd>{{ __('page-builder::chrome.shortcut_redo') }}</dd>
                <dt x-text="isMac ? '⌘C ⌘V' : 'Ctrl+C Ctrl+V'">Ctrl+C Ctrl+V</dt><dd>{{ __('page-builder::chrome.shortcut_copy_paste') }}</dd>
                <dt x-text="isMac ? '⌘D' : 'Ctrl+D'">Ctrl+D</dt><dd>{{ __('page-builder::chrome.shortcut_duplicate') }}</dd>
                <dt>⇧↑ ⇧↓</dt><dd>{{ __('page-builder::chrome.shortcut_move') }}</dd>
                <dt>↑ ↓</dt><dd>{{ __('page-builder::chrome.shortcut_select') }}</dd>
                <dt>⌫</dt><dd>{{ __('page-builder::chrome.shortcut_delete') }}</dd>
                <dt>Esc</dt><dd>{{ __('page-builder::chrome.shortcut_deselect') }}</dd>
                <dt>{{ __('page-builder::chrome.shortcut_right_click') }}</dt><dd>{{ __('page-builder::chrome.shortcut_more_actions') }}</dd>
            </dl>
        </aside>

        <main class="fpb-canvas-wrap" x-show="!narrow || workspace === 'canvas'">
            <div class="fpb-canvas-frame" x-ref="frame">
                <div
                    class="fpb-canvas"
                    :data-preview="preview"
                    x-on:dragover.prevent="onDragOver($event)"
                    x-on:drop.prevent="onDrop($event)"
                    x-on:dragleave="onDragLeave($event)"
                    x-on:contextmenu="onContextMenu($event)"
                >
                    @if ($stylesView = $this->canvasStylesView())
                        @include($stylesView)
                    @endif
                    @forelse ($this->rootBlocks as $block)
                        <x-page-builder::canvas-block :block="$block" :selected-id="$this->selectedId" />
                    @empty
                        <div class="fpb-empty">
                            <p class="fpb-empty-title">{{ __('page-builder::chrome.start_layout') }}</p>
                            <p class="fpb-empty-copy">
                                <span class="fpb-empty-copy-wide">{{ __('page-builder::chrome.empty_wide') }}</span>
                                <span class="fpb-empty-copy-narrow">{{ __('page-builder::chrome.empty_narrow') }}</span>
                            </p>
                            <div class="fpb-empty-actions">
                                @foreach (array_slice($this->palette, 0, 3) as $item)
                                    <button
                                        type="button"
                                        class="fpb-empty-btn"
                                        x-on:click="insertFromPalette('{{ $item['type'] }}')"
                                    >{{ __('page-builder::chrome.add_block', ['label' => $item['label']]) }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endforelse
                </div>

                {{-- Editor decoration that must never be part of the page: the drop
                     indicator, column handles and size readouts. It sits outside the
                     canvas so drawing it cannot move a block or wake the canvas's
                     mutation observer, and Livewire leaves it alone. --}}
                <div class="fpb-overlay" x-ref="overlay" wire:ignore aria-hidden="true">
                    <div class="fpb-drop-indicator" x-ref="indicator">
                        <span class="fpb-drop-cap"></span>
                        <span class="fpb-drop-label" x-ref="indicatorLabel"></span>
                        <span class="fpb-drop-cap"></span>
                    </div>
                    <div class="fpb-size-tip" x-ref="sizeTip"></div>
                </div>
            </div>
        </main>

        <aside class="fpb-panel fpb-inspector" x-show="!narrow || workspace === 'settings'">
            @if ($selected = $this->selectedBlock)
                <header class="fpb-inspector-head">
                    <span class="fpb-inspector-icon" aria-hidden="true">
                        @if ($selected['icon'])
                            <x-filament::icon :icon="$selected['icon']" />
                        @endif
                    </span>
                    <div class="fpb-inspector-heading">
                        <h2 class="fpb-panel-title">{{ $selected['label'] }}</h2>
                        @if ($selected['parentId'])
                            <button type="button" class="fpb-inspector-parent" wire:click="selectBlock('{{ $selected['parentId'] }}')">
                                {{ __('page-builder::chrome.inside', ['parent' => $selected['parentLabel']]) }}
                            </button>
                        @elseif ($selected['description'])
                            <p class="fpb-inspector-desc">{{ $selected['description'] }}</p>
                        @endif
                    </div>
                    <div class="fpb-inspector-actions">
                        @php
                            $inspectorNarrower = __('page-builder::chrome.narrower');
                            $inspectorWider = __('page-builder::chrome.wider');
                        @endphp
                        <button
                            type="button"
                            class="fpb-icon-btn fpb-desktop-only"
                            x-on:click="wideInspector = ! wideInspector"
                            :aria-pressed="wideInspector ? 'true' : 'false'"
                            :title="wideInspector ? @js($inspectorNarrower) : @js($inspectorWider)"
                        >
                            <x-filament::icon icon="heroicon-m-arrows-right-left" class="fpb-icon" />
                        </button>
                        <button type="button" class="fpb-icon-btn" wire:click="selectBlock(null)" title="{{ __('page-builder::chrome.close') }}">
                            <x-filament::icon icon="heroicon-m-x-mark" class="fpb-icon" />
                        </button>
                    </div>
                </header>

                <div class="fpb-inspector-tabs" role="tablist" aria-label="{{ __('page-builder::chrome.aria_block_inspector') }}">
                    <button type="button" role="tab" id="fpb-tab-content" class="fpb-side-tab" aria-controls="fpb-panel-content" :aria-selected="inspectorTab === 'content'" :data-active="inspectorTab === 'content'" x-on:click="inspectorTab = 'content'">{{ __('page-builder::chrome.content') }}</button>
                    <button type="button" role="tab" id="fpb-tab-style" class="fpb-side-tab" aria-controls="fpb-panel-style" :aria-selected="inspectorTab === 'style'" :data-active="inspectorTab === 'style'" x-on:click="inspectorTab = 'style'">{{ __('page-builder::chrome.style') }}</button>
                    <button type="button" role="tab" id="fpb-tab-layout" class="fpb-side-tab" aria-controls="fpb-panel-layout" :aria-selected="inspectorTab === 'layout'" :data-active="inspectorTab === 'layout'" x-on:click="inspectorTab = 'layout'">{{ __('page-builder::chrome.layout') }}</button>
                </div>

                <div id="fpb-panel-content" role="tabpanel" aria-labelledby="fpb-tab-content" x-show="inspectorTab === 'content'">
                    @if ($this->isSelectedBlockEditable())
                        {{ $this->form }}
                    @elseif (! $this->isSelectedBlockKnown())
                        <p class="fpb-panel-hint">
                            {{ __('page-builder::chrome.unregistered_hint') }}
                        </p>
                    @else
                        <p class="fpb-panel-hint">
                            {{ __('page-builder::chrome.no_permission_hint') }}
                        </p>
                    @endif
                </div>

                <div id="fpb-panel-style" role="tabpanel" aria-labelledby="fpb-tab-style" class="fpb-style-panel" x-show="inspectorTab === 'style'" x-cloak>
                    @if ($this->isSelectedBlockEditable() && $this->hasCustomStyles())
                        {{ $this->styleForm }}
                    @elseif (! $this->isSelectedBlockEditable())
                        <p class="fpb-panel-hint">{{ __('page-builder::chrome.style_permission_hint') }}</p>
                    @endif

                    @if ($this->styleTokens() !== [])
                        <details class="fpb-presets" @if (! $this->hasCustomStyles()) open @endif>
                            <summary>{{ __('page-builder::chrome.brand_presets') }}</summary>
                            <p class="fpb-panel-hint">{{ __('page-builder::chrome.brand_presets_hint') }}</p>

                            @foreach ($this->styleTokens() as $token => $options)
                                <fieldset class="fpb-style-field">
                                    <legend>{{ ucfirst($token) }}</legend>
                                    <div class="fpb-token-picks" role="radiogroup" aria-label="{{ ucfirst($token) }}">
                                        <button
                                            type="button"
                                            class="fpb-token-pick"
                                            @if (($this->blockSettings[$token] ?? '') === '') data-active="true" @endif
                                            wire:click="$set('blockSettings.{{ $token }}', '')"
                                        >{{ __('page-builder::chrome.default') }}</button>
                                        @foreach ($options as $value => $label)
                                            @php
                                                $stored = is_int($value) ? $label : $value;
                                            @endphp
                                            <button
                                                type="button"
                                                class="fpb-token-pick"
                                                @if (($this->blockSettings[$token] ?? '') === $stored) data-active="true" @endif
                                                wire:click="$set('blockSettings.{{ $token }}', '{{ $stored }}')"
                                            >{{ $label }}</button>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </details>
                    @endif
                </div>

                <div id="fpb-panel-layout" role="tabpanel" aria-labelledby="fpb-tab-layout" class="fpb-style-panel" x-show="inspectorTab === 'layout'" x-cloak>
                    @if ($this->isSelectedBlockEditable() && $this->hasCustomStyles())
                        {{ $this->layoutForm }}
                    @endif

                    <label class="fpb-style-field fpb-anchor-field">
                        <span>{{ __('page-builder::chrome.anchor') }}</span>
                        <input
                            type="text"
                            class="fpb-search-input"
                            wire:model.blur="blockAnchor"
                            placeholder="intro"
                            autocomplete="off"
                        >
                        {{-- "intro" is an id, not a word: it must start with an ASCII letter
                             (BlockTree::isValidAnchor), so it is not translated, and the field
                             takes it without the #, which only the link uses. --}}
                        <small class="fpb-panel-hint">{{ \CarlJanzell\FilamentPageBuilder\PageBuilder::lineWithMarkup('page-builder::chrome.anchor_hint', ['anchor' => '<code>#intro</code>']) }}</small>
                    </label>
                </div>
            @else
                <h2 class="fpb-panel-title">{{ __('page-builder::chrome.nothing_selected') }}</h2>
                <p class="fpb-panel-hint">
                    <span class="fpb-empty-copy-wide">{{ __('page-builder::chrome.inspector_empty_wide') }}</span>
                    <span class="fpb-empty-copy-narrow">{{ __('page-builder::chrome.inspector_empty_narrow') }}</span>
                </p>
            @endif
        </aside>

        <button
            type="button"
            class="fpb-add"
            x-show="narrow && workspace === 'canvas'"
            x-cloak
            x-on:click="showWorkspace('blocks')"
            aria-label="{{ __('page-builder::chrome.aria_add_block') }}"
        >+</button>

        <nav class="fpb-dock" x-show="narrow" x-cloak role="tablist" aria-label="{{ __('page-builder::chrome.aria_design_workspace') }}">
            <button
                type="button"
                role="tab"
                class="fpb-dock-btn"
                :aria-selected="workspace === 'blocks'"
                :data-active="workspace === 'blocks'"
                x-on:click="showWorkspace('blocks')"
            >{{ __('page-builder::chrome.blocks') }}</button>
            <button
                type="button"
                role="tab"
                class="fpb-dock-btn"
                :aria-selected="workspace === 'canvas'"
                :data-active="workspace === 'canvas'"
                x-on:click="showWorkspace('canvas')"
            >{{ __('page-builder::chrome.page') }}</button>
            <button
                type="button"
                role="tab"
                class="fpb-dock-btn"
                :aria-selected="workspace === 'settings'"
                :data-active="workspace === 'settings'"
                x-on:click="showWorkspace('settings')"
            >
                {{ __('page-builder::chrome.settings') }}
                <span class="fpb-dock-dot" x-show="$wire.selectedId" x-cloak aria-hidden="true"></span>
            </button>
        </nav>

        {{-- Right-click menu. Alpine owns it; Livewire must not redraw it mid-click. --}}
        <div
            class="fpb-menu"
            x-ref="menu"
            x-show="menu.open"
            x-cloak
            wire:ignore
            role="menu"
            aria-label="{{ __('page-builder::chrome.aria_block_actions') }}"
            :style="`left: ${menu.x}px; top: ${menu.y}px`"
            x-on:click.outside="closeMenu()"
            x-on:contextmenu.prevent
        >
            <p class="fpb-menu-title" x-text="menu.label"></p>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuInspect('content')"><span>{{ __('page-builder::chrome.edit_content') }}</span></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuInspect('style')"><span>{{ __('page-builder::chrome.style') }}</span></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuInspect('layout')"><span>{{ __('page-builder::chrome.spacing_and_size') }}</span></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-show="menu.parent" x-on:click="menuSelectParent()"><span>{{ __('page-builder::chrome.select_parent') }}</span></button>
            <hr class="fpb-menu-sep">
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuCopy()"><span>{{ __('page-builder::chrome.copy') }}</span><kbd x-text="keys('C')"></kbd></button>
            <button type="button" role="menuitem" class="fpb-menu-item" :disabled="! clipboard" x-on:click="menuPaste('after')"><span>{{ __('page-builder::chrome.paste_after') }}</span><kbd x-text="keys('V')"></kbd></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-show="menu.container" :disabled="! clipboard" x-on:click="menuPaste('inside')"><span>{{ __('page-builder::chrome.paste_inside') }}</span></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuCopyStyle()"><span>{{ __('page-builder::chrome.copy_style') }}</span></button>
            <button type="button" role="menuitem" class="fpb-menu-item" :disabled="! styleClipboard" x-on:click="menuPasteStyle()"><span>{{ __('page-builder::chrome.paste_style') }}</span></button>
            <hr class="fpb-menu-sep">
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuAddBelow()"><span>{{ __('page-builder::chrome.add_block_below') }}</span></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuDuplicate()"><span>{{ __('page-builder::chrome.duplicate') }}</span><kbd x-text="keys('D')"></kbd></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuMove(-1)"><span>{{ __('page-builder::chrome.move_up') }}</span><kbd>⇧↑</kbd></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuMove(1)"><span>{{ __('page-builder::chrome.move_down') }}</span><kbd>⇧↓</kbd></button>
            <button type="button" role="menuitem" class="fpb-menu-item" x-on:click="menuResetStyle()"><span>{{ __('page-builder::chrome.reset_style') }}</span></button>
            <hr class="fpb-menu-sep">
            <button type="button" role="menuitem" class="fpb-menu-item fpb-menu-danger" x-on:click="menuDelete()"><span>{{ __('page-builder::chrome.delete') }}</span><kbd>⌫</kbd></button>
        </div>

        {{-- Preview: the unsaved page, rendered for visitors, at a real width. --}}
        <div class="fpb-preview" x-show="previewing" x-cloak wire:ignore role="dialog" aria-modal="true" aria-label="{{ __('page-builder::chrome.aria_page_preview') }}">
            <header class="fpb-preview-bar">
                <div class="fpb-preview-heading">
                    <strong>{{ __('page-builder::chrome.preview') }}</strong>
                    <span class="fpb-preview-note">{{ __('page-builder::chrome.preview_note') }}</span>
                </div>
                <div class="fpb-preview-toggle" role="group" aria-label="{{ __('page-builder::chrome.preview_width') }}">
                    <button type="button" class="fpb-preview-btn" :data-active="previewDevice === 'desktop'" x-on:click="previewDevice = 'desktop'">{{ __('page-builder::chrome.desktop') }}</button>
                    <button type="button" class="fpb-preview-btn" :data-active="previewDevice === 'tablet'" x-on:click="previewDevice = 'tablet'">{{ __('page-builder::chrome.tablet') }} <span class="fpb-preview-px">768</span></button>
                    <button type="button" class="fpb-preview-btn" :data-active="previewDevice === 'mobile'" x-on:click="previewDevice = 'mobile'">{{ __('page-builder::chrome.mobile') }} <span class="fpb-preview-px">390</span></button>
                </div>
                <div class="fpb-preview-actions">
                    <button type="button" class="fpb-preview-btn" x-on:click="refreshPreview()">{{ __('page-builder::chrome.refresh') }}</button>
                    <button type="button" class="fpb-preview-close" x-on:click="closePreview()">{{ __('page-builder::chrome.back_to_editing') }} <kbd>Esc</kbd></button>
                </div>
            </header>
            <div class="fpb-preview-stage">
                <iframe
                    class="fpb-preview-frame"
                    x-ref="previewFrame"
                    :data-device="previewDevice"
                    title="{{ __('page-builder::chrome.aria_page_preview') }}"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation allow-modals"
                ></iframe>
                <p class="fpb-preview-loading" x-show="previewLoading">{{ __('page-builder::chrome.rendering') }}</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
