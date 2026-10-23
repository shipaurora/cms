<?php

namespace Aurora\Support;

use Closure;
use DateTimeInterface;

/**
 * Addresses for sitemap.xml besides the live pages': the website's own (events, albums),
 * added from a service provider. Each source runs when the sitemap is made.
 *
 *     Sitemap::add(fn (): iterable => Event::query()->live()->get()->map(fn (Event $event): array => [
 *         'url' => route('events.show', $event),
 *         'updated_at' => $event->updated_at,
 *     ]));
 */
class Sitemap
{
    /**
     * @var array<int, Closure(): iterable<int, string|array{url: string, updated_at?: ?DateTimeInterface, priority?: ?float}>>
     */
    protected static array $sources = [];

    /**
     * @param  Closure(): iterable<int, string|array{url: string, updated_at?: ?DateTimeInterface, priority?: ?float}>  $source  addresses, or each with when it last changed and its priority (0 to 1)
     */
    public static function add(Closure $source): void
    {
        static::$sources[] = $source;
    }

    public static function forget(): void
    {
        static::$sources = [];
    }

    /**
     * Every address added, as the sitemap lists them.
     *
     * @return array<int, array{url: string, updated_at: ?DateTimeInterface, priority: ?float}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (static::$sources as $source) {
            foreach ($source() as $entry) {
                $entry = is_string($entry) ? ['url' => $entry] : $entry;

                if (filled($entry['url'] ?? null)) {
                    $entries[] = ['url' => url($entry['url']), 'updated_at' => $entry['updated_at'] ?? null, 'priority' => $entry['priority'] ?? null];
                }
            }
        }

        return $entries;
    }
}
