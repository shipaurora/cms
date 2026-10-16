<?php

namespace Aurora\Mcp\Servers;

use Aurora\Mcp\Prompts\ComposePage;
use Aurora\Mcp\Resources\SiteGuide;
use Aurora\Mcp\Tools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;

#[Version('1.0.0')]
#[Instructions(CmsServer::INSTRUCTIONS)]
class CmsServer extends Server
{
    /**
     * How to work with the CMS. Shared with the in-panel copilot (Aurora\Support\CopilotContext).
     */
    public const INSTRUCTIONS = <<<'MARKDOWN'
    You are connected to the website's content management system, acting as the person who connected you.
    Your permissions are theirs: call `whoami` first to see what you may do.

    - The CMS may run several websites. If `whoami` lists more than one site, pass `site` (its handle) to every tool, and check with the user which site they mean. With one site, leave `site` out.

    - Pages are built from typed blocks. Call `list_block_types` before writing content: it lists every block, field, option and slot.
    - Read `cms://guide` for the site's voice and page recipes.
    - Use `get_page` to see a page's block tree with ids, then make targeted changes with `update_block`, `add_block`, `move_block` and `remove_block`. Use `set_page_blocks` or `create_page` with a full tree for new pages or full redesigns.
    - Every change is saved as a revision attributed to the user, and can be undone with `restore_revision`.
    - Do not publish unless asked. Share the preview link so the user can review your work.
    MARKDOWN;

    /**
     * Also offered to the in-panel copilot (Aurora\Support\CopilotTools).
     *
     * @var array<int, class-string<Tool>>
     */
    public const TOOLS = [
        Tools\WhoAmI::class,
        Tools\ListSites::class,
        Tools\ListBlockTypes::class,
        Tools\ListPages::class,
        Tools\GetPage::class,
        Tools\CreatePage::class,
        Tools\UpdatePage::class,
        Tools\SetPageBlocks::class,
        Tools\AddBlock::class,
        Tools\UpdateBlock::class,
        Tools\MoveBlock::class,
        Tools\RemoveBlock::class,
        Tools\PublishPage::class,
        Tools\DeletePage::class,
        Tools\ListRevisions::class,
        Tools\RestoreRevision::class,
        Tools\ListImages::class,
        Tools\ListForms::class,
        Tools\UploadImageFromUrl::class,
        Tools\GetMenus::class,
        Tools\AddMenuItem::class,
        Tools\UpdateMenuItem::class,
        Tools\RemoveMenuItem::class,
    ];

    public int $defaultPaginationLength = 50;

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        // Named after the CMS (config('aurora.name')), not hard-coded: a #[Name] attribute must be a constant.
        $this->name = config('aurora.name');
    }

    protected array $tools = self::TOOLS;

    protected array $resources = [
        SiteGuide::class,
    ];

    protected array $prompts = [
        ComposePage::class,
    ];
}
