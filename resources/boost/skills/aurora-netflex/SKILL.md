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
# and remove the starter app's Welcome example (aurora-porting, section 5)
```

The starter app comes with Boost, and these skills with it.

- **Read Netflex over HTTP, not with its packages:** they don't install on Laravel 13. A small client class of the site's own, with Laravel's `Http` facade: base URL `NETFLEX_URI` from the old project's `.env` (default `https://api.netflexapp.com/v1/`), Basic auth with `NETFLEX_PUBLIC_KEY` and `NETFLEX_PRIVATE_KEY`. Put those three in the new app's `.env` under the same names, and in `config/services.php`, with the old project's other keys (storage, mail, Font Awesome's npm token …: `aurora-porting`, "Keys and accounts").
- **Only ever GET.** The keys can write to the live site's content. Netflex's own SDK writes behind your back (a label missing in `__()` is posted to the API, reading a file's width saves the file); a client of your own doesn't, and must never `POST`, `PUT` or `DELETE`.
- **Users:** customers' passwords never leave Netflex. If the site has a members' area, ask the user whether its customers come across, as users who set a new password, or not at all.

## 1. Take stock

Netflex keeps half of the site in the API and half in the repository; list both, with counts.

| Netflex | Where | Becomes in Aurora |
| --- | --- | --- |
| Pages and their tree | API `builder/pages/content` (every page with its content), the `template` and `parent_id` of each | Pages, with the same parents and order (`sorting`) and slugs, so the addresses stay |
| A page template (Netflex's word for a frame pages share; in Aurora that's a layout, and an Aurora page template is something else: a page new pages start from) | `foundation/templates` (`type: page`, its `alias` and `controller`); view `resources/views/templates/{alias}.blade.php`, around a layout in `resources/views/layouts` | An Aurora layout (`make:aurora-layout`) for each frame that differs |
| A builder component (template `type: builder`) | `resources/views/components/{alias}.blade.php`, sometimes a class in `app/View/Components` | A block (`aurora:make-block`), its fields from the component's editor buttons (section 2) |
| Page-level areas outside the blocks (a "hide breadcrumbs" checkbox, a hero image) | `content('area')` / `<x-editor-button>` in a template or layout | Fields of the layout (`Layouts::schema()`), or a block at the top of those pages |
| A template controller with `$routes` (a list at `/`, a detail page at `/{entry}`) | `app/Http/Controllers/*`, `protected $routes = [...]` | Website routes in `routes/web.php` under the page's address, the list as a block on that page |
| A structure (a news, events or staff directory) | `app/Models/*` with `protected $relationId = {id}`; API `builder/structures/{id}/basic` for its fields | A model of the site's own, its migration and Filament resource, and blocks that pick or list them (`aurora-porting`, section 3) |
| A structure's `matrix` or `editor-blocks` field | renderers in `resources/views/components/matrix/*` and similar | The model's rich text (`editor-blocks`), or blocks on a page of its own (a matrix that's really a page) |
| Global content (`static_content()`, `<x-static-content>`) | API `foundation/globals` | Tabs on Settings › Website, one per global group (`SettingsTabs::register(…, page: 'website')`), read with `Setting::get()` |
| Variables, Netflex's "settings" (`variable('…')`), keys and secrets often among them | API `foundation/variables` | `.env`, a setting or a secret, by what each is (section 3, "Variables and keys") |
| Labels (`__()`, `@lang`, often with source text as the key) | API `foundation/labels/i18n`, `resources/lang/*.json` | `lang/{locale}.json` |
| Navigation (`NavigationData::get()`, `navigation_data()`, `<x-nav>`) | the page tree: `visible_nav`, `visible_subnav`, `nav_title`, folder pages (`template: 'f'`) as roots | Menus in locations (`Menu::location('header')`) |
| Files and images | API `files/file/{id}`, the CDN (`site_cdn_url` variable); `config/media.php` presets | The media library (`AssetLibrary::fromFile()`), alt text kept; presets become image formats (Media library › Formats; section 3) |
| Redirects | API `foundation/redirects` (`source_url`, `target_url`, `type`) | `Aurora\Models\Redirect` |
| Languages | a page tree per language, its root's `lang` (an `/en/` folder) | Aurora languages (Settings › Languages), the pages' translations |
| Forms | controllers and views of the site's own (Netflex has no forms module) | Aurora forms (Content › Forms) and a form block (`aurora-development`, references/forms.md) |
| Error pages | `resources/views/errors/*.blade.php`, and any page, global or label their text comes from | Error views of the new app (`aurora-porting`, "Error pages"), the 404 in the site's layout |

Commerce (Netflex Commerce: products, orders, carts), customers, newsletters and signups have no counterpart in Aurora: list what the site uses of each, with counts, and ask the user what it becomes before going on, offering what `aurora-porting` recommends ("Shops, customers and newsletters": Shopify or a shop engine in Aurora's admin, a customer register of the site's own, campaigns sent through Brevo or Mailchimp). Netflex admin extensions: list them and ask too. Leave out debug and test routes (`/-/…` and the like) rather than port them.

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
- **Netflex Blade to Aurora Blade:** `content('title')` → `$data['title'] ?? null`; `<x-picture :src="$entry->image" preset="hero"/>` and `<x-image>` → `<x-aurora::media :value="…" size="hero" />` (the preset's image format); `media_url($image, 'hero')` → `AssetLibrary::url($value, 'hero')`; `cdn_url($path)` → the asset's URL; `static_content('footer', 'text')` → `Setting::get('footer_text')`; `variable('phone')` → `Setting::get('phone')` or `config()`; `navigation_data()` and `<x-nav>` → `Menu::location('header')->menuItems`; `current_page()` → `current_page()` (Aurora's, an `Aurora\Models\Page`); `@lang('…')` and `__()` stay. Class components that query in `render()` keep their query, against the site's models.
- **CSS and scripts:** keep the old stylesheet and build (Sass, Bootstrap, Vue islands carry over in Vite); keep its icon set too (Font Awesome, common on Netflex sites, as Blade Font Awesome: `aurora-porting`, "Icons"); set `config('aurora.website_css')` (`AURORA_WEBSITE_CSS`) to its entry so the visual editor's canvas uses it. Turn the brand colours into `brand` and `accent` (`aurora-development`, "Brand colours"). Image URLs built in JavaScript against the Netflex CDN become the asset URLs the Blade view passes in.
- Give each block `defaults()` that look finished, and a screenshot (`php artisan aurora:block-previews --missing --json`).

## 3. Write the import

An artisan command of the site's own (`php artisan make:command ImportNetflex`), run as often as needed: every run updates what the last one made instead of adding doubles, so the content can be brought across again just before the switch.

- **Stable ids:** make each Aurora record's id from its Netflex id, the same on every run: `Uuid::uuid5(Uuid::NAMESPACE_URL, 'netflex:page:'.$id)->toString()` (`Ramsey\Uuid\Uuid`, `netflex:file:`, `netflex:entry:` for the others), and save with `updateOrCreate(['id' => $id], [...])`.
- **Raw values are strings:** `"1"`/`"0"` for booleans, ids as strings, lists (`entries`, `tags`, `multiselect`, `authgroups`) as comma-separated strings. Cast every one. Check text for HTML entities (`&amp;oslash;`) and decode them.
- **Image formats before the files:** the presets in the old project's `config/media.php` (`'presets' => ['hero' => ['mode' => MODE_FIT, 'size' => '1200x828', 'resolutions' => ['1x', '2x'], 'breakpoints' => ['sm' => [...]]], 'default' => Preset::ORIGINAL, …]`) become image formats made with `ImageFormats::put()` (`aurora-porting`, "Image formats"), the preset's name as the handle. A `size` of `'1200x600'` is a width and a height, `'1200x'` a width only. The modes (Netflex's CDN codes in brackets) don't all do what their names say:

  | Mode | What Netflex makes of the size | Aurora format |
  | --- | --- | --- |
  | `MODE_FIT` (`rc`), the most used | fills it exactly, cropping the middle | width and height |
  | `MODE_FIT_DIR` (`rcf`) | the same, cropped towards a `direction` | width and height; the direction is per preset in Netflex, per image (`focal_point`) in Aurora |
  | `MODE_EXACT` (`e`) | squeezes the whole image to it, distorting it | width and height (Aurora crops instead: say so) |
  | `MODE_FILL` (`fill`) | fits inside it, padded with the preset's `fill` colour | width only; padding, if it mattered, in CSS |
  | `MODE_LANDSCAPE` (`l`), `MODE_AUTO` (`a`) | its width (`a` fits inside the box) | width only |
  | `MODE_PORTRAIT` (`p`) | its height, any width | width only, worked out from the height and the images' usual shape: Aurora sizes by width |
  | `MODE_ORIGINAL` (`o`), `Preset::ORIGINAL` | the file as uploaded | none: `size="original"` |
  | `MODE_CROP` (`c`) | (the CDN answered it with an error) | check what the old site shows |

  `resolutions` (`2x`, `3x`; the default preset has all three) and a preset's `breakpoints`, each with a size and mode of its own, are what Aurora's responsive widths do with `srcset`: make sure one responsive format is at least twice the largest size, rather than a format per resolution or breakpoint, unless a breakpoint crops to a different shape (then that's a format of its own). In the views, `<x-picture preset="hero">`, `<x-image preset="hero">` and `media_url($image, 'hero')` then take `size="hero"` (`AssetLibrary::url($value, 'hero')`); a `media_url($image, '400x400', MODE_FIT)` with a size of its own gets the nearest format, or one of its own.
- **Files:** as they're referenced (a file id and a `path`), download `{site_cdn_protocol}://{site_cdn_url}/{path}` (both are variables, `foundation/variables`) and add it with `AssetLibrary::fromFile($tmp, ['id' => $uuid, 'title' => $file['name'], 'alt' => $file['img_alt'] ?? null, 'folder' => …])`, its details from `files/file/{id}`. Keep a map from Netflex file id and path to the Aurora asset id.
- **Pages:** `builder/pages/content` gives every page, keyed by id, with its `content`. Take the ones that are pages (`template` is a template id; `'f'` is a folder, `'e'` an external link, `'i'` a link to another page, `'d'` a domain, and those go to menus and redirects, not pages). Walk them by `parent_id` and `sorting`, parents first. For each: `title` (`title`, or `name` when it's empty), `slug` from the last part of its `url` (stored with a trailing slash; `index/` is the homepage, `is_homepage`), `parent_id` from the `url` itself, so the address stays the same (`aurora-porting`, "Keep the addresses"): the parent is the page whose `url` is this one's without its last part. A folder that is part of the addresses (`services/` above `services/design/`) becomes a page with that slug (ask the user: an overview page or a draft); a folder that isn't in them (a menu group) adds no page, and its children hang from the page above it, `status` and `published_at` from `published`, `start` and `stop`, `meta_description` from `description`, the layout from the template's alias, and `blocks` from the content.
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
- **Globals:** `foundation/globals` (`[{alias, globals: [{alias, content_type, content}]}]`) into `Setting::put([...])`.
- **Variables and keys:** Netflex keeps the site's settings, and often its keys, in the API rather than the project: `GET foundation/variables` (what the framework's `variable()` reads; there is no `foundation/settings`) gives `[{alias, value, format}]`, `format` `boolean` meaning `"1"`/`"0"` and `json` a JSON string. With the Netflex keys in `.env`, read them all, list the aliases (never the values) with where each goes, and ask the user about any that isn't clear. By what each is:
  - **A secret the server uses** (a captcha's secret, an SMTP password, an API token the code calls a service with: `*_secret`, `*_token`, `*_password`, `*_key` that isn't public): `.env` under a name of the new code's, read through `config/services.php`, and its name without the value in `.env.example`. Written into `.env` once, by you, while setting up; never into the import or a commit.
  - **A secret editors should be able to change** (a newsletter provider's API key): a `SecretInput` on the service's tab on Settings › Integrations, stored by the import with `SettingSecrets::put()`.
  - **A public identifier** (a captcha's site key, an analytics or map id): a setting on the service's Integrations tab, or `config/` and `.env` when it differs per server rather than per site.
  - **Something editors change** (a sender's name and address, who gets notifications, a phone number, a switch such as `show_newsletter_signup`): a setting on the tab it belongs to (Website, or the service's on Integrations), by the import with `Setting::put()`, decoded by its `format`.
  - **Netflex's own** (`netflex_api`, `site_cdn_url`, `site_cdn_protocol` and the like): only the import needs them; they don't come across.
- **Labels:** `foundation/labels/i18n` (`{locale: {key: text}}`) into `lang/{locale}.json`, merged with what the old `resources/lang` held.
- **Navigation:** the menu each nav root drew: its children with `visible_nav` (or `visible_subnav`) in `sorting` order, `nav_title` or `name` as the title; a page links to the page made from it (`MenuItem` `linkable`), an external page (`'e'`) keeps its `url`, an internal link (`'i'`, `url` a page id) links to that page.
- **Addresses:** `foundation/redirects` into `Redirect`s (`type` is the status code); and each old page's `url` and each entry's address under its list page against the new `$page->path()` and route: where they differ, a `Redirect` from the old one. Old addresses end with `/`; check that they reach the page or a redirect, not a 404.

Run it, open the pages in Aurora's visual editor and fix the import or the blocks until each page looks as it did on the Netflex site. Tests: the import runs twice without doubles (fake the API with `Http::fake()`), a page with a block and its fields comes across, an entry's image and related entries are mapped.

## 4. Switch over

1. Run the import one last time, after the editors' last change in Netflex.
2. Compare the new site with the Netflex one, page by page, screenshots and HTML (`aurora-porting`, section 7), and adjust until the user is satisfied. Check every old address (the old `sitemap.xml` against Aurora's), the forms, the menus and the labels in each language.
3. Point the domain at the Aurora app. Keep the Netflex site and its API keys until nobody needs to compare, then take the keys out of `.env`.
