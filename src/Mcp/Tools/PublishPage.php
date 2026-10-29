<?php

namespace Aurora\Mcp\Tools;

use Aurora\Enums\PageStatus;
use Aurora\Mcp\PagePresenter;
use Aurora\Models\Page;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('publish_page')]
#[Description('Publish a page now, schedule it for a future date, or take it offline (unpublish). On a live page with unpublished changes, "publish" puts those changes on the website. Requires the publish permission; without it, set status "review" with update_page so an editor can publish.')]
class PublishPage extends CmsTool
{
    public function shouldRegister(Request $request): bool
    {
        return (bool) $this->actingUser($request)?->can('publish', Page::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id, or its address ("/docs/getting-started").')->required(),
            'action' => $schema->string()->enum(['publish', 'schedule', 'unpublish'])->required(),
            'at' => $schema->string()->description('For "schedule": ISO 8601 date-time in the future.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        Gate::authorize('publish', $page);

        $attributes = match ($request->get('action')) {
            'schedule' => ['status' => PageStatus::Published, 'published_at' => Carbon::parse((string) $request->get('at'))],
            'unpublish' => ['status' => PageStatus::Draft],
            default => ['status' => PageStatus::Published, 'published_at' => now()],
        };

        if ($request->get('action') === 'schedule' && $attributes['published_at']->isPast()) {
            return Response::error('"at" must be in the future to schedule. Use action "publish" to publish now.');
        }

        // Live already, with unpublished changes: publishing puts those on the website.
        if ($request->get('action') === 'publish' && $page->isLive() && $page->hasWorkingCopy()) {
            $page->publishWorkingCopy();
            $this->did('Published the changes: '.$page->title, $page);

            return Response::json(['message' => $this->summary, 'page' => PagePresenter::summary($page->refresh(), $user) + ['links' => PagePresenter::links($page)]]);
        }

        $page->fill($attributes)->save();
        $this->did(match ($request->get('action')) {
            'schedule' => 'Scheduled for '.$page->published_at->toDayDateTimeString(),
            'unpublish' => 'Unpublished',
            default => 'Published',
        }.': '.$page->title, $page);

        return Response::json(['message' => $this->summary, 'page' => PagePresenter::summary($page->refresh(), $user) + ['links' => PagePresenter::links($page)]]);
    }
}
