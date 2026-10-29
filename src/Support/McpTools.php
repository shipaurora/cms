<?php

namespace Aurora\Support;

use Aurora\Mcp\Servers\CmsServer;
use Laravel\Mcp\Server\Tool;

/**
 * The tools AI apps and the in-panel assistant work with: Aurora's, then the website's own,
 * for its models (events, products …). A website's tool extends Aurora\Mcp\Tools\CmsTool, so
 * it acts as the person who connected, checks their permissions through the website's
 * policies, is written to the AI activity log, and answers errors as messages. Registered
 * from a service provider.
 *
 *     McpTools::register([ListEvents::class, CreateEvent::class]);
 */
class McpTools
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected static array $tools = [];

    /**
     * @param  array<int, class-string<Tool>>  $tools
     */
    public static function register(array $tools): void
    {
        static::$tools = array_values(array_unique([...static::$tools, ...$tools]));
    }

    /**
     * Take tools away again (a test).
     *
     * @param  array<int, class-string<Tool>>  $tools
     */
    public static function forget(array $tools): void
    {
        static::$tools = array_values(array_diff(static::$tools, $tools));
    }

    /**
     * Aurora's tools and the website's.
     *
     * @return array<int, class-string<Tool>>
     */
    public static function all(): array
    {
        return array_values(array_unique([...CmsServer::TOOLS, ...static::$tools]));
    }
}
