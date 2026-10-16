<?php

namespace Aurora\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Plan, write and build a complete new page from a brief, using the site\'s blocks and voice.')]
class ComposePage extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument('brief', 'What the page is for, e.g. "a page for our new accessibility audit service".', required: true),
            new Argument('audience', 'Who will read it.', required: false),
        ];
    }

    public function handle(Request $request): Response
    {
        $brief = $request->get('brief');
        $audience = $request->get('audience', 'prospective clients');

        return Response::text(<<<TEXT
        Build a new page for this brief: "{$brief}". The audience is {$audience}.

        1. Call whoami to see what you may do, read the cms://guide resource, and call list_block_types.
        2. Outline the page section by section (one line each) before writing anything.
        3. Write the copy in the site's voice. Link buttons to existing pages (list_pages) by id.
        4. Create the page with create_page, passing the whole block tree, an excerpt and a meta description.
        5. Read it back with get_page and fix anything that looks off.
        6. Reply with a short summary and the preview and visual editor links.
        TEXT);
    }
}
