---
name: aurora-development
description: Build a website on Aurora CMS (aurora/cms): create and register page blocks, add the website's own settings tabs, write the page view the editor and the site share, and use Aurora's models and helpers without depending on what Aurora is built on.
metadata:
  author: Aurora
---

# Aurora development

Aurora is the CMS; the app is the website. The website gives Aurora its blocks, its settings tabs and its page view through the hooks below, from its own service provider. Never import the packages Aurora builds on (the page builder, Filament plugins) in website code: use the `Aurora\` classes, so Aurora can change underneath without breaking the site.

## Blocks

A block is a class and a Blade view. Create both with `php artisan aurora:make-block "Pricing table"` (`--container` for a block that holds other blocks, `--category=`, `--icon=`), then fill them in.

1. The class extends `Aurora\Blocks\Block` (in `config('aurora.block_path')`, default `app/Blocks`): `type()` (a short slug, also the view name), `label()`, `description()`, `schema()` (Filament fields for the inspector), and optionally `icon()` (a Phosphor icon, e.g. `phosphor-chart-bar`), `category()` (layout, content, marketing, media, design or developer), `defaults()` (the data a new block starts with: make it look finished), `editables()` (text editable on the canvas: `Aurora\Blocks\Editable::text()`, `::richText()`, `->multiline()`, `->placeholder()`) and `aiHints()` (what the AI should know when filling it in).
2. The view is an anonymous component at `resources/views/components/blocks/{type}.blade.php` with `@props(['data' => []])`. The same view draws the block on the website, in the visual editor and in previews. Mark canvas-editable text with `@editable('field')`; use `Aurora\Blocks\Blocks::isEditing()` to skip animations or show placeholders while editing.
3. A block that holds other blocks implements `Aurora\Blocks\Contracts\Container` (`slots()` and `defaults()`) and draws each slot with `{!! Aurora\Blocks\Blocks::slot('name') !!}`.
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

## The page view

`config('aurora.page_view')` (default `page`) draws one page from `$page` (an `Aurora\Models\Page`), `$preview` and `$embedded` (true inside the visual editor). Its blocks render through the page builder's public component; keep the layout around them in the app.

## The admin theme

Aurora's admin comes with a prebuilt theme; run `php artisan filament:assets` after installing or updating Aurora. To show your blocks styled in the visual editor, build your own admin theme on Aurora's and name its Vite entry in `config('aurora.theme')`:

```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';
@plugin '@tailwindcss/typography';
@import '../../site/tokens.css'; /* your design tokens */
@import '../../../../vendor/aurora/cms/resources/css/filament/admin/index.css';
@source '../../../../app/PageBlocks/**/*';
@source '../../../../resources/views/components/blocks/**/*';
```

## Configuration and commands

- Publish the config with `php artisan vendor:publish --tag=aurora-config`: the CMS name (`AURORA_NAME`), demo mode (`AURORA_DEMO_LOGIN`), `page_view` and `translations_path`.
- `php artisan aurora:install` sets up the CMS (database, roles, a first admin; `--demo` for the demo content).
- `php artisan aurora:import-translations` loads labels into the admin's Translations screen; `aurora:sync-translations` adds new labels from the code; `aurora:export-translations` writes edited labels back to the lang files.
