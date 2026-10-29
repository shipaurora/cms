<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Models\PageTemplate;
use Aurora\Support\ContentLocale;
use Aurora\Support\PageTemplates;
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
     * The page template it starts from (?template=…, from the "New page" prompt), if any.
     */
    public ?string $templateId = null;

    /**
     * Opened from the "New page" prompt (?title=…): the title, and the address made from it,
     * in the site's default language; from "Create subpage" (&parent=…) under that page.
     */
    protected function afterFill(): void
    {
        // From a template: its layout and the layout's fields, to see and change before creating;
        // its blocks come with it when the page is created.
        if (($template = PageTemplate::query()->find(request()->query('template'))) !== null) {
            $this->templateId = $template->getKey();

            $this->form->fill([
                ...$this->form->getRawState(),
                'layout_id' => $template->layout_id ?? $this->form->getRawState()['layout_id'] ?? null,
                ...(Page::storesSettings() ? ['settings' => $template->settings ?? []] : []),
            ]);
        }

        $title = request()->query('title');

        if (! is_string($title) || blank($title)) {
            return;
        }

        $this->activeLocale = ContentLocale::default();
        ContentLocale::set($this->activeLocale);

        // "Create subpage" (?parent=…): under that page, its address free among the pages there.
        $parentId = Page::query()->find(request()->query('parent'))?->getKey();

        $this->form->fill([
            ...$this->form->getRawState(),
            'title' => Str::limit(trim($title), 255, ''),
            'slug' => Page::uniqueSlug($title, $this->activeLocale, parentId: $parentId),
            'parent_id' => $parentId,
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // A page template's blocks and their translations (the layout came with the form), or
        // another page's ("Start from").
        $template = $this->templateId !== null ? PageTemplate::query()->find($this->templateId) : null;
        $from = $template ?? Page::query()->find($this->data['start_from'] ?? null);

        if ($from !== null) {
            $content = PageTemplates::content($from);
            $data['blocks'] = $content['blocks'];
            $data['block_translations'] = $content['block_translations'];
        }

        if ($template !== null) {
            $data['template_id'] = $template->getKey();
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

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count(ContentLocale::all()) > 1),
            $this->headerSaveButton(),
        ];
    }
}
