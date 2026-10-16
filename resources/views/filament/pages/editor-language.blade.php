{{-- The visual editor's language: the page's own language edits the page itself, any
     other translates its text (Aurora\Filament\Resources\Pages\Concerns\TranslatesBlocks). --}}
@php
    use Aurora\Support\ContentLocale;

    // Only the languages this page is in; with just one, no switcher.
    $languages = array_intersect_key(ContentLocale::all(), array_flip($this->getRecord()->availableLocales()));
@endphp

@if (count($languages) > 1)
    <div class="cms-editor-language" x-data="{ open: false }" x-on:click.outside="open = false">
        <button type="button" class="cms-editor-language-button" x-on:click="open = ! open" :aria-expanded="open">
            <x-filament::icon icon="phosphor-translate-bold" class="size-4" />
            <span class="cms-editor-language-name">{{ $languages[$this->editingLocale()] ?? '' }}</span>
            <span class="cms-editor-language-code-short">{{ strtoupper($this->editingLocale()) }}</span>
            <x-filament::icon icon="phosphor-caret-down-bold" class="size-3 opacity-60" />
        </button>
        <div class="cms-editor-language-menu" x-show="open" x-cloak x-transition.opacity>
            @foreach ($languages as $code => $name)
                @php($isDefault = $code === $this->getRecord()->primaryLocale())
                <a href="{{ \Aurora\Filament\Resources\Pages\PageResource::getUrl('design', ['record' => $this->getRecord(), ...($isDefault ? [] : ['language' => $code])]) }}"
                    @class(['cms-editor-language-item', 'is-active' => $code === ($this->editingLocale())])>
                    <span class="cms-editor-language-code">{{ strtoupper($code) }}</span>
                    <span>{{ $name }}</span>
                    @if ($isDefault)
                        <span class="cms-editor-language-note">{{ __('Layout and text') }}</span>
                    @else
                        <span class="cms-editor-language-note">{{ __('Text only') }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>

    {{-- Rendered by hand here, so its visibility (an AI assistant set up, a language to translate into) is checked by hand too. --}}
    @if ($this->translateWithAiAction->isVisible())
        {{ $this->translateWithAiAction }}
    @endif
@endif
