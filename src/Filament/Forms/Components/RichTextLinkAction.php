<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\Asset;
use Aurora\Models\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

/**
 * The rich editor's link dialog, in place of Filament's URL-only one: link to a page, to a
 * file in the media library (a PDF, a price list) or to any URL. Pages are linked by their
 * address and files by their lasting address (/files/{id}), which survives the file being
 * replaced. Registered for every rich editor in AppServiceProvider.
 */
class RichTextLinkAction
{
    public static function make(): Action
    {
        return Action::make('link')
            ->label(__('filament-forms::components.rich_editor.actions.link.label'))
            ->modalHeading(__('filament-forms::components.rich_editor.actions.link.modal.heading'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('Save link'))
            ->fillForm(fn (array $arguments): array => [
                ...static::parse($arguments['url'] ?? null),
                'shouldOpenInNewTab' => $arguments['shouldOpenInNewTab'] ?? false,
            ])
            ->schema([
                ToggleButtons::make('type')
                    ->hiddenLabel()
                    ->options(['page' => __('Page'), 'file' => __('File'), 'url' => __('URL')])
                    ->icons(['page' => 'phosphor-file-text', 'file' => 'phosphor-paperclip', 'url' => 'phosphor-link'])
                    ->inline()
                    ->grouped()
                    ->live(),
                PagePicker::make('page')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => $get('type') === 'page'),
                AssetPicker::make('file')
                    ->hiddenLabel()
                    ->anyFile()
                    ->visible(fn (Get $get): bool => $get('type') === 'file'),
                TextInput::make('url')
                    ->hiddenLabel()
                    ->placeholder('https://… or mailto:…')
                    ->prefixIcon('phosphor-link')
                    ->inputMode('url')
                    ->visible(fn (Get $get): bool => $get('type') === 'url'),
                Checkbox::make('shouldOpenInNewTab')
                    ->label(__('filament-forms::components.rich_editor.actions.link.modal.form.should_open_in_new_tab.label')),
            ])
            ->action(function (array $arguments, array $data, RichEditor $component): void {
                $href = static::href($data);
                $isSingleCharacterSelection = ($arguments['editorSelection']['head'] ?? null) === ($arguments['editorSelection']['anchor'] ?? null);
                $extend = $isSingleCharacterSelection ? [EditorCommand::make('extendMarkRange', arguments: ['link'])] : [];

                // No address: take the link off, like Filament's own dialog.
                $component->runCommands(
                    blank($href)
                        ? [...$extend, EditorCommand::make('unsetLink')]
                        : [...$extend, EditorCommand::make('setLink', arguments: [[
                            'href' => $href,
                            'target' => $data['shouldOpenInNewTab'] ? '_blank' : null,
                        ]])],
                    editorSelection: $arguments['editorSelection'],
                );
            });
    }

    /**
     * What an existing link points at, to open the dialog on the right tab.
     *
     * @return array{type: string, page: ?string, file: ?string, url: ?string}
     */
    public static function parse(?string $href): array
    {
        $empty = ['type' => 'page', 'page' => null, 'file' => null, 'url' => null];

        if (blank($href)) {
            return $empty;
        }

        if (preg_match('#^/files/([0-9a-f-]{36})$#i', $href, $matches) && Asset::query()->whereKey($matches[1])->exists()) {
            return [...$empty, 'type' => 'file', 'file' => $matches[1]];
        }

        $page = Page::query()->get(['id', 'slug', 'is_homepage'])->first(fn (Page $page): bool => $page->path() === $href);

        return $page ? [...$empty, 'page' => $page->id] : [...$empty, 'type' => 'url', 'url' => $href];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function href(array $data): ?string
    {
        return match ($data['type'] ?? null) {
            'page' => is_string($data['page'] ?? null) ? Page::query()->find($data['page'])?->path() : null,
            'file' => is_string($data['file'] ?? null) && Str::isUuid($data['file']) ? route('files.show', $data['file'], absolute: false) : null,
            default => filled($data['url'] ?? null) ? trim($data['url']) : null,
        };
    }
}
