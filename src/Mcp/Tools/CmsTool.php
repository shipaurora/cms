<?php

namespace Aurora\Mcp\Tools;

use Aurora\Enums\AiChannel;
use Aurora\Mcp\BlockInput;
use Aurora\Models\Layout;
use Aurora\Models\McpActivity;
use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\Layouts;
use Aurora\Support\Permissions;
use Aurora\Support\RevisionContext;
use Aurora\Support\WorkingCopies;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The shared behaviour of every CMS tool.
 *
 * The assistant acts as the person who connected it: the request's user becomes the
 * authenticated user for the duration of the call, so policies, the publishing guard
 * and revision authorship all apply exactly as they do in the admin panel. Each call
 * is written to the AI activity log, and errors come back as messages the assistant
 * can act on rather than stack traces.
 */
abstract class CmsTool extends Tool
{
    protected ?Model $subject = null;

    protected ?string $summary = null;

    /**
     * Whether the tool works on one site's content (pages, menus, forms). Tools for what all
     * sites share (media, block types, who you are) don't need one.
     */
    protected bool $needsSite = true;

    abstract protected function run(Request $request, User $user): Response;

    public function handle(Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user() ?? $this->localUser();

        if ($user === null) {
            return Response::error('Not authenticated. Connect with a personal access token from your profile in the admin panel.');
        }

        $channel = AiChannel::current();

        if ($user->is_active === false || ! $user->can($channel->permission())) {
            return Response::error('Your account is not allowed to use the '.lcfirst($channel->label()).'. Ask an administrator for the "'.Permissions::label($channel->permission()).'" permission.');
        }

        // Hidden tools are hidden, not merely unlisted: a direct call is refused too.
        if (method_exists($this, 'shouldRegister') && ! $this->shouldRegister($request)) {
            return Response::error('Permission denied: your role does not allow this tool. '.$this->roleHint($user));
        }

        Auth::setUser($user);

        $site = $this->resolveSite($request, $user, $channel);

        if (is_string($site)) {
            return Response::error($site);
        }

        $succeeded = false;

        try {
            // Everything the tool reads and writes is on that site (CurrentSite).
            // A live page's new content goes into its working copy, for someone to publish.
            $response = CurrentSite::using($site, fn (): Response => RevisionContext::as($channel->revisionSource(), null, fn (): Response => WorkingCopies::capture(fn (): Response => $this->run($request, $user))));
            $succeeded = ! $response->isError();

            return $response;
        } catch (AuthorizationException) {
            return Response::error('Permission denied: your role does not allow this. '.$this->roleHint($user));
        } catch (ValidationException $exception) {
            return Response::error('Invalid input: '.collect($exception->errors())->flatten()->implode(' '));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        } finally {
            McpActivity::query()->create([
                // The site the call worked on, so it shows in that site's activity log only.
                'site_id' => $site?->getKey() ?? CurrentSite::id(),
                'user_id' => $user->id,
                'channel' => $channel,
                'tool' => $this->name(),
                'summary' => $this->summary,
                'succeeded' => $succeeded,
                'subject_type' => $this->subject?->getMorphClass(),
                'subject_id' => $this->subject?->getKey(),
                'arguments' => $this->loggableArguments($request->all()),
            ]);
        }
    }

    /**
     * The site the call works on, or why there is none:
     * - the "site" argument (its id, handle or domain), when given;
     * - in the admin's assistant, the site being worked on there;
     * - else the person's one site; with several, they must say which.
     * Only sites the person may work on (User::accessibleSites()).
     */
    protected function resolveSite(Request $request, User $user, AiChannel $channel): Site|string|null
    {
        $sites = $user->accessibleSites();
        $given = trim((string) $request->get('site', ''));
        $list = fn (): string => $sites->map(fn (Site $site): string => $site->handle.' ('.$site->name.')')->implode(', ');

        if ($given !== '') {
            $site = $sites->first(fn (Site $site): bool => in_array(mb_strtolower($given), array_filter([(string) $site->getKey(), $site->handle, $site->domain]), true));

            return $site ?? "No site \"{$given}\" that you can work on. Your sites: {$list()}.";
        }

        if ($channel === AiChannel::Assistant && ($current = CurrentSite::get()) !== null && $sites->contains($current)) {
            return $current;
        }

        if ($sites->count() === 1 || ! $this->needsSite) {
            return $sites->contains(CurrentSite::default()) ? CurrentSite::default() : $sites->first();
        }

        return "This CMS runs several sites: say which one with the \"site\" argument (its handle). Your sites: {$list()}. Ask the user if it isn't clear which site they mean.";
    }

    /**
     * The tool as AI apps see it: with a "site" argument when the person works on more than
     * one site (the admin's assistant always works on the site being worked on).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $user = auth()->user() ?? $this->localUser();

        if ($this->needsSite && (! $user instanceof User || $user->accessibleSites()->count() > 1)) {
            $properties = (array) ($tool['inputSchema']['properties'] ?? []);
            $properties['site'] = ['type' => 'string', 'description' => 'Which site to work on: its handle (see list_sites). Leave out when there is only one site.'];
            $tool['inputSchema']['properties'] = $properties;
        }

        return $tool;
    }

    /**
     * Record what the call did, for the activity log and the page's revision history.
     */
    protected function did(string $summary, ?Model $subject = null): void
    {
        $this->summary = $summary;
        $this->subject = $subject ?? $this->subject;
    }

    /**
     * Save a page as an AI revision with a human summary.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function savePage(Page $page, array $attributes, string $summary): Page
    {
        Gate::authorize('update', $page);

        RevisionContext::as(AiChannel::current()->revisionSource(), $summary, fn () => $page->fill($attributes)->save());
        $this->did($summary, $page);

        return $page->refresh()->applyWorkingCopy();
    }

    /**
     * Custom HTML only with the user's say-so: refuse blocks that add "code" blocks (more than
     * the page had) unless the call says the user agreed. Before the blocks are checked, so
     * nothing is saved or uploaded for a refused call.
     *
     * @param  array<int, mixed>  $blocks
     */
    protected function allowCustomHtml(Request $request, array $blocks, ?Page $page = null): void
    {
        // Without the permission, the blocks' own check says it isn't allowed.
        if ($request->boolean('custom_html') || ! app(BlockRegistries::class)->for('admin')->isVisible('code') || BlockInput::count($blocks, 'code') <= BlockInput::count($page->blocks ?? [], 'code')) {
            return;
        }

        throw new InvalidArgumentException('Not saved: this adds a custom HTML ("code") block. Build it from the site\'s blocks instead (see list_block_types: section, grid, embed and video cover most layouts). Use custom HTML only when the user asked for it, or for something no other block can do; then ask the user first, saying what it is for, and call again with "custom_html": true once they agree.');
    }

    /**
     * The page's settings with the "layout_settings" passed in this layout's fields, checked
     * against them; else an error the assistant can act on.
     *
     * @return array<string, mixed>|Response
     */
    protected function layoutSettings(Request $request, ?Layout $layout, Page $page): array|Response
    {
        if (! Page::storesSettings()) {
            return Response::error('Layout settings can’t be saved until the site’s database is migrated (php artisan migrate).');
        }

        $values = BlockInput::decode($request->get('layout_settings'));

        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            return Response::error('"layout_settings" takes an object of field names and values, e.g. {"background": "<image asset id>"}.');
        }

        if ($layout === null) {
            return Response::error('The page has no layout to set fields for. See list_layouts.');
        }

        if (($errors = Layouts::validateSettings($layout, $values)) !== []) {
            return Response::error(implode(' ', $errors).' See list_layouts.');
        }

        return $page->settingsWithLayoutValues($values, $layout);
    }

    /**
     * The page at a whole address in any of the site's languages, with or without the language's
     * prefix ("/en/about", "about").
     */
    protected function pageAtAddress(string $address): ?Page
    {
        $path = trim($address, '/');

        if ($path === '') {
            return null;
        }

        foreach (array_keys(ContentLocale::all()) ?: [ContentLocale::default()] as $locale) {
            $prefix = trim(ContentLocale::prefix($locale), '/');
            $own = $prefix !== '' && str_starts_with($path.'/', $prefix.'/') ? ltrim(substr($path, strlen($prefix)), '/') : $path;

            if ($own !== '' && ($page = Page::findByPath($own, $locale)) !== null) {
                return $page;
            }
        }

        return null;
    }

    protected function findPage(Request $request, string $key = 'page'): Page
    {
        $identifier = trim((string) $request->get($key, ''));

        // By id, else by its whole address in any language ("/docs/getting-started", with or
        // without the language's prefix), else by its own part of it when only one page has
        // that: pages in different places may share it (/services/design, /work/design).
        $page = Str::isUuid($identifier) ? Page::query()->whereKey($identifier)->first() : null;
        $page ??= $this->pageAtAddress($identifier);

        if ($page === null && ($slug = Str::afterLast(trim($identifier, '/'), '/')) !== '') {
            $named = Page::query()
                ->where(function ($query) use ($slug): void {
                    foreach (array_keys(ContentLocale::all()) ?: [ContentLocale::default()] as $locale) {
                        $query->orWhere('slug->'.$locale, $slug);
                    }
                })
                ->get();

            if ($named->count() > 1) {
                throw new InvalidArgumentException("Several pages have \"{$slug}\" in their address: ".$named->map(fn (Page $page): string => $page->path())->implode(', ').'. Give the page id or its whole address.');
            }

            $page = $named->first();
        }

        if ($page === null && in_array($identifier, ['/', 'home', ''], true)) {
            $page = Page::query()->homepage()->first();
        }

        if ($page === null) {
            throw new InvalidArgumentException("No page found for \"{$identifier}\". Use list_pages to find the page id or its address.");
        }

        Gate::authorize('view', $page);
        $this->subject = $page;

        // With its unpublished changes: what the AI reads and changes is the working copy.
        $page->applyWorkingCopy();

        return $page;
    }

    /**
     * Who uses the tool: the signed-in user, or (a local stdio connection) MCP_USER. For
     * shouldRegister(), which runs before handle() has resolved the user.
     */
    protected function actingUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : $this->localUser();
    }

    /**
     * The stdio server (`php artisan mcp:start cms`) has no HTTP request to authenticate,
     * so it acts as the account named in MCP_USER. Never set for web traffic.
     */
    protected function localUser(): ?User
    {
        $email = config('services.mcp.local_user') ?? config('aurora.mcp.local_user');

        return app()->runningInConsole() && filled($email)
            ? User::query()->where('email', $email)->first()
            : null;
    }

    protected function roleHint(User $user): string
    {
        return 'You are signed in as '.$user->name.' ('.$user->getRoleNames()->implode(', ').'). Use whoami to see what you may do.';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function loggableArguments(array $arguments): array
    {
        $json = json_encode($arguments);

        return strlen((string) $json) > 4000 ? ['truncated' => true, 'keys' => array_keys($arguments)] : $arguments;
    }
}
