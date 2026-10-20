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

1. The class extends `Aurora\Blocks\Block` (in `config('aurora.block_path')`, default `app/Blocks`): `type()` (a short slug, also the view name), `label()`, `description()`, `schema()` (Filament fields for the inspector), and optionally `icon()` (a Phosphor icon, e.g. `phosphor-chart-bar`), `category()` (layout, content, marketing, media, design or developer), `defaults()` (the data a new block starts with: make it look finished), `editables()` (text editable on the canvas: `Aurora\Blocks\Editable::text()`, `::richText()`, `->multiline()`, `->placeholder()`), `aiHints()` (what the AI should know when filling it in) and `preview()` (an image URL of the block, shown beside it in the visual editor's block list).
2. The view is an anonymous component at `resources/views/components/blocks/{type}.blade.php` with `@props(['data' => []])`. The same view draws the block on the website, in the visual editor and in previews. Mark canvas-editable text with `@editable('field')`. Render fields with the global helpers, no `@php use` imports needed: `inline_text()` (with `*highlighted*` words), `plain_text()`, `rich_text()`, `shows_field('body', $data['body'] ?? null)` for optional fields, `link_href($data)` / `link_target($data)` for a `Fields::link()` group, and `is_editing()` to skip animations or show placeholders while editing (each is a short form of `Aurora\Support\Markup`, `Aurora\Blocks\Fields` or `Aurora\Blocks\Blocks`). Each also has a Blade directive (`@inlineText(…)`, `@plainText`, `@richText`, `@linkHref`, `@linkTarget`, `@blockSlot('name')`, and `@editing … @endediting`, `@shows('body', $data['body'] ?? null) … @endshows`). Render media library images with `<x-aurora::media :value="$data['image'] ?? null" size="md" />`.
3. A block that holds other blocks implements `Aurora\Blocks\Contracts\Container` (`slots()` and `defaults()`) and draws each slot with `{{ block_slot('name') }}`.
4. Blocks in `config('aurora.block_path')` are registered on their own. To set their order in the block palette, list them in a service provider's `boot()` (the rest follow):

```php
use Aurora\Blocks\Blocks;

Blocks::register([HeroBlock::class, StatBlock::class]);
```

Labels and descriptions go through `__()` and need a line in the app's `lang/{locale}.json`.

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

Each page is drawn by its layout (`Aurora\Models\Layout`, set up under Pages › More › Layouts), which names a Blade view. Aurora ships two, `aurora::layouts.standard` (the default: header, menu and footer) and `aurora::layouts.blank` (the page alone), published to `resources/views/vendor/aurora/layouts` on install (or with `php artisan vendor:publish --tag=aurora-layouts`) to make them the site's. A layout view gets `$page` (an `Aurora\Models\Page`), `$layout`, `$preview` and `$embedded` (true inside the visual editor). Its blocks render through `<x-aurora::blocks :page="$page" />`; keep the markup around them in the view. For a new layout, write a view anywhere in the app (`layouts.campaign`) and add a layout naming it in the Layouts dialog.

## The admin theme

Aurora's admin comes with a prebuilt theme, Borealis (`shipaurora/borealis-theme`); run `php artisan filament:assets` after installing or updating Aurora. A different look for the CMS is a theme package of its own: it registers a Filament theme (`FilamentAsset::register([Theme::make('acme', …)])`), and `config('aurora.theme')` names its id. The admin theme is only the CMS's look: the visual editor shows blocks in the website's own stylesheet (`config('aurora.website_css')`, default `resources/css/app.css`), found through Vite and scoped to the canvas, so nothing needs compiling for it. Style the page itself through `:root`, `html` and `body` in that stylesheet rather than classes on `<body>`, so the canvas gets it too.

## Search

Pages are searchable with Laravel Scout, out of the box: on save, Aurora keeps what a live page says (titles, excerpt, meta description and its blocks' text, in every language) for the index. Search the live pages visitors may see on the current site (the one the request's address is for) with `Aurora\Models\Page::search($terms)` (a Scout builder: `->paginate(10)`, `->get()`; `->query()` adds to its limits, never lifts them), or another site's with `Page::searchSite($terms, site: $site)`. By default it uses the database, with full-text search on MySQL; set `SCOUT_DRIVER` (meilisearch, typesense, algolia) for a search service, then `php artisan scout:import "Aurora\Models\Page"`. Each page's "Reindex" action (and the bulk one) indexes pages again.

## Configuration and commands

- Publish the config with `php artisan vendor:publish --tag=aurora-config`: the CMS name (`AURORA_NAME`), demo mode (`AURORA_DEMO_LOGIN`) and `translations_path`.
- `php artisan aurora:install` sets up the CMS (database, roles, a first admin), then offers to run the website's own seeders (`database/seeders/DatabaseSeeder`; `--seed` runs them without asking). A website's own content, such as its first homepage, belongs in those seeders, never in Aurora. For demo mode's one-click sign-in (`AURORA_DEMO_LOGIN`), a website registers its accounts from a service provider: `Aurora\Support\Demo::register([['name' => …, 'email' => …, 'role' => …]])`; only those accounts are offered.
- `php artisan aurora:import-translations` loads labels into the admin's Translations screen; `aurora:sync-translations` adds new labels from the code; `aurora:export-translations` writes edited labels back to the lang files.
