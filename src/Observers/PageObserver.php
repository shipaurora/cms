<?php

namespace Aurora\Observers;

use Aurora\Enums\PageStatus;
use Aurora\Models\AssetUsage;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\CmsNotifications;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\PageSearch;
use Aurora\Support\RevisionContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;

class PageObserver
{
    /**
     * Attributes whose change is worth a revision. Status changes are not content.
     */
    protected const TRACKED = ['parent_id', 'title', 'slug', 'excerpt', 'blocks', 'block_translations', 'locales', 'layout_id', 'meta_title', 'meta_description', 'og_image', 'noindex', 'exclude_from_search'];

    public function saving(Page $page): void
    {
        // What it says, for search: kept with the page, so the database can search it.
        $indexed = PageSearch::indexed($page);
        $page->search_text = $indexed['search_text'];

        // A save that writes the page indexes it again (Scout, once saved); left out, it isn't.
        if ($page->isDirty()) {
            $page->indexed_at = $indexed['indexed_at'];
        }

        // Publishing is its own permission, checked here so no surface can skip it:
        // not the form, not a bulk action, not an AI assistant over MCP.
        // Taking a page live counts however it happens: its status, or the date a published
        // page goes live (a scheduled page moved to now), or making it the homepage.
        $goesLive = $page->status === PageStatus::Published && $page->isDirty('status', 'published_at');

        // The site's only page may be its homepage for anyone who may make pages: there is no
        // live homepage it could replace.
        $replacesHomepage = $page->isDirty('is_homepage') && Page::othersExist($page->getKey());

        if (($goesLive || $replacesHomepage) && auth()->check()) {
            Gate::authorize('publish', $page);
        }

        // Each language's own address, tidied (never one language's read through the fallback
        // into another); new ones are made from the title by Page::addSlug().
        // Tidying can turn a free address into a taken one (about_us into about-us): then it
        // gets the next free number, as a new address would.
        foreach ($page->getTranslations('slug') as $locale => $slug) {
            $tidy = Str::slug((string) $slug);
            $tidy = Page::isReservedSlug($tidy) ? $tidy.'-page' : $tidy;

            if ($tidy !== $slug && ! Page::slugIsAvailable($tidy, $locale, $page->getKey())) {
                $tidy = Page::uniqueSlug($tidy, $locale, $page->getKey());
            }

            $page->setTranslation('slug', $locale, $tidy);
        }

        if ($page->status === PageStatus::Published && $page->published_at === null) {
            $page->published_at = now();
        }

        $this->guardHomepage($page);
        $this->guardParent($page);

        if (auth()->check()) {
            $page->last_edited_by_id = auth()->id();
            $page->author_id ??= auth()->id();
        }
    }

    /**
     * A page sits under another page of its own site, never under itself or a page below it.
     */
    protected function guardParent(Page $page): void
    {
        if ($page->parent_id === null || ! $page->isDirty('parent_id')) {
            return;
        }

        $parent = Page::query()->withTrashed()->find($page->parent_id);

        if ($parent === null || $parent->site_id !== ($page->site_id ?? CurrentSite::id()) || $page->parent_id === $page->getKey() || ($page->exists && in_array($page->parent_id, $page->descendantIds(), true))) {
            throw ValidationException::withMessages(['parent_id' => __('A page can’t sit under itself or one of its own subpages.')]);
        }
    }

    /**
     * Which page is the homepage changes by choosing another one, and a page becomes it only
     * when it is live: turning the flag off, or pointing it at a draft, would leave `/` to a
     * fallback and look like the switch did nothing. The homepage itself may go back to
     * review or draft, or to the trash (the admin warns what visitors then see). Checked here
     * so the page form and MCP follow the same rule.
     */
    protected function guardHomepage(Page $page): void
    {
        if (! $page->isDirty('is_homepage')) {
            return;
        }

        if (! $page->is_homepage && $page->getOriginal('is_homepage')) {
            throw ValidationException::withMessages([
                'is_homepage' => 'The site always needs a homepage. To change it, make another page the homepage instead.',
            ]);
        }

        // Live, unless it is the site's only page (until it is, the site has nothing to show).
        if ($page->is_homepage && ! $page->isLive() && Page::othersExist($page->getKey())) {
            throw ValidationException::withMessages([
                'is_homepage' => 'The homepage must be published and live. Publish this page first, or in the same save.',
            ]);
        }
    }

    /**
     * Back from the trash after another page became the homepage: an ordinary page again.
     */
    public function restored(Page $page): void
    {
        if ($page->is_homepage && Page::query()->homepage()->whereKeyNot($page->getKey())->exists()) {
            $page->forceFill(['is_homepage' => false])->saveQuietly();
        }
    }

    public function saved(Page $page): void
    {
        if ($page->is_homepage && $page->wasChanged('is_homepage') || ($page->wasRecentlyCreated && $page->is_homepage)) {
            // Also one in the trash, so restoring it doesn't bring back a second homepage.
            Page::withTrashed()->whereKeyNot($page->getKey())->where('is_homepage', true)->update(['is_homepage' => false]);
        }

        // A redirect from one of the page's own addresses would loop.
        Redirect::query()->whereIn('from_path', collect(ContentLocale::all())->keys()->map(fn (string $locale): string => $page->path($locale)))->delete();

        if ($page->wasRecentlyCreated || $page->wasChanged(['blocks', 'og_image'])) {
            AssetLibrary::syncUsages($page, AssetLibrary::pageValues($page));
        }

        if ($page->wasRecentlyCreated || $page->wasChanged(self::TRACKED)) {
            $page->revisions()->create([
                'user_id' => auth()->id(),
                'source' => RevisionContext::source(),
                'summary' => RevisionContext::summary() ?? ($page->wasRecentlyCreated ? 'Created the page' : $this->describe($page)),
                'snapshot' => $page->toSnapshot(),
            ]);
        }
    }

    /**
     * A new page straight into review: the publishers hear about it. Only for pages someone
     * made (not seeders or the scheduler).
     */
    public function created(Page $page): void
    {
        if (($by = auth()->user()) instanceof User && $page->status === PageStatus::Review) {
            CmsNotifications::pageSentForReview($page, $by);
            $this->refreshSidebar();
        }
    }

    /**
     * Sent for review, or published by someone other than its author: tell the people concerned.
     */
    public function updated(Page $page): void
    {
        if ($page->wasChanged('status')) {
            $this->refreshSidebar();

            if ($page->status !== PageStatus::Review) {
                CmsNotifications::reviewDone($page);
            }
        }

        if (! ($by = auth()->user()) instanceof User || ! $page->wasChanged('status')) {
            return;
        }

        match ($page->status) {
            PageStatus::Review => CmsNotifications::pageSentForReview($page, $by),
            PageStatus::Published => CmsNotifications::pagePublished($page, $by),
            default => null,
        };
    }

    /**
     * The sidebar counts pages in review: redraw it, from whichever admin screen made the
     * change (the editor, a list's row or bulk action, the canvas).
     */
    protected function refreshSidebar(): void
    {
        if (($component = Livewire::current()) instanceof Component) {
            $component->dispatch('refresh-sidebar');
        }
    }

    protected function describe(Page $page): string
    {
        $changed = array_intersect(self::TRACKED, array_keys($page->getChanges()));

        // In English, translated when shown (PageRevision::summaryText()).
        $labels = array_map(fn (string $attribute): string => match ($attribute) {
            'blocks' => 'content',
            'block_translations' => 'translations',
            'locales' => 'languages',
            'slug' => 'address',
            'layout_id' => 'layout',
            'excerpt' => 'summary',
            'meta_title', 'meta_description', 'og_image', 'noindex' => 'SEO',
            'exclude_from_search' => 'search',
            default => str_replace('_', ' ', $attribute),
        }, $changed);

        return 'Changed '.implode(', ', array_unique($labels));
    }

    public function forceDeleted(Page $page): void
    {
        AssetUsage::query()->whereMorphedTo('usable', $page)->delete();
    }
}
