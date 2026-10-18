<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\PagePresenter;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Aurora\Support\PageRedirects;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_page')]
#[Description('Change page settings: title, address (slug), summary, layout, SEO, status, publish date or homepage. Only the fields you pass change. Changing the slug of a live page redirects the old address to the new one unless redirect_old_address is false. Publishing requires the publish permission.')]
class UpdatePage extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug.')->required(),
            'title' => $schema->string(),
            'slug' => $schema->string(),
            'parent' => $schema->string()->description('Id or slug of the page to move this one under ("" for the top level). Old addresses keep working.'),
            'redirect_old_address' => $schema->boolean()->description('When the slug of a live page changes, keep the old address working with a redirect. Defaults to true.'),
            'excerpt' => $schema->string(),
            'layout' => $schema->string()->enum(Layout::cached()->pluck('handle')->all())->description('The layout\'s handle, see list_layouts.'),
            'status' => $schema->string()->enum(['draft', 'review', 'published']),
            'published_at' => $schema->string()->description('ISO 8601 date-time. A future date with status "published" schedules the page.'),
            'unpublish_at' => $schema->string()->description('ISO 8601 date-time when the live page comes down by itself (a campaign, a job ad). Empty string to keep it live. Not for the homepage.'),
            'is_homepage' => $schema->boolean(),
            'meta_title' => $schema->string(),
            'meta_description' => $schema->string(),
            'og_image' => $schema->string()->description('Share image path, see list_images.'),
            'noindex' => $schema->boolean(),
            'members_only' => $schema->boolean()->description('Only people signed in to the website can see the page.'),
            'exclude_from_search' => $schema->boolean()->description('Leave the page out of the site’s own search index. Needs the "Manage search" permission.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        Gate::authorize('update', $page);

        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:120', 'alpha_dash', fn (string $attribute, mixed $value, Closure $fail) => Page::slugIsAvailable((string) $value, except: $page->id) ? null : $fail('Another page already has this address.')],
            'status' => ['sometimes', 'in:draft,review,published'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'unpublish_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $attributes = Arr::only($request->all(), ['title', 'slug', 'excerpt', 'status', 'published_at', 'unpublish_at', 'is_homepage', 'meta_title', 'meta_description', 'og_image', 'noindex', 'members_only', 'exclude_from_search']);

        if (array_key_exists('exclude_from_search', $attributes) && ! $user->can('manage-search Page')) {
            return Response::error('Leaving a page out of the search index needs the "Manage search" permission.');
        }

        if (($attributes['unpublish_at'] ?? null) === '') {
            $attributes['unpublish_at'] = null;
        }

        if ($request->has('layout')) {
            $layout = Layout::cached()->firstWhere('handle', $request->get('layout'));

            if ($layout === null) {
                return Response::error('No layout has the handle "'.$request->get('layout').'". See list_layouts.');
            }

            $attributes['layout_id'] = $layout->getKey();
        }

        if ($request->has('parent')) {
            $attributes['parent_id'] = filled($request->get('parent')) ? $this->findPage($request, 'parent')->getKey() : null;
        }

        if ($attributes === []) {
            return Response::error('Nothing to change. Pass at least one field besides "page".');
        }

        $redirects = $request->boolean('redirect_old_address', true) && isset($attributes['slug'])
            ? PageRedirects::changes($page, [ContentLocale::current() => Str::slug((string) $attributes['slug'])])
            : [];

        $page = $this->savePage($page, $attributes, 'Updated '.implode(', ', array_map(fn ($key) => str_replace('_', ' ', $key), array_keys($attributes))));

        PageRedirects::keep($page, array_column($redirects, 'from'));

        return Response::json(['message' => 'Page updated.', 'page' => PagePresenter::summary($page, $user) + ['links' => PagePresenter::links($page)]]);
    }
}
