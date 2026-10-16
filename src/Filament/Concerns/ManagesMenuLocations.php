<?php

namespace Aurora\Filament\Concerns;

use Aurora\Models\MenuLocation;
use Aurora\Models\NavigationLocation;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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

/**
 * The places menus can be shown (NavigationLocation): in a dialog from the menus list's "More"
 * menu, and in Settings › Menu locations. One row each, changed in a dialog of its own; the
 * website's templates ask for a location by its handle. Changes save straight away.
 */
trait ManagesMenuLocations
{
    protected function menuLocationsAction(): Action
    {
        return Action::make('menuLocations')
            ->label(__('Manage locations'))
            ->icon('phosphor-map-pin')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->can('manage-settings'))
            ->modalHeading(__('Menu locations'))
            ->modalDescription(__('The places in the website’s templates a menu can be shown. Put a menu in one with “Locations”.'))
            ->modalIcon('phosphor-map-pin')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(fn (): array => $this->menuLocationRows());
    }

    /**
     * The locations, a row each, and "Add location".
     *
     * @return array<int, Component>
     */
    protected function menuLocationRows(): array
    {
        $menus = MenuLocation::query()->with('menu')->get()->groupBy('location');

        return [
            Group::make(NavigationLocation::cached()->map(fn (NavigationLocation $location): Flex => Flex::make([
                Icon::make('phosphor-map-pin')->color('gray')->grow(false),
                Group::make([
                    Text::make(new HtmlString(e(__($location->name)).' <span class="cms-format-handle">('.e($location->handle).')</span>'))->weight(FontWeight::Medium)->color('neutral'),
                    Text::make(collect([
                        $location->description === null ? null : __($location->description),
                        ($names = ($menus[$location->handle] ?? collect())->map(fn (MenuLocation $assigned): ?string => $assigned->menu?->name)->filter()->unique()->implode(', ')) !== ''
                            ? __('Shows :menus', ['menus' => $names])
                            : __('No menu yet'),
                    ])->filter()->implode(' · '))->size('sm'),
                ]),
                Actions::make([
                    $this->editMenuLocationAction($location),
                    $this->deleteMenuLocationAction($location),
                ])->key('menu-location-'.$location->id)->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'cms-format-row']))->all())
                ->extraAttributes(['class' => 'cms-format-list']),
            Actions::make([$this->editMenuLocationAction(null)])->key('add-menu-location'),
        ];
    }

    /**
     * One location, in a dialog of its own: added (no $location) or renamed.
     */
    protected function editMenuLocationAction(?NavigationLocation $location): Action
    {
        $isNew = $location === null;

        return Action::make($isNew ? 'addMenuLocation' : 'editMenuLocation')
            ->label($isNew ? __('Add location') : __('Edit'))
            ->icon($isNew ? 'phosphor-plus-bold' : 'phosphor-pencil-simple')
            ->color('gray')
            ->when(! $isNew, fn (Action $action): Action => $action->iconButton())
            ->overlayParentActions()
            ->after(fn () => $this->refreshMenuLocationsDialog())
            ->modalHeading($isNew ? __('Add a location') : __('Edit “:name”', ['name' => __($location->name)]))
            ->modalDescription(__('A place in the website’s templates a menu can be shown. The templates ask for it by its handle.'))
            ->modalIcon('phosphor-map-pin')
            ->modalWidth('lg')
            ->modalSubmitActionLabel($isNew ? __('Add location') : __('Save'))
            ->fillForm(fn (): array => $isNew ? [] : [
                'name' => $location->name,
                'handle' => $location->handle,
                'description' => $location->description,
            ])
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->placeholder(__('e.g. Sidebar'))
                    ->required()
                    ->maxLength(60)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $isNew && blank($get('handle')) ? $set('handle', Str::slug((string) $state)) : null),
                TextInput::make('handle')
                    ->label(__('Handle'))
                    ->prefixIcon('phosphor-code')
                    ->helperText($isNew ? __('What the templates ask for it by: Menu::location(\'handle\'). It can’t be changed later.') : __('What the templates ask for it by. It can’t be changed.'))
                    ->required($isNew)
                    ->disabled(! $isNew)
                    ->maxLength(30)
                    ->when($isNew, fn (TextInput $input): TextInput => $input
                        ->regex('/^[a-z0-9][a-z0-9-]*$/')
                        ->unique(NavigationLocation::class, 'handle'))
                    ->validationMessages(['regex' => __('Use lowercase letters, numbers and dashes.')]),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->placeholder(__('e.g. Links beside the blog posts.'))
                    ->maxLength(120),
            ])
            ->action(function (array $data) use ($location, $isNew): void {
                $isNew
                    ? NavigationLocation::query()->create([...$data, 'sort' => (int) NavigationLocation::query()->max('sort') + 1])
                    : $location->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);

                Notification::make()
                    ->title($isNew ? __('Added “:name”', ['name' => $data['name']]) : __('Saved “:name”', ['name' => $data['name']]))
                    ->success()
                    ->send();
            });
    }

    protected function deleteMenuLocationAction(NavigationLocation $location): Action
    {
        return Action::make('deleteMenuLocation')
            ->label(__('Delete'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->iconButton()
            ->overlayParentActions()
            ->after(fn () => $this->refreshMenuLocationsDialog())
            ->requiresConfirmation()
            ->modalHeading(__('Delete “:name”?', ['name' => __($location->name)]))
            ->modalDescription(__('Its menus stay, but they’re no longer shown there. Templates asking for it show no menu.'))
            ->action(function () use ($location): void {
                $location->delete();

                Notification::make()->title(__('Deleted “:name”', ['name' => __($location->name)]))->success()->send();
            });
    }

    /**
     * Build the list again so it shows the change: the dialog behind a location's dialog, or
     * the page's form where the list is part of it (Settings › Menu locations).
     */
    protected function refreshMenuLocationsDialog(): void
    {
        $index = array_key_last($this->mountedActions) - 1;

        if ($index >= 0) {
            $this->cacheSchema("mountedActionSchema{$index}", fn (): ?Schema => $this->getMountedActionSchema($index));
        } else {
            unset($this->cachedSchemas['form']);
        }
    }
}
