<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

/**
 * Code shown on the page (not run): a file name, its language and a copy button, for docs
 * and guides. The code itself is never translated; the caption is.
 */
class CodeSampleBlock extends Block
{
    use HasPreviewImage;

    /**
     * Language => [label, the editor's highlighting].
     *
     * @var array<string, array{0: string, 1: ?Language}>
     */
    public const LANGUAGES = [
        'php' => ['PHP', Language::Php],
        'blade' => ['Blade', Language::Html],
        'bash' => ['Terminal', null],
        'json' => ['JSON', Language::Json],
        'js' => ['JavaScript', Language::JavaScript],
        'css' => ['CSS', Language::Css],
        'html' => ['HTML', Language::Html],
        'yaml' => ['YAML', Language::Yaml],
        'graphql' => ['GraphQL', null],
        'text' => ['Plain text', null],
    ];

    public static function type(): string
    {
        return 'code-sample';
    }

    public static function label(): string
    {
        return __('Code sample');
    }

    public static function description(): string
    {
        return __('Code to read and copy, with its file name: for docs and guides. Shown, never run.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-file-code';
    }

    public static function category(): string
    {
        return 'developer';
    }

    public static function defaults(): array
    {
        return ['language' => 'php', 'filename' => 'app/Providers/AppServiceProvider.php', 'code' => "Blocks::register([\n    HeroBlock::class,\n]);"];
    }

    public static function sameInEveryLanguage(): array
    {
        return ['filename'];
    }

    public static function editables(): array
    {
        return [
            'caption' => Editable::text()->placeholder(__('Optional caption')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'code is shown verbatim (keep its indentation, no Markdown fences); language is one of '.implode(', ', array_keys(self::LANGUAGES)).'; filename is the path the code belongs in, or empty for terminal commands.';
    }

    public static function schema(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('language')
                    ->label(__('Language'))
                    ->options(array_map(fn (array $language): string => $language[0], self::LANGUAGES))
                    ->default('php')
                    ->live()
                    ->selectablePlaceholder(false),
                TextInput::make('filename')->label(__('File name'))->placeholder('app/Models/Post.php'),
            ]),
            CodeEditor::make('code')
                ->label(__('Code'))
                ->language(fn (Get $get): ?Language => self::LANGUAGES[$get('language')][1] ?? null),
            TextInput::make('caption')->label(__('Caption')),
        ];
    }
}
