---
name: aurora-statamic
description: "Port a Statamic website to Aurora CMS in the same Laravel app: install Aurora beside Statamic, rebuild its blueprints, Bard and Replicator sets and Antlers templates as Aurora blocks and layouts, bring its entries, page trees, navigation, globals, assets, forms and old addresses across with an import that can run again, then switch the website over and remove Statamic. Use when asked to port, migrate, convert or move a Statamic project to Aurora."
metadata:
  author: Aurora
---

# Porting a Statamic site to Aurora

Statamic and Aurora are both Laravel packages, so the port happens in the Statamic project itself: Aurora is installed beside Statamic, the website is rebuilt on Aurora while Statamic still serves it, and Statamic goes once everything has come across. Read it with `aurora-porting` (what becomes a layout, a block, a setting or a model of the site's own) and `aurora-development` (the API for each). Work on a branch, and commit after each step.

## 0. Install Aurora beside Statamic

Aurora needs Laravel 13 and PHP 8.4, so Statamic 6 (`composer show statamic/cms laravel/framework`); on Statamic 5 or Laravel 12, upgrade first.

```bash
composer require shipaurora/cms
php artisan aurora:install          # a database (SQLite will do), the admin account
php artisan boost:install           # choose shipaurora/cms when asked: it installs these skills
```

- **Two admins side by side:** Statamic's at `/cp`, Aurora's at `/admin`. Aurora's pages are fallback routes, so Statamic's control panel and its website keep working: while `statamic.routes.enabled` is true (`config/statamic/routes.php`), Statamic serves the website, and the Aurora pages are seen in Aurora's visual editor and previews.
- **Users:** with Statamic's default `file` users (`config/statamic/users.php`, `'repository' => 'file'`), nothing clashes. With `eloquent` users, stop and tell the user: Aurora's install gives the `users` table UUID keys, which Statamic's own user tables don't expect. Port on a copy, or move Statamic to file users first.
- **Read Statamic through its own API** while it's installed (`Statamic\Facades\Entry`, `Collection`, `Nav`, `GlobalSet`, `Asset`, `Form`, `Site`, `User`), not by parsing its files: it knows the localizations, trees and sets, and works the same with the flat-file and the database drivers.

## 1. Take stock

List what the site has, with counts: `php please` is Statamic's CLI, and its files are the map.

| Statamic | Where | Becomes in Aurora |
| --- | --- | --- |
| A structured collection of pages (`pages`), with its tree | `content/collections/pages/*.md`, `content/trees/collections/pages.yaml` | Pages, with the same parents and order (`parent_id`) and slugs, so the addresses stay |
| A dated collection (blog, news) | `content/collections/blog/*.md` | Pages under a page of their own (`/blog/…`), as Aurora's WordPress import does with posts; a block lists them (`Page::query()->live()`) |
| A collection of records with fields (team, products, offices, jobs) | `content/collections/team/*.md` | A model of the site's own, its migration and Filament resource, and blocks that pick or list them (`aurora-porting`, section 3) |
| A blueprint or fieldset | `resources/blueprints/collections/…`, `resources/fieldsets/…` | A page's fields are its blocks; a record's are the model's columns and resource form |
| A Bard field's sets, a Replicator's sets | the field's `sets:` in the blueprint | One block per set, its fields as the block's `schema()` (the set's text fields as `editables()`) |
| Bard text between sets | — | A rich text block (`Editable::richText()`) |
| Grid, Group | — | A repeater, or a group of fields, in the block |
| Templates and layouts (Antlers or Blade) | `resources/views/*.antlers.html` | Aurora layouts (`make:aurora-layout`) and block views, in Blade |
| Partials | `resources/views/partials/` | Blade components used by the layouts and blocks |
| Globals | `content/globals/` | A settings tab (`SettingsTabs::register()`), read with `Setting::get()` |
| Navigation | `content/navigation/*.yaml`, `content/trees/navigation/` | Menus in locations (`Menu::location('header')`) |
| Taxonomies | `content/taxonomies/` | A model (with its own pages if terms have them), or a `TagsInput`/`Select` on the records |
| Assets and their alt text | `content/assets/*.yaml` (containers), the files, `.meta/*.yaml` | The media library (`AssetLibrary::fromFile()`), alt text and folders kept |
| Forms | `resources/forms/*.yaml`, `resources/blueprints/forms/` | Aurora forms (Content › Forms) and a form block (`aurora-development`, references/forms.md) |
| Sites (multi-site, languages) | `resources/sites.yaml` | Aurora sites and languages (Settings › Languages); localized entries become the pages' translations |
| Redirects of an addon, changed addresses | — | `Aurora\Models\Redirect` |

## 2. Rebuild the design

- **Layouts:** one Aurora layout per Statamic layout or template that frames a page differently. Start from the published standard layout (`resources/views/vendor/aurora/layouts/standard.blade.php`); move the old head, header and footer into it; `<x-aurora::blocks :page="$page" />` stands where `{{ template_content }}` (or the content field's loop) was.
- **Blocks:** `php artisan aurora:make-block "Hero"` for each set and section. Port the set's partial (`resources/views/sets/hero.antlers.html` or wherever the template draws it) into the block view, replacing each value with its field.
- **Antlers to Blade:** `{{ title }}` → `{{ plain_text($data['title'] ?? '') }}` (or `inline_text()`); `{{ content }}` of Markdown or Bard → `{!! rich_text($data['body'] ?? '') !!}`; `{{ if x }}…{{ /if }}` → `@if`/`@shows('x', $data['x'] ?? null)`; `{{ items }}…{{ /items }}` → `@foreach (($data['items'] ?? []) as $item)`; an image `{{ image }}<img src="{{ url }}" alt="{{ alt }}">{{ /image }}` → `<x-aurora::media :value="$data['image'] ?? null" size="md" />`; `{{ glide:image width="800" }}` → a size of the media library (`AssetLibrary::url($value, 'md')`); `{{ partial:card }}` → `<x-card …/>`; `{{ nav:main }}` → `Menu::location('header')`; `{{ global_handle:field }}` → `Setting::get('field')`; `{{ trans:key }}` → `__('key')`.
- **CSS:** keep the old stylesheet and build (Vite and Tailwind usually carry over); turn the brand colours into `brand` and `accent` (`aurora-development`, "Brand colours").
- Give each block `defaults()` that look finished, and a screenshot (`php artisan aurora:block-previews --missing --json`).

## 3. Write the import

An artisan command of the site's own (`php artisan make:command ImportStatamic`), run as often as needed: every run updates what the last one made instead of adding doubles, so the content can be brought across again just before the switch.

- **Stable ids:** make each Aurora page's and asset's id from its Statamic id, the same on every run: `Ramsey\Uuid\Uuid::uuid5(Uuid::NAMESPACE_URL, 'statamic:'.$entry->id())->toString()` (Ramsey's UUID comes with Laravel), and save with `updateOrCreate(['id' => $id], [...])`.
- **Assets first:** `Asset::all()` (or per container); for each, `AssetLibrary::fromFile($asset->resolvedPath(), ['id' => $uuid, 'title' => $asset->get('title') ?? $asset->title(), 'alt' => $asset->get('alt'), 'folder' => dirname($asset->path())])`. Keep a map from the Statamic id (`assets::img/team.jpg`, `$asset->id()`) to the Aurora asset id; fields hold the former.
- **Pages:** walk the collection's tree in order, `Collection::find('pages')->structure()->in($site)->tree()` (each item `['entry' => id, 'children' => [...]]`), so parents exist before their children. For each entry: `title`, `slug` (`$entry->slug()`; the homepage gets `is_homepage`), `parent_id`, `status` and `published_at` from `$entry->published()` and `$entry->date()`, SEO fields (an SEO addon's `seo_title`, `seo_description`), and `blocks` from the content.
- **Content into blocks:** for a Bard field with sets, `$entry->augmentedValue('content')->value()` gives the content in order: text as HTML (`['type' => 'text', 'text' => …]`) and each set with its values. Text becomes a rich text block, each set the block made for it, its values mapped to the block's fields, asset references (`assets::…`) to Aurora asset ids. Use the raw values (`$entry->get('content')`) where augmented ones turn ids into objects. A Replicator is the same list without text. Build the tree and save it with `BlockTreeBuilder::flatten()`.
- **Languages:** for each other site, `$entry->in($siteHandle)` is the localization (or null); its title, slug and text go into the page's translations (`setTranslation('title', $locale, …)`, `block_translations` for the blocks' text), the same block ids as the original's.
- **Records:** each entry of a record collection into its model, by the stable id.
- **Globals:** `GlobalSet::find('settings')->inDefaultSite()->data()` into `Setting::put([...])` (per site where a value is per site).
- **Navigation:** `Nav::find('main')->in($site)->tree()`: items with `entry` link to the page made from it (`MenuItem` `linkable`), items with `url` keep the address, `title` and `children` as they are; into the menu in the location the layout draws.
- **Forms:** each form's fields (`$form->blueprint()->fields()->all()`) into an Aurora form; submissions can stay in Statamic's files, or come across too.
- **Addresses:** compare each entry's `$entry->uri()` with the new page's `$page->path()`; where they differ, a `Redirect` from the old one.

Run it, open the pages in Aurora's visual editor and fix the import or the blocks until each page looks as it did. Tests: the import runs twice without doubles, a page and its translation come across, a set becomes its block.

## 4. Switch over

1. Run the import one last time, after the editors' last change in Statamic.
2. Set `'enabled' => false` in `config/statamic/routes.php`: Aurora serves the website from then on (`/cp` still works, for comparing).
3. Check every old address (the sitemap of the old site against Aurora's `sitemap.xml`), the forms and the menus.
4. When nobody needs it any more: `composer remove statamic/cms`, then remove what was Statamic's (`content/`, `resources/blueprints`, `resources/fieldsets`, `resources/forms`, `resources/sites.yaml`, `users/`, `config/statamic/`, the `.antlers.html` views, Statamic's entries in `routes/` and `app/`), after a last look that nothing still uses it.
