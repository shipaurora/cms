<?php

namespace Aurora\Mcp\Resources;

use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Support\CurrentSite;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('The site\'s voice, design rules and page recipes. Read before writing or composing content.')]
#[Uri('cms://guide')]
#[MimeType('text/markdown')]
class SiteGuide extends Resource
{
    public function handle(): Response
    {
        // The site being worked on (in the admin's assistant: the one picked there); with
        // more than one, the others are named so the assistant asks which one is meant.
        $site = Setting::get('site_name', config('app.name'));
        $tagline = Setting::get('tagline');
        $others = (auth()->user()?->accessibleSites() ?? collect())
            ->reject(fn (Site $other): bool => $other->is(CurrentSite::get()))
            ->map(fn (Site $other): string => $other->setting('site_name', $other->name).' ('.$other->handle.')');
        $tagline .= $others->isNotEmpty() ? "\n\nThis guide describes {$site}. The CMS also runs: {$others->implode(', ')}; their content is separate (pass \"site\" to the tools)." : '';

        return Response::text(<<<MARKDOWN
        # {$site}: content guide

        {$tagline}

        ## Voice
        - Calm, confident and concrete. Short sentences. No hype words ("revolutionary", "cutting-edge").
        - British English spelling (colour, organise).
        - Lead with the benefit to the reader, then the detail.

        ## Composition rules
        - One hero per page, always the first block, never inside a band.
        - After the hero, build the page from **bands**. Alternate band backgrounds (white → muted → white, or brand-soft) so sections read as separate.
        - Use at most one dark/aurora band per page, typically for statistics or a quote.
        - Start each band with a heading (eyebrow + title, optionally an intro), then a spacer (md), then the content.
        - Repeated items (features, cards, stats, plans, people, testimonials) go in a **grid**. Text beside an image goes in a **section** with two columns and valign "center".
        - End important pages with a call to action band.
        - Highlight one word per headline with *asterisks*. Never more.

        ## Recipes
        - **Landing page**: hero (centered) → logo-cloud band → features grid (6, style card) → section with image + heading + checklist → stats on aurora → testimonials grid (3) → cta.
        - **Service page**: band (brand-soft) with h1 heading + button-group → cards grid (3) with images → tabs or steps → cta.
        - **Article**: band (narrow) with heading (h1), image (16/9), rich-text; then a muted band with a page-list.

        ## Before you finish
        - Give every page an excerpt and a meta description (max 155 characters).
        - Leave new pages as drafts (or "review") and share the preview link.
        MARKDOWN);
    }
}
