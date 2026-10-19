<?php

namespace Aurora\Filament\Resources\SharedSections\Pages;

use Aurora\Blocks\SharedSectionBlock;
use Aurora\Filament\Concerns\EditsOnCanvas;
use Aurora\Filament\Resources\SharedSections\SharedSectionResource;
use Aurora\Support\EditLocks;
use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use Filament\Notifications\Notification;

/**
 * A shared section on the canvas: built like a page, shown wherever it's placed. It never
 * holds another shared section, which could end up inside itself.
 */
class DesignSharedSection extends BaseDesignPage
{
    use EditsOnCanvas {
        getPaletteProperty as paletteWithPictures;
    }

    protected static string $resource = SharedSectionResource::class;

    public function exitUrl(): ?string
    {
        return SharedSectionResource::getUrl('index');
    }

    /**
     * Every block but a shared section.
     *
     * @return array<int, array{type: string, label: string, icon: ?string, category: string, description: ?string, preview: ?string}>
     */
    public function getPaletteProperty(): array
    {
        return array_values(array_filter($this->paletteWithPictures(), fn (array $item): bool => $item['type'] !== SharedSectionBlock::type()));
    }

    public function insertBlock(string $type, ?int $at = null, ?string $parent = null, ?string $slot = null): ?string
    {
        return $type === SharedSectionBlock::type() ? null : parent::insertBlock($type, $at, $parent, $slot);
    }

    public function save(): void
    {
        // Someone else is editing it: say so, instead of saving over their work.
        if (EditLocks::heldByOther($this->getRecord())) {
            Notification::make()
                ->title(__(':name is editing this section', ['name' => EditLocks::holder($this->getRecord())['name']]))
                ->body(__('Your changes weren’t saved. Take over editing to save them.'))
                ->danger()
                ->send();

            return;
        }

        // Shared sections it held from before aren't kept.
        $this->blocks = array_values(array_filter($this->blocks, fn (array $block): bool => $block['type'] !== SharedSectionBlock::type()));

        parent::save();
    }
}
