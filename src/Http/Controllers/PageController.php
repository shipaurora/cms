<?php

namespace Aurora\Http\Controllers;

use Aurora\Http\Middleware\UseSiteLocale;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\PageRevision;
use Aurora\Models\Redirect;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\Layouts;
use Aurora\Support\PagePassword;
use Aurora\Support\Sitemap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The website's pages: the homepage, a page by its address, signed previews, the sitemap and
 * robots.txt. Each page is drawn with its layout's view (Aurora\Support\Layouts).
 */
class PageController
{
    public function home(): Response
    {
        // Only the homepage: while it isn't live, the address is "Page not found".
        return $this->render(Page::query()->live()->homepage()->firstOrFail());
    }

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        // /nb or /nb/om-oss: the language's prefix comes off the front (UseSiteLocale picked it).
        if (UseSiteLocale::codeIn($slug) !== null) {
            $slug = str_contains($slug, '/') ? Str::after($slug, '/') : '';

            if ($slug === '') {
                return $this->home();
            }
        }

        // A subpage is found by its whole address (/docs/getting-started), part by part, so pages
        // in different places may share their own part. An old address (a parent renamed, the
        // page moved) finds the one page whose own part it ends with, and the visitor is sent to
        // where it is now; so does its address in another language, or not translated yet.
        $page = Page::findByPath($slug) ?? Page::findByOldPath($slug);

        if ($page?->is_homepage) {
            return redirect($page->path(), 301);
        }

        if ($page !== null && ($page->isLive() || Gate::allows('update', $page))) {
            // Not in this language at all: "Page not found" (render() says so), never a redirect.
            if ($page->isAvailableIn(ContentLocale::current()) && '/'.trim(rawurldecode($request->path()), '/') !== $page->path()) {
                // A redirect someone set up for exactly this address still wins.
                return $this->storedRedirect($request) ?? redirect($page->path(), 301);
            }

            return $this->render($page, preview: ! $page->isLive());
        }

        return $this->redirectOrFail($request);
    }

    /**
     * A signed link to a page (or one of its revisions) as it will look, whatever its status.
     */
    public function preview(Request $request, Page $previewPage): Response
    {
        $page = $previewPage;

        // The page as its own site shows it: its settings, menus and languages (in its default
        // language, unless another is asked for below).
        CurrentSite::set($page->site);
        app()->setLocale(ContentLocale::current());

        // A language other than the page's own, as chosen in the admin.
        if ($page->isAvailableIn($locale = (string) $request->query('locale'))) {
            ContentLocale::set($locale);
            app()->setLocale($locale);
        }

        // A revision, as it was; else the page as it will look, with its unpublished changes.
        if ($request->filled('revision')) {
            $revision = PageRevision::query()->whereBelongsTo($page)->findOrFail($request->string('revision'));
            $page->forceFill(Page::attributesFromSnapshot($revision->snapshot));
        } else {
            $page->applyWorkingCopy();
        }

        // Drawn with another layout, as the layout picker shows it.
        if (($layout = Layout::cached()->firstWhere('id', $request->query('layout'))) !== null) {
            $page->layout_id = $layout->getKey();
        }

        return $this->render($page, preview: ! $request->boolean('embed'), embedded: $request->boolean('embed'), signed: true);
    }

    public function sitemap(): Response
    {
        $pages = Page::query()->live()->where('noindex', false)->whereNull('password')->where('members_only', false)->latest('updated_at')->get();

        return response()
            ->view('aurora::sitemap', ['pages' => $pages, 'entries' => Sitemap::entries()])
            ->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /admin\nDisallow: /preview\n\nSitemap: ".url('sitemap.xml')."\n")
            ->header('Content-Type', 'text/plain');
    }

    protected function render(Page $page, bool $preview = false, bool $embedded = false, bool $signed = false): Response
    {

        // Blocks that need to know where they are (a form records the page it was sent from).
        ViewFacade::share('currentPage', $page);
        // A preview links between languages within the preview: the page may be on a site
        // without a domain, or not live yet.
        ViewFacade::share('previewing', $preview || $embedded);

        // A page only exists in the languages it was given.
        abort_unless($page->isAvailableIn(ContentLocale::current()), 404);

        // Behind a password, and not unlocked yet: its password page, never the page itself,
        // whoever is signed in. Only a signed preview link shows it: it's given out on purpose.
        if (! $signed && PagePassword::isLocked($page)) {
            return response()->view('aurora::page-password', ['page' => $page])->header('Cache-Control', 'no-store, private');
        }

        // Only for people signed in to the website: the others are asked to sign in.
        if (! $signed && $page->members_only && auth()->guest()) {
            return response()->view('aurora::page-sign-in', ['page' => $page, 'signInUrl' => Route::has('login') ? route('login') : null], 403)->header('Cache-Control', 'no-store, private');
        }

        $response = response()->view(Layouts::viewFor($page), [
            'page' => $page,
            'layout' => $page->layoutOrDefault(),
            'settings' => $page->layoutSettings(),
            'preview' => $preview,
            'embedded' => $embedded,
        ]);

        // For this visitor only: no shared cache may keep it.
        return $page->isRestricted() ? $response->header('Cache-Control', 'no-store, private') : $response;
    }

    protected function redirectOrFail(Request $request): RedirectResponse
    {
        return $this->storedRedirect($request) ?? abort(404);
    }

    /**
     * The redirect set up for the address asked for, if there is one (and it still leads somewhere).
     */
    protected function storedRedirect(Request $request): ?RedirectResponse
    {
        $redirect = Redirect::query()->where('from_path', Redirect::normalisePath($request->path()))->first();

        // Not one that has come to go round in a circle since it was saved (a page it leads to
        // was unpublished, say): "Page not found" rather than visitors sent round and round.
        if ($redirect === null || blank($destination = $redirect->destination()) || $redirect->loop() !== null) {
            return null;
        }

        $redirect->increment('hits', 1, ['last_hit_at' => now()]);

        return redirect($destination, $redirect->status_code);
    }
}
