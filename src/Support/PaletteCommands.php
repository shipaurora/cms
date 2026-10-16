<?php

namespace Aurora\Support;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Pages\AiAssistant;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Filament\Resources\Menus\MenuResource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Redirects\RedirectResource;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Models\Asset;
use Aurora\Models\Page;
use Illuminate\Http\Request;
use Throwable;
use Usamamuneerchaudhary\CommandPalette\Support\CommandItem;

/**
 * The CMS's own commands in the command palette (⌘K): the things people come to do, not
 * just places to go. Each one only shows for people who may do it.
 *
 * Links ending in `?action=name` open that page's action straight away (Filament mounts
 * it from the URL), so "Invite someone" lands in the invite dialog. Assistant commands
 * fire `cms-assistant-prompt`, which resources/js/admin/copilot.js turns into the chat
 * opening with that prompt.
 */
class PaletteCommands
{
    /**
     * @return array<int, CommandItem>
     */
    public static function all(): array
    {
        if (auth()->guest()) {
            return [];
        }

        return [
            ...static::forCurrentPage(),
            ...static::actions(),
        ];
    }

    /**
     * @return array<int, CommandItem>
     */
    protected static function actions(): array
    {
        $user = auth()->user();
        $home = Page::query()->homepage()->first();
        $assistant = static::canUseAssistant();

        return array_values(array_filter([
            $user->can('create', Page::class)
                ? static::item('Draft a new page', 'Start from a blank page', 'phosphor-file-plus', PageResource::getUrl('index', ['action' => 'create']), ['create', 'add', 'new', 'page'])
                : null,
            $assistant && $user->can('create', Page::class)
                ? static::assistant('Draft a page with the assistant', 'Describe it, and the AI drafts the blocks', 'Help me draft a new page. Ask me what it should be about first.', ['ai', 'write', 'generate', 'create'])
                : null,
            $home && $user->can('update', $home)
                ? static::item('Edit the homepage', 'Open it in the visual editor', 'phosphor-house', PageResource::getUrl('design', ['record' => $home]), ['front', 'start', 'landing'])
                : null,
            $user->can('create', Asset::class)
                ? static::item('Upload images', 'Add images to the media library', 'phosphor-upload-simple', AssetResource::getUrl('index', ['action' => 'upload']), ['media', 'photo', 'picture', 'file'])
                : null,
            $user->can('create', Asset::class)
                ? static::item('Create a media folder', 'Organise the media library', 'phosphor-folder-plus', AssetResource::getUrl('index', ['action' => 'newFolder']), ['media', 'directory'])
                : null,
            UserResource::canCreate()
                ? static::item('Invite someone', 'Send an invitation by email', 'phosphor-envelope-simple', UserResource::getUrl('index', ['action' => 'invite']), ['user', 'people', 'team', 'member', 'add'])
                : null,
            RedirectResource::canCreate()
                ? static::item('Create a redirect', 'Send an old address to a new one', 'phosphor-arrow-u-up-right', RedirectResource::getUrl('index', ['action' => 'create']), ['url', '301', 'moved', 'link'])
                : null,
            FormResource::canCreate()
                ? static::item('Create a form', 'Contact, sign-up or any other form', 'phosphor-clipboard-text', FormResource::getUrl('create'), ['contact', 'submissions'])
                : null,
            MenuResource::canCreate()
                ? static::item('Create a menu', 'A new navigation menu', 'phosphor-list', MenuResource::getUrl('index', ['action' => 'create']), ['navigation', 'nav'])
                : null,
            AiAssistant::canAccess()
                ? static::item('Connect an AI app', 'Let Claude and others work with your permissions', 'phosphor-link', AiAssistant::getUrl(), ['mcp', 'claude', 'connector', 'token'])
                : null,
            $assistant
                ? static::assistant('Open the assistant', 'Chat with the AI assistant', null, ['ai', 'chat', 'help'])
                : null,
            static::item('View the website', CurrentSite::url('/'), 'phosphor-globe', CurrentSite::url('/'), ['site', 'live', 'public'])->openInNewTab(),
        ]));
    }

    /**
     * Commands for the page being edited, when the palette is opened from its editor.
     * The palette loads its commands in a request of its own, so the screen comes from
     * that request's Referer; the record is still authorised like any other.
     *
     * @return array<int, CommandItem>
     */
    protected static function forCurrentPage(): array
    {
        $page = static::currentPage(request());
        $user = auth()->user();

        if ($page === null || ! $user->can('view', $page)) {
            return [];
        }

        $canUpdate = $user->can('update', $page);
        $onDesign = str_contains((string) request()->headers->get('referer'), '/design');
        $group = 'This page';

        return array_values(array_filter([
            $canUpdate && ! $onDesign
                ? static::item('Open in the visual editor', __('Design “:title”', ['title' => $page->title]), 'phosphor-paint-brush', PageResource::getUrl('design', ['record' => $page]), ['design', 'canvas', 'blocks'], $group)
                : null,
            $canUpdate && $onDesign
                ? static::item('Page settings', __('Title, address, SEO and publishing for “:title”', ['title' => $page->title]), 'phosphor-sliders-horizontal', PageResource::getUrl('edit', ['record' => $page]), ['seo', 'title', 'address', 'slug'], $group)
                : null,
            static::item('Preview this page', 'See it as visitors will, in a new tab', 'phosphor-eye', $page->previewUrl(), ['preview'], $group)->openInNewTab(),
            $page->status !== PageStatus::Published && $user->can('publish', $page)
                ? static::item('Publish this page', __('Make “:title” live', ['title' => $page->title]), 'phosphor-rocket-launch', PageResource::getUrl('edit', ['record' => $page, 'action' => 'publish']), ['live', 'go live'], $group)
                : null,
            $page->isLive()
                ? static::item('View this page on the website', $page->path(), 'phosphor-arrow-square-out', $page->url(), ['live', 'open'], $group)->openInNewTab()
                : null,
            static::canUseAssistant() && $canUpdate
                ? static::assistant('Ask the assistant to improve this page', 'Suggestions for the headline, copy and structure', 'Look at the page I have open and suggest three concrete improvements to its headline, copy and structure. Do not change anything yet.', ['ai', 'improve', 'review'], $group)
                : null,
        ]));
    }

    protected static function currentPage(Request $request): ?Page
    {
        $referer = (string) $request->headers->get('referer');

        if (! preg_match('#/admin/(?:[^/]+/)?pages/([0-9a-f-]{36})/(edit|design)#i', $referer, $matches)) {
            return null;
        }

        try {
            return Page::query()->find($matches[1]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected static function item(string $label, ?string $description, string $icon, string $url, array $keywords = [], string $group = 'Actions'): CommandItem
    {
        return CommandItem::make(__($label), $url, __($group), $icon, keywords: $keywords)
            ->id('cms:'.str($group.' '.$label)->slug())
            ->description($description === null ? null : __($description))
            ->priority(static::priority($group));
    }

    /**
     * Opens the assistant's chat, and sends it a prompt when there is one.
     *
     * @param  array<int, string>  $keywords
     */
    protected static function assistant(string $label, string $description, ?string $prompt, array $keywords = [], string $group = 'Actions'): CommandItem
    {
        return CommandItem::make(__($label), null, __($group), 'phosphor-sparkle', keywords: $keywords)
            ->id('cms:'.str($group.' '.$label)->slug())
            ->description(__($description))
            ->priority(static::priority($group))
            ->dispatch('cms-assistant-prompt', ['prompt' => $prompt === null ? null : __($prompt)]);
    }

    /**
     * With an empty search: the open page's commands first, then actions, then places.
     */
    protected static function priority(string $group): int
    {
        return $group === 'This page' ? 30 : 20;
    }

    protected static function canUseAssistant(): bool
    {
        return Copilot::isConfigured() && auth()->user()->can('use-assistant');
    }
}
