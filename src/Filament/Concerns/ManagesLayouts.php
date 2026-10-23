<?php

namespace Aurora\Filament\Concerns;

use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Support\Layouts;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Wallacemartinss\FilamentIconPicker\Forms\Components\IconPickerField;

/**
 * The "Layouts" dialog, opened from the pages list's "More" menu: the layouts editors pick
 * for a page, one row each, changed one at a time in a dialog of their own. Each draws pages
 * with a Blade view (Aurora\Support\Layouts); changes save straight away. Layouts defined in
 * code are listed with the others and can be made the default, but are their code's to change.
 */
trait ManagesLayouts
{
    protected function layoutsAction(): Action
    {
        return Action::make('layouts')
            ->label(__('Layouts'))
            ->icon('phosphor-layout')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->can('manage-settings'))
            ->modalHeading(__('Layouts'))
            ->modalDescription(__('How pages are drawn on the website. Each page picks one; new pages start with the default.'))
            ->modalIcon('phosphor-layout')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(fn (): array => $this->layoutRows());
    }

    /**
     * The layouts, a row each, and "Add layout": in the dialog, and in Settings › Layouts.
     *
     * @return array<int, Component>
     */
    protected function layoutRows(): array
    {
        $pages = Page::query()->toBase()->selectRaw('layout_id, count(*) as aggregate')->whereNull('deleted_at')->groupBy('layout_id')->pluck('aggregate', 'layout_id');

        return [
            Group::make(Layout::cached()->map(fn (Layout $layout): Flex => Flex::make([
                Icon::make($layout->icon ?: 'phosphor-app-window')->color('gray')->grow(false),
                Group::make([
                    Text::make(new HtmlString(e($layout->name).' <span class="cms-format-handle">('.e($layout->handle).')</span>'))->weight(FontWeight::Medium)->color('neutral'),
                    Text::make(collect([
                        $layout->description,
                        trans_choice(':count page|:count pages', (int) ($pages[$layout->getKey()] ?? 0)),
                    ])->filter()->implode(' · '))->size('sm'),
                ]),
                Text::make(__('From code'))->badge()->color('gray')->tooltip($layout->class)->visible($layout->isFromCode())->grow(false),
                Text::make(__('Default'))->badge()->color('primary')->visible($layout->is_default)->grow(false),
                // One defined in code is its code's: it can only be made the default.
                Actions::make($layout->isFromCode()
                    ? [$this->makeDefaultLayoutAction($layout)]
                    : [$this->makeDefaultLayoutAction($layout), $this->editLayoutAction($layout), $this->deleteLayoutAction($layout)])
                    ->key('layout-'.$layout->id)
                    ->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'cms-format-row']))->all())
                ->extraAttributes(['class' => 'cms-format-list']),
            Actions::make([$this->editLayoutAction(null)])->key('add-layout'),
        ];
    }

    /**
     * One layout, in a dialog of its own: added (no $layout) or changed.
     */
    protected function editLayoutAction(?Layout $layout): Action
    {
        $isNew = $layout === null;

        return Action::make($isNew ? 'addLayout' : 'editLayout')
            ->label($isNew ? __('Add layout') : __('Edit'))
            ->icon($isNew ? 'phosphor-plus-bold' : 'phosphor-pencil-simple')
            ->color('gray')
            ->when(! $isNew, fn (Action $action): Action => $action->iconButton())
            // Over the "Layouts" dialog, which stays open behind it and shows the change.
            ->overlayParentActions()
            ->after(fn () => $this->refreshLayoutsDialog())
            ->modalHeading($isNew ? __('Add a layout') : __('Edit “:name”', ['name' => $layout->name]))
            ->modalDescription(__('A layout draws pages with a Blade view of the website.'))
            ->modalIcon('phosphor-layout')
            ->modalWidth('lg')
            ->modalSubmitActionLabel($isNew ? __('Add layout') : __('Save'))
            ->fillForm(fn (): array => $isNew
                ? ['view' => 'aurora::layouts.standard']
                : [
                    'name' => $layout->name,
                    'handle' => $layout->handle,
                    'description' => $layout->description,
                    'icon' => $layout->icon,
                    'view' => $layout->view,
                    'is_default' => $layout->is_default,
                ])
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->placeholder(__('e.g. Campaign'))
                    ->required()
                    ->maxLength(60)
                    // A new layout's handle follows its name until it's typed in.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $isNew && blank($get('handle')) ? $set('handle', Str::slug((string) $state)) : null),
                TextInput::make('handle')
                    ->label(__('Handle'))
                    ->prefixIcon('phosphor-code')
                    ->helperText($isNew ? __('What code asks for this layout by. It can’t be changed later.') : __('What code asks for this layout by. It can’t be changed.'))
                    ->required($isNew)
                    ->disabled(! $isNew)
                    ->maxLength(30)
                    ->when($isNew, fn (TextInput $input): TextInput => $input
                        ->regex('/^[a-z0-9][a-z0-9-]*$/')
                        ->unique(Layout::class, 'handle'))
                    ->validationMessages(['regex' => __('Use lowercase letters, numbers and dashes.')]),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->placeholder(__('e.g. No menu or footer, for campaigns.'))
                    ->helperText(__('Shown with the layout when choosing one for a page.'))
                    ->maxLength(120),
                IconPickerField::make('icon')
                    ->label(__('Icon'))
                    ->allowedSets(['phosphor-icons'])
                    ->showSetFilter(false)
                    ->placeholder(__('Choose an icon')),
                TextInput::make('view')
                    ->label(__('Blade view'))
                    ->placeholder('layouts.campaign')
                    ->required()
                    ->maxLength(255)
                    ->prefixIcon('phosphor-file-code')
                    ->extraInputAttributes(['class' => 'font-mono'])
                    // Checked as it's typed in, and on save: the view must resolve to a file.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (TextInput $component) => $component->getLivewire()->validateOnly($component->getStatePath()))
                    ->helperText(fn (?string $state): string => filled($state) && view()->exists($state)
                        ? __('Found: :file', ['file' => Str::after(view()->getFinder()->find($state), base_path().DIRECTORY_SEPARATOR)])
                        : __('The Blade view that draws the page, like aurora::layouts.standard or layouts.campaign.'))
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_string($value) || ! view()->exists($value)) {
                            $fail(__('There’s no view called “:view”.', ['view' => $value]));
                        }
                    }),
                Toggle::make('is_default')
                    ->label(__('Default layout'))
                    ->helperText(fn (): string => $layout?->is_default
                        ? __('The default for new pages. To change it, make another layout the default.')
                        : __('New pages start with this layout.'))
                    ->disabled((bool) $layout?->is_default),
            ])
            ->action(function (array $data) use ($layout, $isNew): void {
                Layouts::put($layout, $data);

                Notification::make()
                    ->title($isNew ? __('Added “:name”', ['name' => $data['name']]) : __('Saved “:name”', ['name' => $data['name']]))
                    ->success()
                    ->send();
            });
    }

    protected function makeDefaultLayoutAction(Layout $layout): Action
    {
        return Action::make('makeDefaultLayout')
            ->label(__('Make default'))
            ->tooltip(__('Make default'))
            ->icon('phosphor-star')
            ->color('gray')
            ->iconButton()
            ->hidden($layout->is_default)
            ->after(fn () => $this->refreshLayoutsDialog())
            ->action(function () use ($layout): void {
                Layouts::makeDefault($layout);

                Notification::make()->title(__('“:name” is the default layout', ['name' => $layout->name]))->success()->send();
            });
    }

    protected function deleteLayoutAction(Layout $layout): Action
    {
        return Action::make('deleteLayout')
            ->label(__('Delete'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->iconButton()
            // The default stays: another layout becomes the default first.
            ->hidden($layout->is_default)
            ->overlayParentActions()
            ->after(fn () => $this->refreshLayoutsDialog())
            ->requiresConfirmation()
            ->modalHeading(__('Delete “:name”?', ['name' => $layout->name]))
            ->modalDescription(fn (): string => __('Pages with this layout get the default one, “:default”.', ['default' => Layout::default()?->name]))
            ->action(function () use ($layout): void {
                Layouts::remove($layout);

                Notification::make()->title(__('Deleted “:name”', ['name' => $layout->name]))->success()->send();
            });
    }

    /**
     * Build the list again so it shows the change: the "Layouts" dialog behind a layout's
     * dialog, or the page's form where the list is part of it (Settings › Layouts).
     */
    protected function refreshLayoutsDialog(): void
    {
        $index = array_key_last($this->mountedActions) - 1;

        if ($index >= 0) {
            $this->cacheSchema("mountedActionSchema{$index}", fn (): ?Schema => $this->getMountedActionSchema($index));
        } else {
            $this->rebuildForm();
        }
    }
}
