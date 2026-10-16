<?php

namespace Aurora\Support;

use Aurora\Enums\AiChannel;
use Aurora\Mcp\Servers\CmsServer;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * The CMS's MCP tools, offered to the in-panel copilot.
 *
 * The same classes that external AI apps (Claude Desktop and others) call over MCP,
 * wrapped by the Laravel AI SDK. They act as the signed-in user, so policies, the
 * publishing permission, revisions and the AI activity log all apply exactly as they
 * do over MCP, and a tool the user's role hides is left out here too. Calls run as
 * the assistant channel (AiChannel::Assistant): they need the "use-assistant"
 * permission rather than "use-mcp", and are credited to the assistant.
 */
class CopilotTools
{
    /**
     * Tools that change content: after one succeeds, an open editor reloads.
     *
     * @var array<int, string>
     */
    public const CHANGING = [
        'create_page', 'update_page', 'set_page_blocks', 'add_block', 'update_block', 'move_block', 'remove_block',
        'publish_page', 'delete_page', 'restore_revision', 'upload_image_from_url',
        'add_menu_item', 'update_menu_item', 'remove_menu_item',
    ];

    /**
     * @return array<int, McpServerTool>
     */
    public static function forCurrentUser(): array
    {
        $request = new Request([]);

        return collect(CmsServer::TOOLS)
            ->map(fn (string $class): Tool => app($class))
            ->filter(fn (Tool $tool): bool => ! method_exists($tool, 'shouldRegister') || $tool->shouldRegister($request))
            ->map(fn (Tool $tool): McpServerTool => new class($tool) extends McpServerTool
            {
                public function handle(ToolRequest $request): string
                {
                    return AiChannel::asAssistant(fn (): string => parent::handle($request));
                }
            })
            ->values()
            ->all();
    }
}
