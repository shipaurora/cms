<?php

namespace Aurora\Filament\Resources\SharedSections\Pages;

use Aurora\Blocks\SharedSectionBlock;
use Aurora\Filament\Concerns\EditsOnCanvas;
use Aurora\Filament\Resources\SharedSections\SharedSectionResource;
use Aurora\Support\EditLocks;
use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

/**
 * A shared section on the canvas: built like a page, shown wherever it's placed.
 */
class DesignSharedSection extends BaseDesignPage
{
    use EditsOnCanvas;

    protected static string $resource = SharedSectionResource::class;

    protected ?BlockRegistry $sectionRegistry = null;

    public function exitUrl(): ?string
    {
        return SharedSectionResource::getUrl('index');
    }

    /**
     * Every block but a shared section: one inside another isn't offered (it could end up
     * inside itself).
     */
    public function registry(): BlockRegistry
    {
        if ($this->sectionRegistry === null) {
            $this->sectionRegistry = clone parent::registry();

            (fn () => $this->blocks = Arr::except($this->blocks, SharedSectionBlock::type()))->call($this->sectionRegistry);
        }

        return $this->sectionRegistry;
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
