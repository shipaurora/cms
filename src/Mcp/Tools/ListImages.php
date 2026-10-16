<?php

namespace Aurora\Mcp\Tools;

use Aurora\Enums\AssetType;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\User;
use Aurora\Support\AssetAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_images')]
#[Description('Images in the media library, with the id to put in image fields, alt text, folder path and how often each is used. Filter by words or by folder path, e.g. "Photos/Norway" (includes its subfolders).')]
#[IsReadOnly]
class ListImages extends CmsTool
{
    protected bool $needsSite = false;

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional: words in the title, alt text, caption, folder name or tag.'),
            'folder' => $schema->string()->description('Optional: a folder path such as "Photos/Norway".'),
        ];
    }

    protected static function folderAt(string $path): ?AssetFolder
    {
        $folder = null;

        foreach (array_filter(array_map('trim', explode('/', $path))) as $name) {
            $folder = AssetFolder::query()->where('parent_id', $folder?->id)->where('name', $name)->first();

            if ($folder === null) {
                return null;
            }
        }

        return $folder;
    }

    protected function run(Request $request, User $user): Response
    {
        Gate::authorize('viewAny', Asset::class);

        $search = (string) $request->get('search', '');
        $folder = filled($request->get('folder')) ? static::folderAt((string) $request->get('folder')) : null;

        if (filled($request->get('folder')) && $folder === null) {
            return Response::error('No folder "'.$request->get('folder').'". Folders: '.(implode(', ', array_map(fn (AssetFolder $folder): string => $folder->path('/'), AssetFolder::query()->get()->filter(fn (AssetFolder $folder): bool => AssetAccess::canRead($user, $folder))->all())) ?: 'none yet').'.');
        }

        $assets = Asset::query()
            ->tap(fn ($query) => AssetAccess::scopeReadable($query, $user))
            ->ofType(AssetType::Image)
            ->with(['media', 'folder', 'tags'])
            ->withCount('usages')
            ->when($folder, fn ($query) => $query->whereIn('folder_id', $folder->descendantIds()))
            ->when($search !== '', fn ($query) => $query->search($search))
            ->latest()
            ->limit(100)
            ->get();

        return Response::json([
            'images' => $assets->map(fn (Asset $asset): array => [
                'id' => $asset->id,
                'title' => $asset->title,
                'alt' => $asset->alt,
                'folder' => $asset->folder?->path('/'),
                'tags' => $asset->tags->pluck('name')->all(),
                'used_in' => $asset->usages_count,
                'preview' => $asset->url('md'),
            ]),
        ]);
    }
}
