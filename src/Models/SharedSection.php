<?php

namespace Aurora\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Support\BlockTranslations;
use Aurora\Support\ContentLocale;
use CarlJanzell\FilamentPageBuilder\Concerns\HasBlocks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A section built once and placed on many pages with the "Shared section" block (a call to
 * action, a contact strip): edited in one place, under Content › Shared sections, it
 * changes on every page that shows it. Written in the default site language, with each
 * other language's text kept by block, as a page's is.
 */
#[Fillable(['name', 'blocks', 'block_translations'])]
class SharedSection extends Model
{
    use BelongsToSite, HasBlocks, HasFactory, HasUuids;

    protected string $blocksAttribute = 'blocks';

    /**
     * The sections being drawn right now, so one that (through others) shows itself stops.
     *
     * @var array<int, string>
     */
    protected static array $drawing = [];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'block_translations' => 'array',
        ];
    }

    /**
     * Its blocks in a language: the one structure, with that language's text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function localizedBlocks(?string $locale = null): array
    {
        $locale ??= ContentLocale::current();

        return BlockTranslations::apply($this->blocks ?? [], $locale === ContentLocale::default() ? [] : ($this->block_translations[$locale] ?? []));
    }

    /**
     * Draw it, unless it's already being drawn further up (a section inside itself).
     */
    public function draw(\Closure $draw): string
    {
        if (in_array($this->getKey(), static::$drawing, true)) {
            return '';
        }

        static::$drawing[] = $this->getKey();

        try {
            return (string) $draw();
        } finally {
            array_pop(static::$drawing);
        }
    }

    /**
     * The pages that show it.
     *
     * @return Builder<Page>
     */
    public function pages(): Builder
    {
        return Page::query()->where('blocks', 'like', '%"shared_section_id":"'.$this->getKey().'"%');
    }
}
