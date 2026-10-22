<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class HeadingBlock extends Block
{
    use HasPreviewImage;

    public const LEVELS = ['h1', 'h2', 'h3', 'h4'];

    public const SIZES = ['display' => 'Display', 'xl' => 'XL', 'lg' => 'L', 'md' => 'M', 'sm' => 'S'];

    public static function type(): string
    {
        return 'heading';
    }

    public static function label(): string
    {
        return __('Heading');
    }

    public static function description(): string
    {
        return __('A title with an optional eyebrow and intro, typed straight onto the page.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-text-h-one';
    }

    public static function defaults(): array
    {
        return ['level' => 'h2', 'size' => 'lg', 'align' => 'left'];
    }

    public static function editables(): array
    {
        return [
            'eyebrow' => Editable::text()->placeholder(__('Eyebrow')),
            'text' => Editable::text()->placeholder(__('Write a heading')),
            'intro' => Editable::text()->multiline()->placeholder(__('Optional introduction…')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'Use one h1 per page (usually in the hero). Section titles are h2 with size "lg" or "xl". The eyebrow is a short uppercase kicker (2–3 words); the intro is one or two sentences. Wrap a highlighted word in *asterisks* to paint it in the brand gradient, e.g. "Build *faster* together".';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('eyebrow')->label(__('Eyebrow'))->maxLength(80),
            Textarea::make('text')->label(__('Heading'))->rows(2)->helperText(__('Wrap a word in *asterisks* to highlight it.')),
            Textarea::make('intro')->label(__('Introduction'))->rows(3),
            ToggleButtons::make('level')->label(__('Level (SEO)'))->options(array_combine(self::LEVELS, array_map('strtoupper', self::LEVELS)))->default('h2')->inline()->grouped(),
            ToggleButtons::make('size')->label(__('Size'))->options(self::SIZES)->default('lg')->inline()->grouped(),
            ToggleButtons::make('align')->label(__('Alignment'))->options(['left' => __('Left'), 'center' => __('Centre')])->default('left')->inline()->grouped(),
        ];
    }
}
