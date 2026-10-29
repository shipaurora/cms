<?php

namespace Aurora\Filament\Concerns;

use Aurora\Filament\Resources\PageTemplates\PageTemplateResource;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\PageTemplate;
use Aurora\Support\PageTemplates;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;

/**
 * The site's page templates, a row each with what each can do, and "New page template": in
 * Settings › Templates, and in a dialog from the page list (for those who manage templates but
 * not settings). A template is designed in the visual editor, like a page; new pages can start
 * from it (Aurora\Support\PageTemplates). Pages made from a template keep their content when it
 * changes or goes.
 */
trait ManagesPageTemplates
{
    protected static function managesPageTemplates(): bool
    {
        return Page::storesTemplates() && (bool) auth()->user()?->can('manage-templates Page');
    }

    protected function pageTemplatesAction(): Action
    {
        return Action::make('pageTemplates')
            ->label(__('Page templates'))
            ->icon('phosphor-files')
            ->color('gray')
            ->visible(fn (): bool => static::managesPageTemplates())
            ->modalHeading(__('Page templates'))
            ->modalDescription(__('Pages new pages can start from: their layout and blocks, ready to fill in.'))
            ->modalIcon('phosphor-files')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(fn (): array => $this->pageTemplateRows());
    }

    /**
     * @return array<int, Component>
     */
    protected function pageTemplateRows(): array
    {
        $templates = PageTemplate::query()->orderBy('created_at')->get();
        $made = Page::query()->toBase()->selectRaw('template_id, count(*) as aggregate')->whereNotNull('template_id')->whereNull('deleted_at')->groupBy('template_id')->pluck('aggregate', 'template_id');

        return [
            Text::make(__('No page templates yet. Make one here, or from a page with “Create template from this page” in its menu.'))
                ->color('gray')
                ->visible($templates->isEmpty()),
            Group::make($templates->map(fn (PageTemplate $template): Flex => Flex::make([
                Icon::make('phosphor-file-dashed')->color('gray')->grow(false),
                Group::make([
                    Text::make($template->title)->weight(FontWeight::Medium)->color('neutral'),
                    Text::make(collect([
                        $template->excerpt,
                        $template->layoutOrDefault()?->name,
                        trans_choice(':count page made from it|:count pages made from it', (int) ($made[$template->getKey()] ?? 0)),
                    ])->filter()->implode(' · '))->size('sm'),
                ]),
                Actions::make([
                    Action::make('designPageTemplate')
                        ->label(__('Design'))
                        ->tooltip(__('Design'))
                        ->icon('phosphor-paint-brush')
                        ->color('gray')
                        ->iconButton()
                        ->url(PageTemplateResource::getUrl('design', ['record' => $template])),
                    $this->editPageTemplateAction($template),
                    $this->duplicatePageTemplateAction($template),
                    $this->deletePageTemplateAction($template),
                ])->key('page-template-'.$template->getKey())->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'cms-format-row']))->all())
                ->extraAttributes(['class' => 'cms-format-list']),
            Actions::make([$this->editPageTemplateAction(null)])->key('add-page-template'),
        ];
    }

    /**
     * A template's name, description and layout, in a dialog of its own: a new one goes
     * straight to the visual editor.
     */
    protected function editPageTemplateAction(?PageTemplate $template): Action
    {
        $isNew = $template === null;

        return Action::make($isNew ? 'addPageTemplate' : 'editPageTemplate')
            ->label($isNew ? __('New page template') : __('Edit'))
            ->icon($isNew ? 'phosphor-plus-bold' : 'phosphor-pencil-simple')
            ->color('gray')
            ->when(! $isNew, fn (Action $action): Action => $action->iconButton()->tooltip(__('Edit')))
            ->overlayParentActions()
            ->after(fn () => $this->refreshPageTemplatesDialog())
            ->modalHeading($isNew ? __('New page template') : __('Edit “:name”', ['name' => $template->title]))
            ->modalDescription(__('Design it in the visual editor next. New pages can start from it.'))
            ->modalIcon('phosphor-files')
            ->modalWidth('lg')
            ->modalSubmitActionLabel($isNew ? __('Create and design') : __('Save'))
            ->fillForm(fn (): array => $isNew
                ? ['layout_id' => Layout::default()?->getKey()]
                : ['name' => $template->title, 'description' => $template->excerpt, 'layout_id' => $template->layout_id ?? Layout::default()?->getKey()])
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->placeholder(__('e.g. News article'))
                    ->required()
                    ->maxLength(80),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->placeholder(__('e.g. A heading, an image and the story.'))
                    ->helperText(__('Shown with the template when starting a new page.'))
                    ->maxLength(160),
                Select::make('layout_id')
                    ->label(__('Layout'))
                    ->options(fn (): array => Layout::cached()->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (array $data) use ($template, $isNew): void {
                if ($isNew) {
                    $template = PageTemplates::create($data['name'], $data['description'] ?? null, $data['layout_id']);
                    $this->redirect(PageTemplateResource::getUrl('design', ['record' => $template]));

                    return;
                }

                $template->update(['title' => $data['name'], 'excerpt' => $data['description'] ?? null, 'layout_id' => $data['layout_id']]);

                Notification::make()->title(__('Saved “:name”', ['name' => $data['name']]))->success()->send();
            });
    }

    protected function duplicatePageTemplateAction(PageTemplate $template): Action
    {
        return Action::make('duplicatePageTemplate')
            ->label(__('Duplicate'))
            ->tooltip(__('Duplicate'))
            ->icon('phosphor-copy-simple')
            ->color('gray')
            ->iconButton()
            ->after(fn () => $this->refreshPageTemplatesDialog())
            ->action(function () use ($template): void {
                $copy = PageTemplates::duplicate($template);

                Notification::make()->title(__('Made “:name”', ['name' => $copy->title]))->success()->send();
            });
    }

    protected function deletePageTemplateAction(PageTemplate $template): Action
    {
        return Action::make('deletePageTemplate')
            ->label(__('Delete'))
            ->tooltip(__('Delete'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->iconButton()
            ->requiresConfirmation()
            ->modalHeading(__('Delete “:name”?', ['name' => $template->title]))
            ->modalDescription(__('New pages can no longer start from it. Pages already made from it keep their content.'))
            ->modalSubmitActionLabel(__('Delete'))
            ->after(fn () => $this->refreshPageTemplatesDialog())
            ->action(function () use ($template): void {
                $template->forceDelete();

                Notification::make()->title(__('Deleted “:name”', ['name' => $template->title]))->success()->send();
            });
    }

    /**
     * Build the list again so it shows the change: the "Page templates" dialog behind, or the
     * page's form where the list is part of it (Settings › Templates).
     */
    protected function refreshPageTemplatesDialog(): void
    {
        $index = array_key_last($this->mountedActions ?? []) - 1;

        if ($index >= 0) {
            $this->cacheSchema("mountedActionSchema{$index}", fn () => $this->getMountedActionSchema($index));
        } elseif (method_exists($this, 'rebuildForm')) {
            $this->rebuildForm();
        }
    }
}
