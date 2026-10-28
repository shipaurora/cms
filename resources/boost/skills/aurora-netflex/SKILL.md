---
name: aurora-netflex
description: "Port a website built on Netflex (netflex/framework or netflex/sdk, Laravel with models that read the headless Netflex API) to Aurora CMS: a new Aurora app beside the old project, its page templates and builder components rebuilt as Aurora layouts and blocks, the fields of each block worked out from its editor buttons, and its pages, block content, structures, files, globals, labels, navigation and redirects brought across from the Netflex API with an import that can run again. Use when asked to port, migrate, convert or move a Netflex site to Aurora."
metadata:
  author: Aurora
---

# Porting a Netflex site to Aurora

A Netflex site is a Laravel app whose models read Netflex's hosted API instead of a database: the pages, their content, the structures' entries, the files and the labels all live in Netflex, and the repository holds only templates, components and models. Read it with `aurora-porting` (what becomes a layout, a block, a setting or a model of the site's own) and `aurora-development` (the API for each). Work on a branch, and commit after each step.

## 0. A new Aurora app beside the old project

Netflex sites run on Laravel 8 to 11 (`composer show netflex/framework laravel/framework` in the old project), and Aurora needs Laravel 13 and PHP 8.4, so the port is a new app, not an install in the old one. Keep the old project checked out next to it, as the reference for every template and component.

```bash
composer create-project shipaurora/aurora new-site   # runs aurora:install; say no to its seeders, the homepage comes from Netflex
```

The starter app comes with Boost, and these skills with it.

- **Read Netflex over HTTP, not with its packages:** they don't install on Laravel 13. A small client class of the site's own, with Laravel's `Http` facade: base URL `NETFLEX_URI` from the old project's `.env` (default `https://api.netflexapp.com/v1/`), Basic auth with `NETFLEX_PUBLIC_KEY` and `NETFLEX_PRIVATE_KEY`. Put those three in the new app's `.env` under the same names, and in `config/services.php`.
- **Only ever GET.** The keys can write to the live site's content. Netflex's own SDK writes behind your back (a label missing in `__()` is posted to the API, reading a file's width saves the file); a client of your own doesn't, and must never `POST`, `PUT` or `DELETE`.
- **Users:** customers' passwords never leave Netflex. If the site has a members' area, ask the user whether its customers come across, as users who set a new password, or not at all.

## 1. Take stock

Netflex keeps half of the site in the API and half in the repository; list both, with counts.

| Netflex | Where | Becomes in Aurora |
| --- | --- | --- |
| Pages and their tree | API `builder/pages/content` (every page with its content), the `template` and `parent_id` of each | Pages, with the same parents and order (`sorting`) and slugs, so the addresses stay |
| A page template | `foundation/templates` (`type: page`, its `alias` and `controller`); view `resources/views/templates/{alias}.blade.php`, around a layout in `resources/views/layouts` | An Aurora layout (`make:aurora-layout`) for each frame that differs |
| A builder component (template `type: builder`) | `resources/views/components/{alias}.blade.php`, sometimes a class in `app/View/Components` | A block (`aurora:make-block`), its fields from the component's editor buttons (section 2) |
| Page-level areas outside the blocks (a "hide breadcrumbs" checkbox, a hero image) | `content('area')` / `<x-editor-button>` in a template or layout | Fields of the layout (`Layouts::schema()`), or a block at the top of those pages |
| A template controller with `$routes` (a list at `/`, a detail page at `/{entry}`) | `app/Http/Controllers/*`, `protected $routes = [...]` | Website routes in `routes/web.php` under the page's address, the list as a block on that page |
| A structure (a news, events or staff directory) | `app/Models/*` with `protected $relationId = {id}`; API `builder/structures/{id}/basic` for its fields | A model of the site's own, its migration and Filament resource, and blocks that pick or list them (`aurora-porting`, section 3) |
| A structure's `matrix` or `editor-blocks` field | renderers in `resources/views/components/matrix/*` and similar | The model's rich text (`editor-blocks`), or blocks on a page of its own (a matrix that's really a page) |
| Global content (`static_content()`, `<x-static-content>`) | API `foundation/globals` | A settings tab (`SettingsTabs::register()`), read with `Setting::get()` |
| Variables (`variable('…')`) | API `foundation/variables` | A setting where editors change it (a phone number), `config/` and `.env` where it's technical (an id, a key) |
| Labels (`__()`, `@lang`, often with source text as the key) | API `foundation/labels/i18n`, `resources/lang/*.json` | `lang/{locale}.json` |
| Navigation (`NavigationData::get()`, `navigation_data()`, `<x-nav>`) | the page tree: `visible_nav`, `visible_subnav`, `nav_title`, folder pages (`template: 'f'`) as roots | Menus in locations (`Menu::location('header')`) |
| Files and images | API `files/file/{id}`, the CDN (`site_cdn_url` variable); `config/media.php` presets | The media library (`AssetLibrary::fromFile()`), alt text kept; presets become image formats (Settings › Media) |
| Redirects | API `foundation/redirects` (`source_url`, `target_url`, `type`) | `Aurora\Models\Redirect` |
| Languages | a page tree per language, its root's `lang` (an `/en/` folder) | Aurora languages (Settings › Languages), the pages' translations |
| Forms | controllers and views of the site's own (Netflex has no forms module) | Aurora forms (Content › Forms) and a form block (`aurora-development`, references/forms.md) |

Commerce (orders, carts), newsletters, signups, customers and Netflex admin extensions have no counterpart in Aurora: list what the site uses of them and ask the user what each becomes before going on. Leave out debug and test routes (`/-/…` and the like) rather than port them.

## 2. Rebuild the design

- **Layouts:** one Aurora layout per Netflex layout, or template that frames a page differently. Start from the published standard layout (`resources/views/vendor/aurora/layouts/standard.blade.php`); move the old `<head>`, header and footer into it; `<x-aurora::blocks :page="$page" />` stands where `<x-blocks area="…"/>` was. A template that's only `<x-blocks>` in a layout needs no layout of its own.
- **Blocks from builder components:** Netflex has no schema for a block: its fields are the editor buttons and inline areas in its view. Make one block per component (`php artisan aurora:make-block "Banner"`) and read its fields off the old view:

| In the component | In the Aurora block |
| --- | --- |
| `<x-inline area="title" is="h2"/>` | `TextInput::make('title')`, an `Editable::text()` (`richText()` where the inline area allowed formatting), drawn with `@editable('title')` |
| `<x-editor-button type="text"/>`, `textarea`, `editor-small`/`editor-large` | `TextInput`, `Textarea`, `RichEditor` (the last as `Editable::richText()`) |
| `type="image"`, `gallery`, `file` | `AssetPicker::make('image')`, `->multiple()` for a gallery, `->anyFile()` for a document |
| `type="checkbox"`, `select` (its `:options`), `color` | `Toggle`, `Select` or `ToggleButtons` with the same options, `Fields::tone()` (a choice of colour, not a colour) |
| `type="link"` | `Fields::link()` |
| `type="entries"` with `:model="App\Models\News"` | `Select::make('news_ids')->multiple()` of the model's records (`aurora-porting`, section 3) |
| `type="contentlist"`, fields named with the item's hash (`'image'.$hash`) | A repeater, its item fields without the hash; or a container block of item blocks |
| An `<x-blocks area="…"/>` inside the component | A container block (`--container`), a slot per area |
| `content('field')` read without an editor button | The same field: it was set by an editor button somewhere, or is a leftover |
| `@if(if_mode('edit'))`, `@mode('edit')`, `current_mode()` | `@editing … @endediting`, `is_editing()` |

  Fields shown only for one choice (a set of fields per layout option) become `->visible(fn (Get $get) => …)` on a single block, not a block each.
- **Netflex Blade to Aurora Blade:** `content('title')` → `$data['title'] ?? null`; `<x-picture :src="$entry->image" preset="hero"/>` and `<x-image>` → `<x-aurora::media :value="…" size="lg" />`; `media_url($image, 'preset')` → `AssetLibrary::url($value, 'md')`; `cdn_url($path)` → the asset's URL; `static_content('footer', 'text')` → `Setting::get('footer_text')`; `variable('phone')` → `Setting::get('phone')` or `config()`; `navigation_data()` and `<x-nav>` → `Menu::location('header')->menuItems`; `current_page()` → `current_page()` (Aurora's, an `Aurora\Models\Page`); `@lang('…')` and `__()` stay. Class components that query in `render()` keep their query, against the site's models.
- **CSS and scripts:** keep the old stylesheet and build (Sass, Bootstrap, Vue islands carry over in Vite); set `config('aurora.website_css')` (`AURORA_WEBSITE_CSS`) to its entry so the visual editor's canvas uses it. Turn the brand colours into `brand` and `accent` (`aurora-development`, "Brand colours"). Image URLs built in JavaScript against the Netflex CDN become the asset URLs the Blade view passes in.
- Give each block `defaults()` that look finished, and a screenshot (`php artisan aurora:block-previews --missing --json`).

## 3. Write the import

An artisan command of the site's own (`php artisan make:command ImportNetflex`), run as often as needed: every run updates what the last one made instead of adding doubles, so the content can be brought across again just before the switch.

- **Stable ids:** make each Aurora record's id from its Netflex id, the same on every run: `Uuid::uuid5(Uuid::NAMESPACE_URL, 'netflex:page:'.$id)->toString()` (`Ramsey\Uuid\Uuid`, `netflex:file:`, `netflex:entry:` for the others), and save with `updateOrCreate(['id' => $id], [...])`.
- **Raw values are strings:** `"1"`/`"0"` for booleans, ids as strings, lists (`entries`, `tags`, `multiselect`, `authgroups`) as comma-separated strings. Cast every one. Check text for HTML entities (`&amp;oslash;`) and decode them.
- **Files:** as they're referenced (a file id and a `path`), download `{site_cdn_protocol}://{site_cdn_url}/{path}` (both are variables, `foundation/variables`) and add it with `AssetLibrary::fromFile($tmp, ['id' => $uuid, 'title' => $file['name'], 'alt' => $file['img_alt'] ?? null, 'folder' => …])`, its details from `files/file/{id}`. Keep a map from Netflex file id and path to the Aurora asset id.
- **Pages:** `builder/pages/content` gives every page, keyed by id, with its `content`. Take the ones that are pages (`template` is a template id; `'f'` is a folder, `'e'` an external link, `'i'` a link to another page, `'d'` a domain, and those go to menus and redirects, not pages). Walk them by `parent_id` and `sorting`, parents first. For each: `title` (`title`, or `name` when it's empty), `slug` from the last part of its `url` (stored with a trailing slash; `index/` is the homepage, `is_homepage`), `parent_id` (a folder's children go to the folder's parent), `status` and `published_at` from `published`, `start` and `stop`, `meta_description` from `description`, the layout from the template's alias, and `blocks` from the content.
- **Content into blocks:** a page's content is one list of items, each with an `area`. A block is an item in the template's block area (the `area` of its `<x-blocks>`), `published` 1, whose `text` is a builder template id (its alias is the block type, `foundation/templates`) and `title` its hash; take them in `sorting` order. The block's fields are the items whose `area` is `{field}_{hash}`, and a value sits in a column set by the field's type:

| Field type | Value |
| --- | --- |
| `text`, `select`, `color`, `integer`, `datetime` | `text` |
| `checkbox` | `text`, `"1"` or `"0"` |
| `tags`, `multiselect`, `checkbox-group` | `text`, comma-separated |
| `editor-small`, `editor-large`, `textarea`, inline areas | `html` |
| `image` | `image` (the path) and `file` (the file id); `name` is the title, `description` the caption |
| `file` | `file` (the path) and `text` (the file id) |
| `gallery` | one item per image: `image`, `file`, `title`, `description`, `name` (a link) |
| `entries` | one item per entry, `text` the entry id, in `sorting` order |
| `link` | `text` the address, `description` the target |
| `contentlist` | one item per row, keyed by its `title` (the row's hash); the row's fields are items named with that hash |

  An item whose template no longer exists was a deleted block: leave it out. Page-level areas (outside any block) go to the layout's fields with `$page->settingsWithLayoutValues()`. Map file ids to asset ids and entry ids to the new records' ids, build the tree and save it with `BlockTreeBuilder::flatten()`.
- **Languages:** each language has a tree of its own, and Netflex doesn't link a page to its other-language version. Ask the user how they pair up (suggest pairs by template and place in the tree, and let them confirm a list); a paired page's title, slug and text go into the first page's translations (`setTranslation('title', $locale, …)`, `block_translations` with the same block ids). A tree that doesn't pair may be better as a site of its own (Settings › Sites).
- **Structures:** the entries of each with `search?relation=entry&relation_id={id}&q=directory_id:{id}&size=10000` (the API returns at most 10,000; page with `page` for more), their fields' types from `builder/structures/{id}/basic`. Into the model, by the stable id: `name` and `url` (the slug, trailing slash removed), `published`, `use_time`, `start` and `stop` (`HasPublishing`), `image` and `file` fields (`{path, file, …}`) to asset ids, `entries` (comma-separated ids) to the related records' ids, `editor-small`/`editor-large` as HTML, `editor-blocks` (Editor.js, `{blocks: [{type, data}]}`) to HTML, `matrix` (a list of `{type, …fields}`) as the renderers drew it. A model with translated fields stored with suffixes (`title_en`, a `Localizable` trait) gets them in its translated columns.
- **Globals:** `foundation/globals` (`[{alias, globals: [{alias, content_type, content}]}]`) into `Setting::put([...])`; `foundation/variables` for the editable ones.
- **Labels:** `foundation/labels/i18n` (`{locale: {key: text}}`) into `lang/{locale}.json`, merged with what the old `resources/lang` held.
- **Navigation:** the menu each nav root drew: its children with `visible_nav` (or `visible_subnav`) in `sorting` order, `nav_title` or `name` as the title; a page links to the page made from it (`MenuItem` `linkable`), an external page (`'e'`) keeps its `url`, an internal link (`'i'`, `url` a page id) links to that page.
- **Addresses:** `foundation/redirects` into `Redirect`s (`type` is the status code); and each old page's `url` and each entry's address under its list page against the new `$page->path()` and route: where they differ, a `Redirect` from the old one. Old addresses end with `/`; check that they reach the page or a redirect, not a 404.

Run it, open the pages in Aurora's visual editor and fix the import or the blocks until each page looks as it did on the Netflex site. Tests: the import runs twice without doubles (fake the API with `Http::fake()`), a page with a block and its fields comes across, an entry's image and related entries are mapped.

## 4. Switch over

1. Run the import one last time, after the editors' last change in Netflex.
2. Check every old address (the old `sitemap.xml` against Aurora's), the forms, the menus and the labels in each language.
3. Point the domain at the Aurora app. Keep the Netflex site and its API keys until nobody needs to compare, then take the keys out of `.env`.
