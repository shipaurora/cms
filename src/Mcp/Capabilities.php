<?php

namespace Aurora\Mcp;

use Aurora\Mcp\Servers\CmsServer;
use Laravel\Mcp\Request;
use ReflectionClass;

/**
 * The tools an assistant gets for the signed-in user, in human terms.
 *
 * Shown on the "Connect AI apps" page and on the OAuth consent screen, so people see
 * exactly what they are granting before they grant it. Hidden tools are left out, the
 * same way the MCP server leaves them out.
 */
class Capabilities
{
    /**
     * @return array<int, array{name: string, description: string, writes: bool}>
     */
    public static function tools(): array
    {
        $tools = (new ReflectionClass(CmsServer::class))->getProperty('tools')->getDefaultValue();
        $request = new Request;

        return collect($tools)
            ->map(fn (string $class) => app($class))
            ->filter(fn ($tool) => ! method_exists($tool, 'shouldRegister') || $tool->shouldRegister($request))
            ->map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => str($tool->description())->before('. ')->rtrim('.')->append('.')->toString(),
                'writes' => ! str_starts_with($tool->name(), 'list_') && ! str_starts_with($tool->name(), 'get_') && $tool->name() !== 'whoami',
            ])
            ->values()
            ->all();
    }

    /**
     * A one-line, plain-language summary of what the assistant may do, for the consent screen.
     *
     * @return array<int, array{icon: string, text: string, allowed: bool}>
     */
    public static function summary(): array
    {
        $user = auth()->user();

        return [
            ['icon' => 'phosphor-eye', 'text' => 'Read every page, menu and the block catalogue', 'allowed' => $user->can('view-any Page')],
            ['icon' => 'phosphor-note-pencil', 'text' => $user->can('update-any Page') ? 'Create and edit any page' : 'Create pages and edit the ones you wrote', 'allowed' => $user->can('create Page') || $user->can('update Page')],
            ['icon' => 'phosphor-rocket-launch', 'text' => 'Publish, schedule and unpublish pages', 'allowed' => $user->can('publish Page')],
            ['icon' => 'phosphor-text-align-left', 'text' => 'Change the website\'s menus', 'allowed' => $user->can('update Menu')],
            ['icon' => 'phosphor-trash', 'text' => 'Move pages to the trash', 'allowed' => $user->can('delete Page')],
        ];
    }
}
