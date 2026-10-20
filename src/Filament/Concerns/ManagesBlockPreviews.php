<?php

namespace Aurora\Filament\Concerns;

use Aurora\Aurora;
use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use Aurora\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * Settings › Blocks: every block the visual editor offers, with the picture its block list
 * shows beside it. A screenshot uploaded here replaces the block's own (Block::preview()),
 * stored like people's avatars (public disk, not the media library) and kept in the settings
 * by the block's type. Changes save straight away.
 */
trait ManagesBlockPreviews
{
    /**
     * @return array<int, Component>
     */
    protected function blockRows(): array
    {
        $uploaded = Setting::get(Blocks::PREVIEWS_SETTING) ?? [];

        // The page builder's own blocks (heading, text, image …) aren't listed: only the website's.
        if (Blocks::all() === []) {
            return [
                EmptyState::make(__('No blocks of the website’s own yet'))
                    ->description(__('Editors build pages from the built-in blocks for now. Blocks made for this website are listed here once its code has them: make one with php artisan aurora:make-block.'))
                    ->icon('phosphor-squares-four')
                    ->footer([
                        Action::make('howToMakeABlock')
                            ->label(__('How to make a block'))
                            ->icon('phosphor-book-open')
                            ->color('gray')
                            ->url(Aurora::docs('building-a-site/creating-blocks'), shouldOpenInNewTab: true),
                    ])
                    ->contained(false),
            ];
        }

        return [
            Group::make(collect(Blocks::all())->values()->map(fn (string $block): Flex => Flex::make([
                filled($preview = Blocks::preview($block::type()))
                    ? Image::make($preview, '')->imageWidth('5rem')->imageHeight('3rem')->extraAttributes(['class' => 'cms-block-preview-thumb'])->grow(false)
                    : Icon::make($block::icon() ?: 'phosphor-stack')->color('gray')->grow(false),
                Group::make([
                    Text::make(new HtmlString(e($block::label()).' <span class="cms-format-handle">('.e($block::type()).')</span>'))->weight(FontWeight::Medium)->color('neutral'),
                    Text::make(collect([
                        $block::description(),
                        match (true) {
                            filled($uploaded[$block::type()] ?? null) => __('Your screenshot'),
                            filled($block::preview()) => __('The block’s own picture'),
                            default => __('No picture'),
                        },
                    ])->filter()->implode(' · '))->size('sm'),
                ]),
                Actions::make([
                    $this->uploadBlockPreviewAction($block),
                    $this->resetBlockPreviewAction($block),
                ])->key('block-'.$block::type())->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'cms-format-row']))->all())
                ->extraAttributes(['class' => 'cms-format-list']),
        ];
    }

    /**
     * @param  class-string<Block>  $block
     */
    protected function uploadBlockPreviewAction(string $block): Action
    {
        return Action::make('uploadBlockPreview')
            ->label(__('Upload screenshot'))
            ->icon('phosphor-image')
            ->color('gray')
            ->iconButton()
            ->modalHeading(__('A screenshot of “:block”', ['block' => $block::label()]))
            ->modalDescription(__('Shown beside the block in the visual editor’s block list, so editors see what they’re adding. A wide picture of the block on a page works best.'))
            ->modalIcon('phosphor-image')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Use screenshot'))
            ->schema([
                FileUpload::make('screenshot')
                    ->hiddenLabel()
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('block-previews')
                    ->maxSize(4096)
                    ->required(),
            ])
            ->action(function (array $data) use ($block): void {
                $this->putBlockPreview($block::type(), $data['screenshot']);
                $this->rebuildForm();

                Notification::make()->title(__('Screenshot saved'))->success()->send();
            });
    }

    /**
     * @param  class-string<Block>  $block
     */
    protected function resetBlockPreviewAction(string $block): Action
    {
        return Action::make('resetBlockPreview')
            ->label(__('Remove screenshot'))
            ->icon('phosphor-arrow-counter-clockwise')
            ->color('gray')
            ->iconButton()
            ->visible(fn (): bool => filled((Setting::get(Blocks::PREVIEWS_SETTING) ?? [])[$block::type()] ?? null))
            ->requiresConfirmation()
            ->modalHeading(__('Remove the screenshot of “:block”?', ['block' => $block::label()]))
            ->modalDescription(fn (): string => filled($block::preview())
                ? __('The block list shows the block’s own picture again.')
                : __('The block list shows no picture for it.'))
            ->modalSubmitActionLabel(__('Remove'))
            ->action(function () use ($block): void {
                $this->putBlockPreview($block::type(), null);
                $this->rebuildForm();

                Notification::make()->title(__('Screenshot removed'))->success()->send();
            });
    }

    /**
     * Keep a block's screenshot (or none), and the file of the one it replaces goes.
     */
    protected function putBlockPreview(string $type, ?string $path): void
    {
        $previews = Setting::get(Blocks::PREVIEWS_SETTING) ?? [];

        if (filled($previews[$type] ?? null) && $previews[$type] !== $path) {
            Storage::disk('public')->delete($previews[$type]);
        }

        $previews[$type] = $path;

        Setting::put([Blocks::PREVIEWS_SETTING => array_filter($previews)]);
    }
}
