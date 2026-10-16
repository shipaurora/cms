<?php

namespace Aurora\Support;

use Aurora\Models\User;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Choosing people, the same way everywhere: a search field over a list of people with their
 * avatar, name, job title and roles. Goes in an action's modal (see "Add people" on a role);
 * the chosen user ids end up in the action's data as "selection". With one person to pick,
 * clicking them is the whole job, like the image picker.
 */
class PeoplePicker
{
    /**
     * @param  Closure(): Builder<User>|null  $query  who can be chosen (default: everyone)
     * @return array<int, mixed>
     */
    public static function schema(?Closure $query = null, bool $multiple = true): array
    {
        return [
            TextInput::make('search')
                ->hiddenLabel()
                ->placeholder(__('Search by name, email or job title…'))
                ->prefixIcon('phosphor-magnifying-glass')
                ->autofocus()
                ->live(debounce: 250),
            ViewField::make('selection')
                ->hiddenLabel()
                ->view('aurora::filament.forms.components.people-list')
                ->viewData(fn (Get $get): array => [
                    'people' => static::search((string) $get('search'), $query),
                    'multiple' => $multiple,
                ])
                ->default($multiple ? [] : null),
        ];
    }

    /**
     * @param  Closure(): Builder<User>|null  $query
     * @return Collection<int, User>
     */
    public static function search(string $search, ?Closure $query = null): Collection
    {
        $search = trim($search);

        return ($query ? $query() : User::query())
            ->with('roles')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * The chosen user ids from an action's data.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function chosen(array $data): array
    {
        return array_values(array_filter((array) ($data['selection'] ?? []), 'is_string'));
    }

    /**
     * A person as a select or filter option: their avatar and name.
     */
    public static function option(User $user): string
    {
        return (string) UserBadge::html($user);
    }
}
