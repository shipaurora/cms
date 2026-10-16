<?php

namespace Aurora\Models;

use Aurora\Database\Factories\NavigationLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;

/**
 * A place in the website's templates a menu can be shown, by its handle
 * (Menu::location('header')): set up under Menus › More › Manage locations, and given a menu
 * with "Locations". Removing one takes its menus out of it.
 */
#[Fillable(['handle', 'name', 'description', 'sort'])]
class NavigationLocation extends Model
{
    /** @use HasFactory<NavigationLocationFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());

        static::deleted(function (self $location): void {
            MenuLocation::query()->where('location', $location->handle)->delete();
            Once::flush();
        });
    }

    /**
     * Every location, in order, read once per request.
     *
     * @return Collection<int, NavigationLocation>
     */
    public static function cached(): Collection
    {
        return once(fn (): Collection => static::query()->orderBy('sort')->orderBy('name')->get());
    }

    /**
     * Their names by handle, in the person's language: what the menu builder offers.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::cached()->mapWithKeys(fn (self $location): array => [$location->handle => __($location->name)])->all();
    }
}
