---
name: aurora-development
description: "Build a website on Aurora CMS (shipaurora/cms): create and register page blocks, add the website's own settings tabs, write the page layouts the editor and the site share, and use Aurora's models and helpers without depending on what Aurora is built on."
metadata:
  author: Aurora
---

# Aurora development

Aurora is the CMS; the app is the website. The website gives Aurora its blocks, its settings tabs and its page view through the hooks below, from its own service provider. Never import the packages Aurora builds on (the page builder, Filament plugins) in website code: use the `Aurora\` classes, so Aurora can change underneath without breaking the site.

## Blocks

A block is a class and a Blade view. Create both with `php artisan aurora:make-block "Pricing table"` (`--container` for a block that holds other blocks, `--category=`, `--icon=`), then fill them in.

1. The class extends `Aurora\Blocks\Block` (in `config('aurora.block_path')`, default `app/Blocks`): `type()` (a short slug, also the view name), `label()`, `description()`, `schema()` (Filament fields for the inspector), and optionally `icon()` (a Phosphor icon, e.g. `phosphor-chart-bar`), `category()` (layout, content, marketing, media, design or developer), `defaults()` (the data a new block starts with: make it look finished), `editables()` (text editable on the canvas: `Aurora\Blocks\Editable::text()`, `::richText()` for a `RichEditor` field, which gets bold, italic, underline, strikethrough, links, H2/H3, lists and quotes right on the page (anything more, such as a table, is edited in the inspector), `->multiline()`, `->placeholder()`; mark the element with `@editable('field')` and draw it with `@shows` so an empty field can still be clicked), `itemEditables()` (the same for the fields of a repeater's items, `['items' => ['question' => Editable::text()]]`; mark each with `@editableItem('items', $loop->index, 'question')`), `aiHints()` (what the AI should know when filling it in) and `preview()` (an image URL of the block, shown beside it in the visual editor's block list).
2. The view is an anonymous component at `resources/views/components/blocks/{type}.blade.php` with `@props(['data' => []])`. The same view draws the block on the website, in the visual editor and in previews. Mark canvas-editable text with `@editable('field')`. Render fields with the global helpers, no `@php use` imports needed: `inline_text()` (with `*highlighted*` words), `plain_text()`, `rich_text()`, `shows_field('body', $data['body'] ?? null)` for optional fields, `link_href($data)` / `link_target($data)` for a `Fields::link()` group, and `is_editing()` to skip animations or show placeholders while editing (each is a short form of `Aurora\Support\Markup`, `Aurora\Blocks\Fields` or `Aurora\Blocks\Blocks`). Each also has a Blade directive (`@inlineText(…)`, `@plainText`, `@richText`, `@linkHref`, `@linkTarget`, `@blockSlot('name')`, and `@editing … @endediting`, `@shows('body', $data['body'] ?? null) … @endshows`). Render media library images with `<x-aurora::media :value="$data['image'] ?? null" size="md" />`.
3. A block that holds other blocks implements `Aurora\Blocks\Contracts\Container` (`slots()` and `defaults()`) and draws each slot with `{{ block_slot('name') }}`. A block view can ask where it is: `current_page()` (the `Aurora\Models\Page` being drawn, on the website, in previews and on the canvas; null in a palette preview), `block_depth()` (`@blockDepth`, 0 straight on the page, 1 in a column) and `is_nested_block()` (`@nested … @endnested`), from `Aurora\Blocks\Blocks::page()`, `::depth()` and `::isNested()`.
4. Blocks in `config('aurora.block_path')` are registered on their own. To set their order in the block palette, list them in a service provider's `boot()` (the rest follow):

```php
use Aurora\Blocks\Blocks;

Blocks::register([HeroBlock::class, StatBlock::class]);
```

Labels and descriptions go through `__()` and need a line in the app's `lang/{locale}.json`.

The page builder's own blocks (section, text, list, quote, image, button, video, embed, spacer, divider, shortcode, code) are drawn with its stylesheet, which a site's layout doesn't load. Leave out the ones the site's stylesheet doesn't style: `Blocks::forget('text', 'quote', 'divider')` in a service provider (registering a type again brings it back).

`<x-aurora::blocks :page="$page" class="flex flex-col gap-24" />` passes attributes to the element around the blocks; `:blocks="$blocks"` draws a list of blocks instead of a page's (in the language being shown; a container left out takes its blocks along).

Translating: a block's text fields (text, multi-line, rich text, Markdown, tags, and lists whose items have text) are translated; everything else is the same in every language. A text field that's the same in every language too (a record's id or a handle in a `TextInput`) goes in `sameInEveryLanguage()`. Text that's the same in a translation (a name) counts as translated once the translator chooses "Same in …" in the inspector; `BlockTranslations::extract($structure, $translated, $same)` keeps it from code.

## The website's settings

Settings the website's templates read (a header button, footer text, social links) are a tab of their own under Settings, registered from a service provider. Keys listed in `siteKeys` are kept per site:

```php
use Aurora\Support\SettingsTabs;
use Filament\Schemas\Components\Tabs\Tab;

SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))->schema([
    TextInput::make('footer_note')->label(__('Footer note')),
]), siteKeys: ['footer_note']);
```

Read them in templates with `Aurora\Models\Setting::get('footer_note')`.

## Page layouts

Each page is drawn by its layout (`Aurora\Models\Layout`, set up under Pages › More › Layouts), which names a Blade view. Aurora ships two, `aurora::layouts.standard` (the default: header, menu and footer) and `aurora::layouts.blank` (the page alone), published to `resources/views/vendor/aurora/layouts` on install (or with `php artisan vendor:publish --tag=aurora-layouts`) to make them the site's. A layout view gets `$page` (an `Aurora\Models\Page`), `$layout`, `$settings`, `$preview` and `$embedded` (true inside the visual editor). Its blocks render through `<x-aurora::blocks :page="$page" />`; keep the markup around them in the view. For a new layout, write a view anywhere in the app (`layouts.campaign`) and add a layout naming it in the Layouts dialog. Or define it in code, as a component library does: a class extending `Aurora\Layouts\PageLayout` (`handle()`, `name()`, `view()`, and optionally `description()`, `icon()` and `schema()` for its own fields), registered from a service provider's `boot()` with `Layouts::register([CampaignLayout::class])`, or found on their own in `config('aurora.layout_path')` (`app/Layouts`). `php artisan make:aurora-layout "Campaign"` (`--description=`, `--icon=`) makes the class there and its view, `resources/views/layouts/campaign.blade.php`, from the site's standard layout. It's offered with the layouts added in the admin and can be made the default there, but not edited or deleted; changing `handle()` makes it a new layout. A layout that needs settings of its own that aren't blocks (a background image) registers fields for its view in a service provider's `boot()`: `Layouts::schema('layouts.campaign', fn (): array => [AssetPicker::make('background')->label(__('Background image'))])` (`Aurora\Support\Layouts`, `Aurora\Filament\Forms\Components\AssetPicker`). The page form shows them under the layout picker, they're saved in the page's `settings` column by view (another view's field of the same name is kept apart), and the view reads its own from `$settings` (`$settings['background'] ?? null`): always with a fallback, as pages saved before the fields existed don't have them. Elsewhere, `$page->layoutSettings()` gives the page's values for its current layout and `$page->layoutSetting('background', $default)` one of them. AI tools see and set them too (`list_layouts`, `get_page`, `layout_settings` on `create_page` and `update_page`), so give each field a clear label and helper text.

## The visual editor's canvas

The canvas draws blocks with the website's stylesheet, wrapped in `@scope (.fpb-canvas)` (`Aurora\Support\CanvasStyles`):

- `:root`, `html`, `body` and `.fpb-canvas` itself are the canvas, and combinators are kept: `body > .fpb-block` is a block straight on the canvas. Target canvas blocks by `:scope > .fpb-block`, `.fpb-block[data-type="hero"]` and `.fpb-block-body` (the block's own markup); on the website, a top-level `:scope` is `:root`, so such rules do nothing there.
- The page's background and text colour (on `html`/`body`, in a layer or not) become the canvas's `--fpb-canvas-bg` and `--fpb-canvas-fg`; set those two on `html` to choose them yourself.
- Width breakpoints (`@media (width >= 48rem)`, `min-width`, `max-width`) are asked of the canvas (`@container fpb-canvas`), so the Tablet and Mobile previews show the site's tablet and mobile layouts; `vw` is the canvas's width (`cqw`). Other media queries (print, colour scheme, hover) stay as they are.
- Fonts: load them in the stylesheet (`@import url(…)` first, or `@font-face`) or with Laravel's Vite font loading. A `<link>` in the layout's `<head>` doesn't reach the canvas.

## The admin theme

Aurora's admin comes with a prebuilt theme, Borealis (`shipaurora/borealis`); run `php artisan filament:assets` after installing or updating Aurora. A different look for the CMS is a theme package of its own: it registers a Filament theme (`FilamentAsset::register([Theme::make('acme', …)])`), and `config('aurora.theme')` names its id. The admin theme is only the CMS's look: the visual editor shows blocks in the website's own stylesheet (`config('aurora.website_css')`, default `resources/css/app.css`), found through Vite and scoped to the canvas, so nothing needs compiling for it. Style the page itself through `:root`, `html` and `body` in that stylesheet rather than classes on `<body>`, so the canvas gets it too.

## The website's own screens, models and routes

- **Admin screens:** register the website's Filament resources and pages from a service provider: `AdminPanel::discover(app_path('Filament/Resources'), 'App\Filament\Resources')`, or `AdminPanel::resources([...])` / `::pages([...])` (`Aurora\Support\AdminPanel`). The admin is per site (`/admin/{site}/…`): resources show every site's records unless registered with `perSite: true`, for a model with a `site` relation. Put them in a group of their own with `AdminPanel::navigationGroup('Events', fn (): string => __('Events'))`, after Content by default (`after: 'AI'`, or another of the website's).
- **Publishing:** a model published like a page uses `Aurora\Models\Concerns\HasPublishing` (`Event::publishingColumns($table)` in its migration; `Event::live()`, `isLive()`, `isScheduled()`, `hasEnded()`), and `Aurora\Filament\Publishing::section()`, `::column()` and `::filter()` in its resource.
- **Routes and languages:** `Aurora\Http\Middleware\UseSiteLocale` runs on every web route: an address starting with a language's prefix (`/en/events`) sets that language (`app()->getLocale()`, `Aurora\Support\ContentLocale::current()`). Define a localized route under each prefix (`ContentLocale::prefix($code)` gives it, empty for the default language) next to the default one. A page's address in a language is `$page->path($locale)` (or `url($locale)`); page routes take every other address, so register website routes in the app's `routes/web.php`, which loads first. A website route's view gets the site's layout around its own content with `<x-aurora::layout :title="__('Events')" description="…">…</x-aurora::layout>` (`layout="landing"` for another layout than the default, `:settings` for its fields): the layout gets a stand-in page with that title and description and the request's address (`Page::standIn()`), and the content stands where its blocks would; `current_page()` inside answers it. From a controller, `Layouts::wrap($html, 'Events')` returns the same.
- **Menus:** add menu builder panels for the website's routes or models (`Datlechin\FilamentMenuBuilder\MenuPanel\StaticMenuPanel`, `ModelMenuPanel` for a model implementing `MenuPanelable`) with `Menus::panels([...])`, and name the paths every language has under its prefix with `Menus::localized(['/events'])`: a menu link to `/events` then goes to `/en/events` in English (`Aurora\Support\Menus`).
- **Shortcodes:** editors write their own in Settings › Website › Shortcodes (`[phone]` and its text, the same on every site, printed as text; `Aurora\Support\CustomShortcodes`).
- **Sitemap:** `Sitemap::add(fn (): iterable => [...])` adds the website's addresses to `sitemap.xml`, each a URL or `['url' => …, 'updated_at' => …, 'priority' => 0.6]` (`Aurora\Support\Sitemap`).
- **Labels:** the admin's labels come from, in order: what's edited in Administration › Translations, the app's `lang/{locale}.json`, then Aurora's and the packages'. So a site's own `"Events": "Events"` in `lang/nb.json` is its label at once, whatever Aurora's says; `aurora:import-translations` only lists labels in the Translations screen.

## Importing content

Pages are `Aurora\Models\Page` records: `title`, `slug`, `excerpt`, `meta_title` and `meta_description` are JSON per language (`setTranslation('slug', 'nb', 'om-oss')`); `blocks` is one flat list for every language, each `['id', 'type', 'data', 'parent', 'slot', 'settings']` (`Aurora\Support\BlockTreeBuilder::flatten()` makes it from a nested tree, with `'children' => ['slot' => [...]]`); `block_translations` is `{"nb": {"<block id>": {"<field>": "…"}}}`, text only; `settings` holds the layout's fields, `{"layouts": {"<view>": {…}}}`, written with `$page->settingsWithLayoutValues($values, 'campaign')` (a layout or its handle). Status and dates are `status`, `published_at` and `unpublish_at`.

- Files: `AssetLibrary::fromFile($path, ['id' => $uuid, 'title' => …, 'alt' => …, 'folder' => 'Photos/2026'])`. With an `id` (say one made from the source's), running the import again finds the asset instead of adding another. The largest file is `aurora.media.max_file_mb` (50 MB; images `max_image_mb`, 10 MB).
- Saves from code go straight on the page, live or not. Only the page form, the visual editor and AI tools keep a live page's changes as unpublished changes (`Aurora\Support\WorkingCopies`).
- Redirects are `Aurora\Models\Redirect` (`from_path`, and `page_id`, `asset_id` or `to_url`, with `status_code`); menus are `Aurora\Models\Menu` with `MenuItem`s (`linkable` a page, or a `url`).

## Search

Pages are searchable with Laravel Scout, out of the box: on save, Aurora keeps what a live page says (titles, excerpt, meta description and its blocks' text, in every language) for the index. Search the live pages visitors may see on the current site (the one the request's address is for) with `Aurora\Models\Page::search($terms)` (a Scout builder: `->paginate(10)`, `->get()`; `->query()` adds to its limits, never lifts them), or another site's with `Page::searchSite($terms, site: $site)`. By default it uses the database, with full-text search on MySQL; set `SCOUT_DRIVER` (meilisearch, typesense, algolia) for a search service, then `php artisan scout:import "Aurora\Models\Page"`. Each page's "Reindex" action (and the bulk one) indexes pages again.

## Configuration and commands

- Publish the config with `php artisan vendor:publish --tag=aurora-config`: the CMS name (`AURORA_NAME`), demo mode (`AURORA_DEMO_LOGIN`) and `translations_path`.
- `php artisan aurora:install` sets up the CMS (database, roles, a first admin), then offers to run the website's own seeders (`database/seeders/DatabaseSeeder`; `--seed` runs them without asking). A website's own content, such as its first homepage, belongs in those seeders, never in Aurora. For demo mode's one-click sign-in (`AURORA_DEMO_LOGIN`), a website registers its accounts from a service provider: `Aurora\Support\Demo::register([['name' => …, 'email' => …, 'role' => …]])`; only those accounts are offered.
- `php artisan aurora:import-translations` loads labels into the admin's Translations screen; `aurora:sync-translations` adds new labels from the code; `aurora:export-translations` writes edited labels back to the lang files.
