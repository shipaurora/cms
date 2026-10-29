<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\Page;
use Aurora\Models\PageTemplate;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a new page starts from: "Blank", always first, or one of the site's page templates, as
 * cards with the template drawn small, like the layout picker. Hidden while there are none.
 * Stores the template's id, or nothing for a blank page.
 */
class PageTemplatePicker extends Field
{
    protected string $view = 'aurora::filament.forms.components.page-template-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->default('');
        $this->in(fn (): array => ['', ...$this->getTemplates()->modelKeys()]);
        $this->visible(fn (): bool => $this->getTemplates()->isNotEmpty());
    }

    /**
     * @return Collection<int, PageTemplate>
     */
    public function getTemplates(): Collection
    {
        return Page::storesTemplates() ? PageTemplate::query()->orderBy('created_at')->get() : new Collection;
    }

    /**
     * A signed link to the template drawn with its layout, for its thumbnail.
     */
    public function getThumbnailUrl(PageTemplate $template): string
    {
        return $template->previewUrl(embed: true);
    }
}
