<?php

namespace Aurora\Models;

use Aurora\Database\Factories\PageFactory;
use Aurora\Enums\PageStatus;
use Aurora\Events\PagePublished;
use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Observers\PageObserver;
use Aurora\Support\BlockTranslations;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\Layouts;
use Aurora\Support\PageSearch;
use Aurora\Support\Search\ScopedBuilder;
use Aurora\Support\WorkingCopies;
use CarlJanzell\FilamentPageBuilder\Concerns\HasBlocks;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Closure;
use Datlechin\FilamentMenuBuilder\Contracts\MenuPanelable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Searchable;
use Spatie\Sluggable\HasTranslatableSlug;
use Spatie\Sluggable\SlugOptions;
use Spatie\Translatable\HasTranslations;

#[ObservedBy(PageObserver::class)]
#[Hidden(['password'])]
class Page extends Model implements MenuPanelable
{
    /** @use HasFactory<PageFactory> */
    use BelongsToSite, HasBlocks, HasFactory, HasTranslations, Searchable, SoftDeletes;

    // Pages are found by id in the admin and by address on the website (whereSlug).
    use HasTranslatableSlug, HasUuids {
        HasUuids::resolveRouteBindingQuery insteadof HasTranslatableSlug;
    }

    /**
     * Read and written in the content language (ContentLocale): the visitor's on the
     * website, the default site language in the admin unless translating.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'slug', 'excerpt', 'meta_title', 'meta_description'];

    /**
     * First URL segments that belong to the application, never to a page.
     */
    public const RESERVED_SLUGS = ['admin', 'api', 'mcp', 'livewire', 'storage', 'filament', 'up', 'sitemap.xml', 'robots.txt', 'preview', 'oauth'];

    protected string $blocksAttribute = 'blocks';

    protected $guarded = ['id'];

    /**
     * The attributes a revision keeps.
     *
     * @var array<int, string>
     */
    public const SNAPSHOT = ['parent_id', 'title', 'slug', 'excerpt', 'blocks', 'block_translations', 'locales', 'layout_id', 'settings', 'meta_title', 'meta_description', 'og_image', 'noindex', 'exclude_from_search'];

    protected $attributes = [
        'status' => 'draft',
        'noindex' => false,
        'exclude_from_search' => false,
        'members_only' => false,
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'block_translations' => 'array',
            'locales' => 'array',
            'settings' => 'array',
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'unpublish_at' => 'datetime',
            'working_copy' => 'array',
            'working_copy_at' => 'datetime',
            'indexed_at' => 'datetime',
            'is_homepage' => 'boolean',
            'noindex' => 'boolean',
            'exclude_from_search' => 'boolean',
            'members_only' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            // Addresses are made per language by addSlug().
            ->preventOverwrite()
            ->slugsShouldBeNoLongerThan(120);
    }

    public function getLocale(): string
    {
        return $this->translationLocale ?: ContentLocale::current();
    }

    /**
     * Every language with a title gets an address from its own title ("Om oss" gives
     * om-oss), unique among that language's addresses. An address, once there, stays:
     * renaming a page doesn't move it. Never one the application owns ("admin", "mcp"…)
     * or a language's prefix ("nb").
     */
    protected function addSlug(): void
    {
        foreach ($this->getTranslations('title') as $locale => $title) {
            if (blank($title) || filled($this->getTranslation('slug', $locale, false))) {
                continue;
            }

            $this->setTranslation('slug', $locale, static::uniqueSlug($title, $locale, $this->getKey()));
        }
    }

    /**
     * An address made from a title that no other page has in the language: "about-us", else
     * "about-us-1", "about-us-2" and so on.
     */
    public static function uniqueSlug(string $title, ?string $locale = null, ?string $except = null): string
    {
        $base = Str::limit(Str::slug($title), 120, '') ?: 'page';
        $base = static::isReservedSlug($base) ? $base.'-page' : $base;
        $slug = $base;

        for ($n = 1; ! static::slugIsAvailable($slug, $locale, $except); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /**
     * Whether the site has other pages than this one (none yet: this is its first page).
     */
    public static function othersExist(?string $except = null): bool
    {
        return static::query()->when($except, fn (Builder $query) => $query->whereKeyNot($except))->exists();
    }

    public static function isReservedSlug(?string $slug): bool
    {
        $slug = strtolower((string) $slug);

        return in_array($slug, self::RESERVED_SLUGS, true) || array_key_exists($slug, ContentLocale::all());
    }

    /* ── Relations ─────────────────────────────────────── */

    /**
     * Null: the default layout (layoutOrDefault()).
     *
     * @return BelongsTo<Layout, $this>
     */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(Layout::class);
    }

    /**
     * The layout the page is drawn with: its own, else the default.
     */
    public function layoutOrDefault(): ?Layout
    {
        return Layout::cached()->firstWhere('id', $this->layout_id) ?? Layout::default();
    }

    /**
     * Whether pages have their settings column yet: a site that updated Aurora without
     * migrating doesn't, and its pages go without settings until it does.
     */
    public static function storesSettings(): bool
    {
        return once(fn (): bool => Schema::hasColumn((new static)->getTable(), 'settings'));
    }

    /**
     * Everything in the page's settings (each layout view's fields among them, under
     * layouts), empty when it has none or the column isn't there yet.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_key_exists('settings', $this->getAttributes()) ? ($this->settings ?? []) : [];
    }

    /**
     * What the page has in the fields its layout's view asks for (Layouts::schema()): the
     * view's $settings. Another view's are left out, even with the same names.
     *
     * @return array<string, mixed>
     */
    public function layoutSettings(): array
    {
        $view = $this->layoutOrDefault()?->view;
        $settings = filled($view) ? ($this->settings()['layouts'][Layouts::settingsKey($view)] ?? null) : null;

        return is_array($settings) ? $settings : [];
    }

    /**
     * One of its layout's fields ($page->layoutSetting('background')), dot notation for
     * nested values; $default when it hasn't been set.
     */
    public function layoutSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->layoutSettings(), $key, $default);
    }

    /**
     * The page's settings with these values in a layout's fields (the page's own layout, by
     * default), for saving: the layout's other fields and other views' settings are kept.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function settingsWithLayoutValues(array $values, ?Layout $layout = null): array
    {
        $settings = $this->settings();
        $view = ($layout ?? $this->layoutOrDefault())?->view;

        if (filled($view)) {
            $key = Layouts::settingsKey($view);
            $settings['layouts'][$key] = [...($settings['layouts'][$key] ?? []), ...$values];
        }

        return $settings;
    }

    /**
     * What a revision keeps (SNAPSHOT), less settings before the column is there.
     *
     * @return array<int, string>
     */
    public static function snapshotKeys(): array
    {
        return static::storesSettings() ? self::SNAPSHOT : array_values(array_diff(self::SNAPSHOT, ['settings']));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by_id');
    }

    /**
     * The page this one sits under, also while it is in the trash (the address keeps it).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The pages above this one, the top one first.
     *
     * @return array<int, self>
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $seen = [$this->getKey() => true];

        for ($page = $this->parent; $page !== null && ! isset($seen[$page->getKey()]); $page = $page->parent) {
            $seen[$page->getKey()] = true;
            array_unshift($ancestors, $page);
        }

        return $ancestors;
    }

    /**
     * Every page below this one, however deep: the pages it can't be moved under.
     *
     * @return array<int, string>
     */
    public function descendantIds(): array
    {
        $children = static::query()->withTrashed()->whereNotNull('parent_id')->pluck('parent_id', 'id')->groupBy(fn (string $parent): string => $parent, preserveKeys: true);
        $ids = [];
        $queue = [$this->getKey()];

        while ($queue !== []) {
            foreach (array_keys(($children[array_shift($queue)] ?? collect())->all()) as $child) {
                if (! in_array($child, $ids, true)) {
                    $ids[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $ids;
    }

    /**
     * The ancestors' part of the address in a language ("/docs"), or "" for a top-level page.
     * The homepage adds nothing: its pages sit at the top.
     */
    public function parentPath(?string $locale = null): string
    {
        $locale ??= ContentLocale::current();

        return collect($this->ancestors())
            ->reject(fn (self $page): bool => $page->is_homepage)
            ->map(fn (self $page): string => '/'.($page->getTranslation('slug', $page->isAvailableIn($locale) ? $locale : $page->primaryLocale())))
            ->implode('');
    }

    public function revisions(): HasMany
    {
        // Newest first; the id breaks ties within a second (UUIDv7s made together aren't ordered).
        return $this->hasMany(PageRevision::class)->latest('created_at')->latest('id');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    /* ── Scopes ────────────────────────────────────────── */

    /**
     * Pages a visitor may see: published, the publish date has arrived, and the unpublish
     * date (if any) hasn't.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', PageStatus::Published)
            ->where(fn (Builder $query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('unpublish_at')->orWhere('unpublish_at', '>', now()));
    }

    /**
     * Live pages anyone may see: not behind a password or sign-in (the website’s APIs, search).
     */
    #[Scope]
    protected function public(Builder $query): void
    {
        $query->live()->whereNull('password')->where('members_only', false);
    }

    /**
     * Published pages whose unpublish date has passed.
     */
    #[Scope]
    protected function ended(Builder $query): void
    {
        $query->where('status', PageStatus::Published)->where('unpublish_at', '<=', now());
    }

    /**
     * Live pages with saved changes that aren't on the website yet (their working copy).
     */
    #[Scope]
    protected function withUnpublishedChanges(Builder $query): void
    {
        $query->whereNotNull('working_copy');
    }

    #[Scope]
    protected function homepage(Builder $query): void
    {
        $query->where('is_homepage', true);
    }

    /**
     * By address as it's shown (path()), in the content language: the homepage first, and
     * each page followed by the pages under it. Addresses are made from the pages above, so
     * they're worked out here, and the query takes their order by id.
     */
    #[Scope]
    protected function orderByPath(Builder $query, string $direction = 'asc'): void
    {
        $ids = static::idsInPathOrder();

        if ($ids === []) {
            return;
        }

        $id = $query->getGrammar()->wrap($this->qualifyColumn($this->getKeyName()));
        $cases = implode(' ', array_map(fn (int $position): string => "when ? then {$position}", array_keys($ids)));

        $query->orderByRaw("case {$id} {$cases} else ".count($ids).' end '.($direction === 'desc' ? 'desc' : 'asc'), $ids);
    }

    /**
     * Every page of the site, the bin's too, in the order of their addresses in a language.
     *
     * @return array<int, string>
     */
    public static function idsInPathOrder(?string $locale = null): array
    {
        $locale ??= ContentLocale::current();
        $pages = static::query()->withTrashed()->get(['id', 'site_id', 'parent_id', 'slug', 'locales', 'is_homepage'])->keyBy('id');

        // Not in this language: its address is in the one it's written in, which its translations tell.
        $elsewhere = $pages->reject(fn (self $page): bool => $page->isAvailableIn($locale))->keys();

        foreach ($elsewhere->isEmpty() ? [] : static::query()->withTrashed()->whereKey($elsewhere)->get(['id', 'block_translations']) as $full) {
            $pages[$full->getKey()]->setRawAttributes([...$pages[$full->getKey()]->getAttributes(), 'block_translations' => $full->getAttributes()['block_translations']], sync: true);
        }

        // The pages above each one, from those already read: path() asks no more of the database.
        foreach ($pages as $page) {
            $page->setRelation('parent', $pages[$page->parent_id] ?? null);
        }

        $segments = $pages->map(fn (self $page): array => explode('/', trim($page->path($locale), '/')));

        return $segments
            ->sort(function (array $a, array $b): int {
                foreach ($a as $index => $segment) {
                    if (! isset($b[$index])) {
                        return 1;
                    }

                    if (($compared = strnatcasecmp($segment, $b[$index])) !== 0) {
                        return $compared;
                    }
                }

                return count($a) <=> count($b);
            })
            ->keys()
            ->all();
    }

    /**
     * By title as it's shown: in the content language, else the default one; ignoring case.
     */
    #[Scope]
    protected function orderByTitle(Builder $query, string $direction = 'asc'): void
    {
        $grammar = $query->getGrammar();
        $title = fn (string $locale): string => $grammar->wrap("title->{$locale}");

        $query->orderByRaw('lower(coalesce('.$title(ContentLocale::current()).', '.$title(ContentLocale::default()).')) '.($direction === 'desc' ? 'desc' : 'asc'));
    }

    /* ── State ─────────────────────────────────────────── */

    public function isLive(): bool
    {
        return $this->status === PageStatus::Published
            && ($this->published_at === null || $this->published_at->isPast())
            && ! $this->hasEnded();
    }

    /**
     * Published, but its unpublish date has passed: it came down by itself.
     */
    public function hasEnded(): bool
    {
        return $this->status === PageStatus::Published && $this->unpublish_at !== null && ! $this->unpublish_at->isFuture();
    }

    public function isScheduled(): bool
    {
        return $this->status === PageStatus::Published && $this->published_at?->isFuture();
    }

    /**
     * Changes to the live page that aren't on the website yet (Aurora\Support\WorkingCopies).
     */
    public function hasWorkingCopy(): bool
    {
        return filled($this->working_copy);
    }

    /**
     * The page as its working copy has it, here only (not saved): what the form, the canvas,
     * previews and AI tools show and change.
     */
    public function applyWorkingCopy(): static
    {
        if (! $this->hasWorkingCopy()) {
            return $this;
        }

        foreach (Arr::only(static::attributesFromSnapshot([...$this->toSnapshot(), ...$this->working_copy]), WorkingCopies::KEYS) as $key => $value) {
            // Translated text holds every language at once.
            $this->isTranslatableAttribute($key) ? $this->setTranslations($key, (array) $value) : $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Put the changes on the website: the working copy becomes the page.
     */
    public function publishWorkingCopy(): void
    {
        WorkingCopies::bypass(function (): void {
            $this->refresh()->applyWorkingCopy()->forceFill(['working_copy' => null, 'working_copy_by_id' => null, 'working_copy_at' => null])->save();
        });

        PagePublished::dispatch($this);
    }

    /**
     * Let the changes go: the page stays as it is on the website.
     */
    public function discardWorkingCopy(): void
    {
        WorkingCopies::bypass(fn () => $this->forceFill(['working_copy' => null, 'working_copy_by_id' => null, 'working_copy_at' => null])->saveQuietly());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function workingCopyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'working_copy_by_id');
    }

    /**
     * Visitors type a password to see it (Aurora\Support\PagePassword).
     */
    public function isPasswordProtected(): bool
    {
        return filled($this->password);
    }

    /**
     * Not for everyone: behind a password, or only for people signed in. Such a page stays out
     * of search, the sitemap and the page cache, and search engines are asked to skip it.
     */
    public function isRestricted(): bool
    {
        return $this->isPasswordProtected() || $this->members_only;
    }

    /* ── Languages ─────────────────────────────────────── */

    /**
     * The languages the page is in, in the site's order (the default site language first).
     * Pages are usually in every language, but one can leave any out, the default too.
     *
     * @return array<int, string>
     */
    public function availableLocales(): array
    {
        $all = array_keys(ContentLocale::all()) ?: [ContentLocale::default()];
        $available = $this->locales === null ? $all : array_values(array_intersect($all, $this->locales));

        // Its languages all switched off: it falls back to the default site language.
        return $available ?: [ContentLocale::default()];
    }

    public function isAvailableIn(string $locale): bool
    {
        return in_array($locale, $this->availableLocales(), true);
    }

    /**
     * The language the page is written in: its blocks hold this language's text, and the
     * other languages are translations of it.
     *
     * It stays the language the blocks were written in when the website's default
     * language changes. Following the new default would read that translation as the
     * blocks themselves, and every language would show the original text.
     */
    public function primaryLocale(): string
    {
        return ContentLocale::authoredIn($this->availableLocales(), array_keys($this->block_translations ?? []));
    }

    /**
     * Whether the page is in more than one language: if not, it has no translation features.
     */
    public function isTranslatable(): bool
    {
        return count($this->availableLocales()) > 1;
    }

    /**
     * The page's address on the website in a language (the content language by default):
     * /about-us, or /nb/om-oss for another language.
     */
    public function path(?string $locale = null): string
    {
        $locale ??= ContentLocale::current();

        // Not in that language: links go to the page in its own language.
        if (! $this->isAvailableIn($locale)) {
            $locale = $this->primaryLocale();
        }
        $prefix = ContentLocale::prefix($locale);

        if ($this->is_homepage) {
            return $prefix === '' ? '/' : $prefix;
        }

        return $prefix.$this->parentPath($locale).'/'.$this->getTranslation('slug', $locale);
    }

    /**
     * On the page's own site: its domain, when it has one.
     */
    public function url(?string $locale = null): string
    {
        $path = $this->path($locale);

        return $this->site_id !== null && $this->site_id !== CurrentSite::id() && ($site = $this->site) !== null
            ? $site->url($path)
            : CurrentSite::url($path);
    }

    /**
     * Search (laravel/scout): the database engine searches search_text (on MySQL, by its
     * full-text index); a search service gets the page's text language by language.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return $this->searchableUsing() instanceof DatabaseEngine
            ? ['search_text' => $this->search_text]
            : PageSearch::document($this);
    }

    /**
     * With a FULLTEXT index (MySQL): see Aurora\Support\Search\DatabaseEngine.
     *
     * @return array<int, string>
     */
    public function fullTextColumns(): array
    {
        return ['search_text'];
    }

    /**
     * Index the page again: its search text worked out anew, and the search service told
     * (or the page taken out of it, when it isn't live). For pages saved before search, or
     * after the search driver changed.
     */
    public function reindex(): void
    {
        $this->forceFill(PageSearch::indexed($this))->saveQuietly();

        $this->shouldBeSearchable() ? $this->searchable() : $this->unsearchable();
    }

    public function searchableAs(): string
    {
        return 'pages';
    }

    /**
     * Only what visitors can read: live, and not hidden from search engines.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->isLive() && ! $this->noindex && ! $this->exclude_from_search && ! $this->isRestricted() && ! $this->trashed();
    }

    /**
     * Search the pages visitors may see on the current site (the one the request's address is
     * for), as a website's search does: Page::search('fjord')->paginate(10). Most relevant first,
     * in the current language with a search service. Chain ->query() to eager load: it adds
     * to these limits, never replaces them (ScopedBuilder).
     *
     * @param  string  $query
     * @param  (Closure(mixed, string, array<string, mixed>): mixed)|null  $callback  The search service's own options.
     * @return ScopedBuilder<static>
     */
    public static function search($query = '', $callback = null): ScopedBuilder
    {
        return static::searchSite((string) $query, callback: $callback);
    }

    /**
     * Search another site's pages than the request's, or every page of it, drafts too
     * (liveOnly: false, the admin's search tester; with the database engine only, as a search
     * service only holds live pages).
     *
     * @param  (Closure(mixed, string, array<string, mixed>): mixed)|null  $callback
     * @return ScopedBuilder<static>
     */
    public static function searchSite(string $terms, ?Site $site = null, bool $liveOnly = true, ?Closure $callback = null): ScopedBuilder
    {
        $siteId = $site?->getKey() ?? CurrentSite::id();

        $search = new ScopedBuilder(new static, $terms, $callback, static::usesSoftDelete() && config('scout.soft_delete', false));

        $search->query(fn (Builder $query) => $query
            ->when($site !== null, fn (Builder $query) => $query->withoutGlobalScope('site')->where($query->qualifyColumn('site_id'), $siteId))
            ->when($liveOnly, fn (Builder $query) => $query->live()->where('noindex', false)->where('exclude_from_search', false)->whereNull('password')->where('members_only', false)));

        // A search service holds every site's pages: ask it for this one's.
        return $search->model->searchableUsing() instanceof DatabaseEngine || $siteId === null
            ? $search
            : $search->where('site_id', $siteId);
    }

    /**
     * The blocks as they read in a language: the one structure, with that language's text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function localizedBlocks(?string $locale = null): array
    {
        $locale ??= ContentLocale::current();

        return BlockTranslations::apply($this->blocks ?? [], $locale === $this->primaryLocale() ? [] : ($this->block_translations[$locale] ?? []));
    }

    /**
     * Pages for a select: titles in the content language, alphabetical, the homepage first.
     *
     * @param  (Closure(Builder<Page>): mixed)|null  $query
     * @return array<string, string>
     */
    public static function options(?Closure $query = null): array
    {
        return static::query()
            ->when($query, $query)
            ->get(['id', 'title', 'is_homepage'])
            ->sortBy(fn (Page $page): string => ($page->is_homepage ? '0' : '1').mb_strtolower($page->title))
            ->mapWithKeys(fn (Page $page): array => [$page->id => $page->title])
            ->all();
    }

    /**
     * Whether an address is free in a language (another page may not have it).
     */
    public static function slugIsAvailable(string $slug, ?string $locale = null, ?string $except = null): bool
    {
        return ! static::query()->withTrashed()->whereSlug($slug, $locale)->when($except, fn (Builder $query) => $query->whereKeyNot($except))->exists();
    }

    /**
     * Find a page by its address in a language.
     */
    #[Scope]
    protected function whereSlug(Builder $query, string $slug, ?string $locale = null): void
    {
        $query->where('slug->'.($locale ?? ContentLocale::current()), $slug);
    }

    /**
     * A signed, expiring link that shows the page even while it is a draft.
     */
    /**
     * A signed link to the page as it will look: in another language, or drawn with another
     * layout (the layout picker's thumbnails), without changing it.
     */
    public function previewUrl(int $minutes = 60, bool $embed = false, ?string $locale = null, ?string $layout = null): string
    {
        return URL::temporarySignedRoute('pages.preview', now()->addMinutes($minutes), array_filter([
            'previewPage' => $this->getKey(),
            'embed' => $embed ? 1 : null,
            'locale' => $locale !== null && $locale !== $this->primaryLocale() ? $locale : null,
            'layout' => $layout,
        ]));
    }

    public function blockCount(): int
    {
        return count(BlockTree::hydrate($this->blocks ?? []));
    }

    public function seoTitle(): string
    {
        return filled($this->meta_title) ? $this->meta_title : $this->title;
    }

    /**
     * Everything a revision needs to bring the page back exactly as it was.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            ...$this->only(static::snapshotKeys()),
            // Every language's text, not just the one being read.
            ...collect($this->translatable)->mapWithKeys(fn (string $attribute): array => [$attribute => $this->getTranslations($attribute)])->all(),
            'noindex' => (bool) $this->noindex,
            'exclude_from_search' => (bool) $this->exclude_from_search,
        ];
    }

    /**
     * A revision's snapshot as attributes to put back on the page. Older snapshots can hold
     * null for columns that may not be null (noindex was null on pages created without it),
     * or a layout that has been deleted since.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function attributesFromSnapshot(array $snapshot): array
    {
        $attributes = array_intersect_key($snapshot, array_flip(static::snapshotKeys()));

        return [
            ...$attributes,
            // A layout deleted since: the default.
            'layout_id' => Layout::cached()->firstWhere('id', $attributes['layout_id'] ?? null)?->getKey(),
            'noindex' => (bool) ($attributes['noindex'] ?? false),
            'exclude_from_search' => (bool) ($attributes['exclude_from_search'] ?? false),
        ];
    }

    /* ── Menu builder ──────────────────────────────────── */

    public function getMenuPanelTitle(): string
    {
        return $this->title;
    }

    public function getMenuPanelUrl(): string
    {
        return $this->path();
    }

    public function getMenuPanelName(): string
    {
        return 'Pages';
    }
}
