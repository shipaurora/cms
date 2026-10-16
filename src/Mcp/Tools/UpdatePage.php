<?php

namespace Aurora\Mcp\Tools;

use Aurora\Enums\PageLayout;
use Aurora\Mcp\PagePresenter;
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
            'layout' => $schema->string()->enum(array_column(PageLayout::cases(), 'value')),
            'status' => $schema->string()->enum(['draft', 'review', 'published']),
            'published_at' => $schema->string()->description('ISO 8601 date-time. A future date with status "published" schedules the page.'),
            'is_homepage' => $schema->boolean(),
            'meta_title' => $schema->string(),
            'meta_description' => $schema->string(),
            'og_image' => $schema->string()->description('Share image path, see list_images.'),
            'noindex' => $schema->boolean(),
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
        ]);

        $attributes = Arr::only($request->all(), ['title', 'slug', 'excerpt', 'layout', 'status', 'published_at', 'is_homepage', 'meta_title', 'meta_description', 'og_image', 'noindex']);

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
