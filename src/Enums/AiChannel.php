<?php

namespace Aurora\Enums;

/**
 * Which AI is calling the CMS tools: an external app over MCP (Claude Desktop and the
 * like, permission "use-mcp") or the assistant in the admin panel (permission
 * "use-assistant"). They share the tools but are allowed, logged and credited separately.
 */
enum AiChannel: string
{
    case Mcp = 'mcp';
    case Assistant = 'assistant';

    protected const CURRENT = 'cms.ai-channel';

    public static function current(): self
    {
        return app()->bound(self::CURRENT) ? app(self::CURRENT) : self::Mcp;
    }

    /**
     * Run a tool call as the in-panel assistant.
     */
    public static function asAssistant(callable $callback): mixed
    {
        $previous = app()->bound(self::CURRENT) ? app(self::CURRENT) : null;

        app()->instance(self::CURRENT, self::Assistant);

        try {
            return $callback();
        } finally {
            $previous === null ? app()->forgetInstance(self::CURRENT) : app()->instance(self::CURRENT, $previous);
        }
    }

    public function permission(): string
    {
        return match ($this) {
            self::Mcp => 'use-mcp',
            self::Assistant => 'use-assistant',
        };
    }

    public function revisionSource(): RevisionSource
    {
        return match ($this) {
            self::Mcp => RevisionSource::Mcp,
            self::Assistant => RevisionSource::Assistant,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mcp => __('External AI app (MCP)'),
            self::Assistant => __('Assistant in the admin panel'),
        };
    }
}
