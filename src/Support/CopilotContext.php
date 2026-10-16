<?php

namespace Aurora\Support;

use Aurora\Mcp\Resources\SiteGuide;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Models\Asset;
use Aurora\Models\Locale;
use Aurora\Models\Page;
use EslamRedaDiv\FilamentCopilot\Agent\ContextBuilder;
use Filament\Facades\Filament;
use Filament\Pages\Page as FilamentPage;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * The copilot's instructions: how to work with the CMS (the same guidance the MCP server
 * gives external assistants), the site's content guide, and what the user is looking at
 * right now, so "change the heading to …" needs no "which page?".
 *
 * The screen comes from the chat request's Referer: the admin page the chat was opened on.
 * It only steers the model; every tool still checks the user's permissions itself.
 */
class CopilotContext extends ContextBuilder
{
    protected const OUTLINE_LIMIT = 80;

    public function build(): string
    {
        return implode("\n\n", array_filter([
            parent::build(),
            $this->buildScreenContext(),
        ]));
    }

    protected function buildBasePrompt(): string
    {
        $user = $this->user;
        $guide = rescue(fn (): string => (string) app(SiteGuide::class)->handle()->content(), '', report: false);

        return implode("\n\n", array_filter([
            // Who the assistant is and how it behaves: editable in Site settings (Copilot::DEFAULT_INSTRUCTIONS).
            Copilot::instructions($user->name),
            $this->languageContext(),
            Str::replace('call `whoami` first to see what you may do', 'call `whoami` if you are unsure what you may do', CmsServer::INSTRUCTIONS),
            "## Links\nWhen you point to something, link it with a short title as Markdown, e.g. [Open “About us” in the visual editor](https://…). Never paste a bare URL: links are shown as buttons, and the title is their label.",
            filled($guide) ? "## Content guide\n".$guide : null,
        ]));
    }

    /**
     * The language the person reads the admin panel in (their profile, or the default).
     */
    protected function languageContext(): string
    {
        $code = Locale::isActiveCode($this->user->locale ?? null) ? $this->user->locale : Locale::defaultCode();
        $name = Locale::options()[$code] ?? $code;

        return "## Language\n{$this->user->name} reads the admin panel in {$name} ({$code}). Reply in {$name}, unless they write to you in another language: then reply in that one.";
    }

    protected function buildResourceContext(): string
    {
        return '';
    }

    protected function buildPageContext(): string
    {
        return '';
    }

    protected function buildWidgetContext(): string
    {
        return '';
    }

    protected function buildScreenContext(): string
    {
        $screen = static::describeScreen(request()->headers->get('referer'));

        return $screen === null ? '' : "## What the user is looking at\n".$screen;
    }

    /**
     * A description of the admin screen at a URL, for the copilot.
     */
    public static function describeScreen(?string $url): ?string
    {
        $route = static::routeFor($url);

        if ($route === null || ! str_starts_with((string) $route->getName(), 'filament.')) {
            return null;
        }

        $resource = static::resourceFor($route);

        if ($resource !== null) {
            $record = rescue(fn (): ?Model => filled($key = $route->parameter('record')) ? $resource::resolveRecordRouteBinding($key) : null, report: false);

            if ($record instanceof Page) {
                return static::describePage($record, Str::afterLast($route->getName(), '.'));
            }

            $label = Str::lower($resource::getModelLabel());
            $screen = Str::afterLast((string) $route->getName(), '.');

            return $record === null
                ? 'The user is on the '.Str::lower($resource::getPluralModelLabel())." {$screen} screen ({$url})."
                : "The user has the {$label} “".strip_tags((string) $resource::getRecordTitle($record))."” (id: {$record->getKey()}) open on its {$screen} screen ({$url}). “This {$label}” means this one.";
        }

        $page = $route->getAction('controller');
        $page = is_string($page) ? Str::before($page, '@') : null;

        if ($page !== null && is_subclass_of($page, FilamentPage::class)) {
            $title = rescue(fn (): string => (string) ($page::getNavigationLabel() ?: class_basename($page)), class_basename($page), report: false);

            return "The user is on the “{$title}” screen of the admin panel ({$url}).";
        }

        return null;
    }

    /**
     * Suggested first questions for the screen at a URL, shown when a chat is empty.
     *
     * @return array<int, array{label: string, prompt: string, icon: string}>
     */
    public static function suggestionsFor(?string $url): array
    {
        $route = static::routeFor($url);
        $name = (string) $route?->getName();
        $resource = $route ? static::resourceFor($route) : null;
        $model = $resource ? $resource::getModel() : null;
        $onRecord = $route?->hasParameter('record') ?? false;

        $suggestions = match (true) {
            $model === Page::class && $onRecord => [
                ['Summarise this page', 'Give me a short overview of this page: what it says and how it is built.', 'phosphor-file-text'],
                ['Sharpen the headline', 'Suggest three punchier versions of the main heading on this page. Do not change anything yet.', 'phosphor-sparkle'],
                ['Write the SEO text', 'Write a meta title and meta description for this page, show them to me, and save them if I agree.', 'phosphor-magnifying-glass'],
                ['Add a call to action', 'Add a call-to-action block at the end of this page that fits its content.', 'phosphor-cursor-click'],
            ],
            $model === Page::class => [
                ['Draft a new page', 'Help me draft a new page. Ask me what it should be about first.', 'phosphor-plus'],
                ['What is still a draft?', 'Which pages are still drafts or scheduled? List them briefly.', 'phosphor-note-pencil'],
                ['Missing SEO text', 'Which pages have no meta description?', 'phosphor-magnifying-glass'],
            ],
            str_contains($name, '.resources.assets.') => [
                ['Missing alt text', 'Which images are missing alt text? Suggest alt text for each.', 'phosphor-eye'],
                ['Unused images', 'Which images are not used on any page?', 'phosphor-image'],
            ],
            str_contains($name, '.resources.menus.') => [
                ['Explain this menu', 'Describe the menus on the site and where they appear.', 'phosphor-list'],
                ['Add a page to the menu', 'Help me add a page to the main navigation. Ask me which one.', 'phosphor-plus'],
            ],
            str_contains($name, '.resources.forms.') => [
                ['Which forms exist?', 'List the forms on the site and the pages they are on.', 'phosphor-tray'],
                ['Put a form on a page', 'Help me add a form to a page. Ask me which form and page.', 'phosphor-file-plus'],
            ],
            default => [
                ['What can you do?', 'Briefly, what can you help me with here, given my role?', 'phosphor-question'],
                ['Draft a new page', 'Help me draft a new page. Ask me what it should be about first.', 'phosphor-plus'],
                ['Recent changes', 'Which pages were changed most recently, and by whom?', 'phosphor-clock'],
            ],
        };

        // In the person's language: the prompt is sent as if they typed it, so the answer comes back in it too.
        return array_map(fn (array $suggestion): array => [
            'label' => __($suggestion[0]),
            'prompt' => __($suggestion[1]),
            'icon' => $suggestion[2],
        ], $suggestions);
    }

    protected static function describePage(Page $page, string $screen): string
    {
        $where = match ($screen) {
            'design' => 'open in the visual editor',
            'edit' => 'open in the page settings form',
            default => 'open',
        };

        $lines = [
            "The user has the page “{$page->title}” {$where}.",
            "- id: {$page->id}",
            "- address: {$page->path()}",
            '- status: '.$page->status->value.($page->is_homepage ? ' (the homepage)' : ''),
            '',
            'When the user says “this page”, “here”, “the heading”, “the button” and so on, they mean this page: do not ask which page. Its blocks right now (id, type and a hint of the content):',
            ...static::outline(BlockOutline::for($page->blocks ?? [])),
            '',
            'Call `get_page` for the full data of a block before changing it.',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, string>
     */
    protected static function outline(array $blocks, int $depth = 0, int &$count = 0): array
    {
        $lines = [];

        foreach ($blocks as $block) {
            if (++$count > self::OUTLINE_LIMIT) {
                $lines[] = str_repeat('  ', $depth).'- … (more blocks; see get_page)';

                break;
            }

            $summary = filled($block['summary'] ?? null) ? ': '.Str::limit($block['summary'], 80) : '';
            $lines[] = str_repeat('  ', $depth)."- [{$block['id']}] {$block['label']}{$summary}";

            array_push($lines, ...static::outline($block['children'] ?? [], $depth + 1, $count));
        }

        return $lines;
    }

    /**
     * The assistant's reply as HTML, ready to show: links open in a new tab (the chat stays
     * where it is), and a link that only shows its address gets a readable label instead
     * ("Open “About us” in the visual editor").
     */
    public static function prepareLinks(string $html): string
    {
        return preg_replace_callback(
            '#<a href="([^"]+)">([^<]+)</a>#',
            function (array $match): string {
                $label = html_entity_decode($match[2]) === html_entity_decode($match[1])
                    ? e(static::linkLabel(html_entity_decode($match[1])))
                    : $match[2];

                return '<a href="'.$match[1].'" target="_blank" rel="noopener">'.$label.'</a>';
            },
            $html,
        ) ?? $html;
    }

    /**
     * A short, readable name for a link: what it opens, or the site it goes to.
     */
    public static function linkLabel(string $url): string
    {
        $route = static::routeFor($url);
        $name = (string) $route?->getName();
        $record = $route?->parameter('record');
        $resource = $route ? static::resourceFor($route) : null;

        $title = fn (): ?string => match (true) {
            $resource !== null && filled($record) => rescue(fn (): ?string => ($model = $resource::resolveRecordRouteBinding($record)) ? strip_tags((string) $resource::getRecordTitle($model)) : null, null, report: false),
            $name === 'pages.show' => Page::query()->whereSlug((string) $route->parameter('slug'))->first()?->title,
            $name === 'home' => Page::query()->where('is_homepage', true)->value('title'),
            $name === 'pages.preview' => ($page = $route->parameter('previewPage')) instanceof Page ? $page->title : Page::acrossSites()->whereKey($page)->value('title'),
            $name === 'files.show' => Asset::query()->whereKey($route->parameter('asset'))->value('title'),
            default => null,
        };

        $label = match (true) {
            str_ends_with($name, '.design') && filled($title()) => __('Open “:title” in the visual editor', ['title' => $title()]),
            in_array($name, ['pages.show', 'home'], true) && filled($title()) => __('View “:title” on the website', ['title' => $title()]),
            $name === 'pages.preview' && filled($title()) => __('Preview “:title”', ['title' => $title()]),
            filled($record) && filled($title()) => __('Open “:title”', ['title' => $title()]),
            $name === 'files.show' && filled($title()) => __('Open “:title”', ['title' => $title()]),
            $resource !== null && str_ends_with($name, '.index') => __('Open :list', ['list' => mb_strtolower($resource::getTitleCasePluralModelLabel())]),
            default => null,
        };

        return $label ?? (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    protected static function routeFor(?string $url): ?Route
    {
        if (blank($url) || parse_url($url, PHP_URL_HOST) !== request()->getHost()) {
            return null;
        }

        try {
            return app('router')->getRoutes()->match(Request::create($url));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return class-string<resource>|null
     */
    protected static function resourceFor(Route $route): ?string
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        foreach ($panel->getResources() as $resource) {
            if (str_starts_with((string) $route->getName(), $resource::getRouteBaseName($panel).'.')) {
                return $resource;
            }
        }

        return null;
    }
}
