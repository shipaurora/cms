<?php

namespace Aurora\Http\Controllers;

use Aurora\Blocks\Blocks;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\Layouts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * One block alone, drawn from its example data (Block::sample()) on the blank layout with
 * the website's stylesheet: the page to take its screenshot from. For people who may edit
 * pages, or anyone with the signed address aurora:block-previews gives (a screenshot tool
 * isn't signed in).
 */
class BlockSampleController
{
    public function __invoke(Request $request, string $type): Response
    {
        if (! $request->hasValidSignature()) {
            Gate::authorize('update Page');
        }

        $block = Blocks::find($type);

        abort_if($block === null, 404);

        $blocks = BlockTreeBuilder::flatten([$block::sample()]);
        $content = new HtmlString(Blade::render('<x-aurora::blocks :blocks="$blocks" />', ['blocks' => $blocks]));

        return response(Layouts::wrap($content, $block::label(), layout: 'blank', noindex: true));
    }
}
