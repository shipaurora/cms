<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use LaraZeus\SpatieTranslatable\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreatePage extends CreateRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use FollowsActiveLocale;
    use SavesFromHeader;
    use Translatable {
        Translatable::updatedActiveLocale as updatedTranslatableActiveLocale;
        FollowsActiveLocale::updatedActiveLocale insteadof Translatable;
    }

    /**
     * A new page is designed straight after it is created, so there is no "create another".
     */
    protected static bool $canCreateAnother = false;

    protected static string $resource = PageResource::class;

    /**
     * Opened from the "New page" prompt (?title=…): the title, and the address made from it,
     * in the site's default language; from "Create subpage" (&parent=…) under that page.
     */
    protected function afterFill(): void
    {
        $title = request()->query('title');

        if (! is_string($title) || blank($title)) {
            return;
        }

        $this->activeLocale = ContentLocale::default();
        ContentLocale::set($this->activeLocale);

        $this->form->fill([
            ...$this->form->getRawState(),
            'title' => Str::limit(trim($title), 255, ''),
            'slug' => Page::uniqueSlug($title, $this->activeLocale),
            // "Create subpage" (?parent=…): under that page.
            'parent_id' => Page::query()->find(request()->query('parent'))?->getKey(),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $template = Page::query()->find($this->data['start_from'] ?? null);

        if ($template !== null) {
            // Fresh ids: two pages sharing block ids would confuse the canvas's history.
            $data['blocks'] = array_map(fn (array $block): array => $block, $this->remintIds(BlockTree::hydrate($template->blocks ?? [])));
        }

        return $data;
    }

    /**
     * A new page goes straight to the canvas: that is where it gets written.
     */
    /**
     * The new page's settings; the visual editor is one click away from there.
     */
    protected function getRedirectUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    protected function remintIds(array $blocks): array
    {
        $map = collect($blocks)->mapWithKeys(fn (array $block): array => [$block['id'] => (string) Str::uuid()])->all();

        return array_map(fn (array $block): array => [
            ...$block,
            'id' => $map[$block['id']],
            'parent' => $block['parent'] !== null ? ($map[$block['parent']] ?? null) : null,
        ], $blocks);
    }

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count(ContentLocale::all()) > 1),
            $this->headerSaveButton(),
        ];
    }
}
