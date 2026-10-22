<?php

namespace Aurora\Mcp\Tools;

use Aurora\Enums\AiChannel;
use Aurora\Enums\PageStatus;
use Aurora\Mcp\BlockInput;
use Aurora\Mcp\PagePresenter;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\RevisionContext;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_page')]
#[Description('Create a new page, optionally with its full content as a nested block tree. New pages are drafts unless you ask for "review" (or "published", if your role may publish). Returns the page with preview and editor links to share with the user.')]
class CreatePage extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Page title, shown in the admin and the browser tab.')->required(),
            'slug' => $schema->string()->description('Address, e.g. "about-us". Generated from the title when omitted.'),
            'parent' => $schema->string()->description('Id or slug of the page this one sits under; its address then starts with the parent\'s (/docs/getting-started). Omit for a top-level page.'),
            'excerpt' => $schema->string()->description('One or two sentences summarising the page. Used in page lists and as SEO fallback.'),
            'layout' => $schema->string()->enum(Layout::cached()->pluck('handle')->all())->description('The layout\'s handle, see list_layouts. Defaults to the default layout.'),
            'layout_settings' => $schema->object()->description('Values for the fields the layout has besides the blocks (its "settings" in list_layouts), e.g. {"background": "<image asset id>"}.'),
            'status' => $schema->string()->enum(['draft', 'review', 'published'])->description('Defaults to draft.'),
            'meta_title' => $schema->string()->description('SEO title (max ~60 characters).'),
            'meta_description' => $schema->string()->description('SEO description (max ~155 characters).'),
            'blocks' => $schema->array()->items($schema->object())->description('The page content as a nested block tree. See list_block_types.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        Gate::authorize('create', Page::class);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', fn (string $attribute, mixed $value, Closure $fail) => Page::slugIsAvailable((string) $value) ? null : $fail('Another page already has this address.')],
            'status' => ['nullable', 'in:draft,review,published'],
        ]);

        $blocks = BlockInput::decode($request->get('blocks', []));
        $flat = BlockTreeBuilder::flatten(BlockInput::tree(is_array($blocks) ? $blocks : []));

        $layout = Layout::cached()->firstWhere('handle', $request->get('layout')) ?? Layout::default();

        $page = new Page([
            'parent_id' => filled($request->get('parent')) ? $this->findPage($request, 'parent')->getKey() : null,
            // The site's first page is its homepage.
            'is_homepage' => ! Page::othersExist(),
            'title' => $request->get('title'),
            'slug' => $request->get('slug'),
            'excerpt' => $request->get('excerpt'),
            'layout_id' => $layout?->getKey(),
            'status' => PageStatus::tryFrom((string) $request->get('status')) ?? PageStatus::Draft,
            'meta_title' => $request->get('meta_title'),
            'meta_description' => $request->get('meta_description'),
            'blocks' => $flat,
            'author_id' => $user->id,
        ]);

        if ($request->has('layout_settings')) {
            $settings = $this->layoutSettings($request, $layout, $page);

            if ($settings instanceof Response) {
                return $settings;
            }

            $page->settings = $settings;
        }

        $summary = 'Created the page with '.count($flat).' blocks';
        RevisionContext::as(AiChannel::current()->revisionSource(), $summary, fn () => $page->save());
        $this->did($summary.': '.$page->title, $page);

        return Response::json([
            'message' => 'Page created. Share the preview link with the user so they can see it.',
            'page' => PagePresenter::summary($page->refresh(), $user) + ['links' => PagePresenter::links($page)],
        ]);
    }
}
