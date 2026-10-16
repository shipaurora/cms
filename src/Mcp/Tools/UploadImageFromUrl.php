<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\User;
use Aurora\Support\AssetAccess;
use Aurora\Support\AssetLibrary;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Name('upload_image_from_url')]
#[Description('Add an image from a public https URL to the media library, with alt text, and return its id for image fields. Max 8 MB; jpg, png, webp, gif or avif.')]
#[IsOpenWorld]
class UploadImageFromUrl extends CmsTool
{
    protected bool $needsSite = false;

    protected const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('A public https URL of an image.')->required(),
            'title' => $schema->string()->description('A short title, e.g. "Team photo 2026".'),
            'alt' => $schema->string()->description('Alt text describing the image for screen readers. Strongly recommended.'),
            'folder' => $schema->string()->description('Optional folder path, e.g. "People/Team". Missing folders are created.'),
        ];
    }

    protected const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Looks a host up (tests replace it): every address it has.
     *
     * @var (Closure(string): array<int, string>)|null
     */
    public static ?Closure $resolver = null;

    /**
     * Fetch a public https address, following up to three redirects by hand: each address
     * (the first and every redirect) must resolve only to public IPs, and the request goes
     * to the IP that was checked, so neither a redirect nor a changed DNS answer can point
     * it at the server's own network.
     */
    protected function download(string $url): HttpResponse
    {
        for ($hops = 0; $hops <= 3; $hops++) {
            $host = parse_url($url, PHP_URL_HOST);

            if (! str_starts_with($url, 'https://') || ! is_string($host)) {
                throw new InvalidArgumentException('Only public https URLs can be downloaded.');
            }

            $ips = static::$resolver ? (static::$resolver)($host) : (gethostbynamel($host) ?: []);
            $public = array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);

            if ($ips === [] || count($public) !== count($ips)) {
                throw new InvalidArgumentException('Only public https URLs can be downloaded.');
            }

            $response = Http::timeout(15)
                ->withUserAgent(config('aurora.name').' media importer (+'.config('app.url').')')
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.reset($public)]],
                ])
                ->get($url);

            if (! $response->redirect()) {
                if ((int) $response->header('Content-Length') > self::MAX_BYTES) {
                    throw new InvalidArgumentException('The image is larger than 8 MB.');
                }

                return $response;
            }

            $location = (string) $response->header('Location');
            $url = str_starts_with($location, '/') ? 'https://'.$host.$location : $location;
        }

        throw new InvalidArgumentException('Too many redirects.');
    }

    protected function run(Request $request, User $user): Response
    {
        Gate::authorize('create', Asset::class);

        // Into a folder they may add files to: the deepest part of the path that exists yet.
        if (filled($request->get('folder')) && ! AssetAccess::canWrite($user, static::deepestFolder((string) $request->get('folder')))) {
            return Response::error('You can\'t add files to the folder "'.$request->get('folder').'".');
        }

        try {
            $response = $this->download((string) $request->get('url'));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        } catch (Throwable $exception) {
            return Response::error('Could not download the image: '.$exception->getMessage());
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! $response->successful() || ! isset(self::TYPES[$type])) {
            return Response::error("That URL did not return an image (status {$response->status()}, type \"{$type}\").");
        }

        if (strlen($response->body()) > self::MAX_BYTES) {
            return Response::error('The image is larger than 8 MB.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mcp').'.'.self::TYPES[$type];
        file_put_contents($temporary, $response->body());

        try {
            $asset = AssetLibrary::fromFile($temporary, array_filter([
                'title' => $request->get('title'),
                'alt' => $request->get('alt'),
                'folder' => $request->get('folder'),
            ]));
        } finally {
            @unlink($temporary);
        }

        $this->did('Added "'.$asset->title.'" to the media library', $asset);

        return Response::json(['id' => $asset->id, 'title' => $asset->title, 'alt' => $asset->alt, 'folder' => $asset->folder?->path('/'), 'preview' => $asset->url('md')]);
    }

    /**
     * The folder a path ("People/Team") leads to, as far as it exists (null: the top level).
     */
    protected static function deepestFolder(string $path): ?AssetFolder
    {
        $folder = null;

        foreach (array_filter(array_map('trim', explode('/', $path))) as $name) {
            $next = AssetFolder::query()->where('parent_id', $folder?->id)->where('name', $name)->first();

            if ($next === null) {
                break;
            }

            $folder = $next;
        }

        return $folder;
    }
}
