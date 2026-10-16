<?php

namespace Aurora\Filament\Actions;

use Aurora\Enums\AccessLevel;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Support\AssetAccess;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

/**
 * "Access": who may read, or read and change, a file or folder in the media library. It
 * either follows the folder it's in, or has its own list of rules (AssetAccess). Offered to
 * people with "Change who has access" who may change the item; nobody can take their own
 * write access away (Super Admins always keep theirs).
 */
class AccessAction
{
    /**
     * For one file or folder.
     *
     * @param  Closure(array<string, mixed>): (Asset|AssetFolder|null)  $item  the file or folder, given the action's arguments
     */
    public static function make(string $name, Closure $item): Action
    {
        $isFolder = fn (array $arguments): bool => $item($arguments) instanceof AssetFolder;

        return Action::make($name)
            ->label(__('Access'))
            ->icon('phosphor-lock-key')
            ->color('gray')
            ->visible(fn (array $arguments): bool => ($found = $item($arguments)) !== null && static::canChange($found))
            ->modalIcon('phosphor-lock-key')
            ->modalHeading(fn (array $arguments): string => __('Access to “:name”', ['name' => static::name($item($arguments))]))
            ->modalDescription(fn (array $arguments): string => $isFolder($arguments)
                ? __('Who can see this folder and what’s in it, and who can change them. Subfolders and files follow it unless they have their own.')
                : __('Who can see this file in the admin, and who can change it. Where it’s used on the website, it still shows.'))
            ->modalWidth('xl')
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (array $arguments): array => static::fill($item($arguments)))
            ->schema(fn (array $arguments): array => static::schema($item($arguments)))
            ->action(function (array $data, array $arguments, Action $action) use ($item): void {
                static::save(collect([$item($arguments)]), $data, $action);
            });
    }

    /**
     * For the files selected in the media library: the same access for all of them.
     */
    public static function bulk(): BulkAction
    {
        $first = fn (BulkAction $action): ?Asset => $action->getSelectedRecords()->first();

        return BulkAction::make('access')
            ->label(__('Access'))
            ->icon('phosphor-lock-key')
            ->visible(fn (): bool => auth()->user()->can('manage-access Asset'))
            ->modalIcon('phosphor-lock-key')
            ->modalHeading(fn (BulkAction $action): string => trans_choice('Access to :count file|Access to :count files', $action->getSelectedRecords()->count()))
            ->modalDescription(__('Who can see these files in the admin, and who can change them; the same for each. Where they’re used on the website, they still show.'))
            ->modalWidth('xl')
            ->modalSubmitActionLabel(__('Save'))
            // Starting from the first one's.
            ->fillForm(fn (BulkAction $action): array => static::fill($first($action)))
            ->schema(fn (BulkAction $action): array => static::schema($first($action)))
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                static::save($records, $data, $action);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Whether someone may change who has access: they have the permission for it (Editors and
     * Super Admins to start with) and may change the item itself.
     */
    protected static function canChange(Asset|AssetFolder $item): bool
    {
        return auth()->user()->can('manage-access Asset') && AssetAccess::canWrite(auth()->user(), $item);
    }

    protected static function name(Asset|AssetFolder|null $item): string
    {
        return (string) ($item instanceof AssetFolder ? $item->name : $item?->title);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fill(Asset|AssetFolder|null $item): array
    {
        if ($item === null) {
            return ['access' => AssetAccess::INHERIT, 'rules' => []];
        }

        $inherited = ($from = AssetAccess::source($item)) ? AssetAccess::rules($from) : null;

        return [
            'access' => $item->access,
            // Starting from what applies now, to adjust rather than build from nothing.
            'rules' => $item->access === AssetAccess::CUSTOM
                ? AssetAccess::rules($item)
                : ($inherited ?? [['grantee' => 'everyone', 'level' => AccessLevel::Write->value]]),
        ];
    }

    /**
     * "Same as its folder" or "Only these", and the rules.
     *
     * @return array<int, mixed>
     */
    protected static function schema(Asset|AssetFolder|null $item): array
    {
        $source = $item ? AssetAccess::source($item) : null;

        return [
            ToggleButtons::make('access')
                ->hiddenLabel()
                ->options([
                    AssetAccess::INHERIT => $source ? __('Same as “:folder”', ['folder' => $source->name]) : __('Everyone'),
                    AssetAccess::CUSTOM => __('Only these'),
                ])
                ->icons([AssetAccess::INHERIT => 'phosphor-arrow-bend-left-up', AssetAccess::CUSTOM => 'phosphor-users-three'])
                ->inline()
                ->grouped()
                ->live(),
            Text::make(AssetAccess::describe($source ? AssetAccess::rules($source) : null))
                ->visible(fn (Get $get): bool => $get('access') !== AssetAccess::CUSTOM),
            // One row per rule: who, what they can do, and a button to remove it at the end.
            Repeater::make('rules')
                ->hiddenLabel()
                ->columns(['default' => 1, 'sm' => 12])
                ->extraAttributes(['class' => 'cms-access-rules'])
                ->schema([
                    // People with their picture, as wherever the admin names someone.
                    Select::make('grantee')
                        ->hiddenLabel()
                        ->placeholder(__('Who'))
                        ->columnSpan(['sm' => 8])
                        ->options(fn (): array => AssetAccess::grantees(badges: true))
                        ->allowHtml()
                        ->searchable()
                        ->required()
                        ->distinct(),
                    // A compact choice next to who it's for.
                    Select::make('level')
                        ->hiddenLabel()
                        ->columnSpan(['sm' => 4])
                        ->options(AccessLevel::class)
                        ->default(AccessLevel::Read->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                ])
                ->reorderable(false)
                ->addActionLabel(__('Add someone'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('access') === AssetAccess::CUSTOM),
        ];
    }

    /**
     * The same access for each item someone may change; nobody takes their own write away.
     *
     * @param  Collection<int, Asset|AssetFolder>  $items
     * @param  array<string, mixed>  $data
     */
    protected static function save(Collection $items, array $data, Action $action): void
    {
        // The level comes back as the enum its field offers: stored and compared as its value.
        $rules = array_map(fn (array $rule): array => [
            'grantee' => (string) $rule['grantee'],
            'level' => $rule['level'] instanceof AccessLevel ? $rule['level']->value : (string) $rule['level'],
        ], array_values($data['rules'] ?? []));

        if ($data['access'] === AssetAccess::CUSTOM && ! AssetAccess::keepsWrite(auth()->user(), $rules)) {
            Notification::make()
                ->title(__('You’d lose access yourself'))
                ->body(__('Give yourself, one of your roles or everyone read & write access, so you can still change it.'))
                ->warning()
                ->send();
            $action->halt();
        }

        $changeable = $items->filter(fn (Asset|AssetFolder|null $item): bool => $item !== null && static::canChange($item));
        $changeable->each(fn (Asset|AssetFolder $item) => AssetAccess::put($item, $data['access'], $rules));

        Notification::make()
            ->title(__('Access saved'))
            ->body($changeable->count() < $items->count() ? trans_choice('Skipped :count you can’t change.|Skipped :count you can’t change.', $items->count() - $changeable->count()) : null)
            ->success()
            ->send();
    }
}
