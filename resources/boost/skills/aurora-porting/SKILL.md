---
name: aurora-porting
description: "Port an existing website (static HTML, another CMS such as WordPress, or a Laravel app) to Aurora CMS: decide what becomes a page layout, a block, a settings tab or a model of the site's own with its Filament resource, then rebuild it and bring the content, files, menus and old addresses across. Covers what to offer for a shop (Shopify, Vanilo, Lunar), a customer register and newsletters (Brevo or Mailchimp), which Aurora doesn't have."
metadata:
  author: Aurora
---

# Porting a website to Aurora

Use this together with the `aurora-development` skill, which has the API for each piece named here. Porting a Statamic site? Use the `aurora-statamic` skill, which does it in the same app and maps Statamic's collections, sets, globals and navigation onto what's here. A Netflex site? Use `aurora-netflex`, which rebuilds it in a new app and reads its content from the Netflex API. The aim is a site editors can run themselves: everything they'd want to change is a page, a block, a setting, a menu or a record in the admin, and nothing they'd want to change is left in a template.

## 1. Take stock before writing code

List, from the old site's templates and its data:

- **Page templates**: the frames pages share (header, footer, sidebar), and how they differ (a landing page without the menu, a docs page with a side menu).
- **Sections**: the parts pages are put together from (hero, feature grid, FAQ, testimonial, call to action, price table), with every variation in use.
- **Records**: data with fields of its own that appears in more than one place, or in lists (staff, products, events, offices, job openings, case studies).
- **Global bits**: header and footer text, social links, a cookie notice, contact details.
- **Navigation**: each menu and where it's shown.
- **Forms**: their fields and who gets the submissions.
- **Error pages**: the old site's own 404, server error and maintenance pages (and 403, 410 or 419 if it has them), with their text in each language.
- **Files**: images and documents, with their alt text and credits, and the image sizes the templates ask for (presets, thumbnails, resize parameters).
- **Keys and accounts**: the services the old site uses with a key, token or password (storage such as AWS S3 or Hetzner's object storage, mail, Font Awesome Pro's npm token, maps, a captcha, analytics, a newsletter or payment provider), from its `.env`, `config/`, `.npmrc` and deploy settings.
- **Icons**: the icon set the templates use (Font Awesome, another icon font, a sprite, inline SVGs), its version and whether it's Pro, and the icons editors chose in the content.
- **Addresses**: every URL that has visitors or links pointing at it, how they nest (`/services/web/design`), and the languages.
- **A shop, customers, newsletters**: products and orders, a customer or member register, newsletter sign-ups and campaigns. Aurora has none of them: see section 4 before going on.

## 2. Decide what each one becomes

| On the old site | In Aurora |
| --- | --- |
| A page template | A page layout: the published standard layout made the site's own, or `php artisan make:aurora-layout "Landing"` for each other frame. Settings that change one frame (a background image, a dark header) are `Layouts::schema()` fields, not separate layouts. |
| A section editors place on pages | A block (`php artisan aurora:make-block`). Variations of one section are one block with a choice (`ToggleButtons` for layout, `Fields::tone()` for colour), not one block each. |
| A repeated item inside a section (a card, a stat) | Either a repeater in the section's block (fixed shape, edited as a list) or a container block holding item blocks (`--container`; editors can mix and reorder). |
| Header, footer, other bits every page shows | Blade components in the layout. Their text and links come from settings, as tabs on Settings › Website, one per section (`SettingsTabs::register(…, page: 'website')`; a service's on Integrations: `aurora-development`, "The website's settings"), their links from menus. |
| A menu | A menu in a location: `Menu::location('header')` in the layout, a location of the site's own for others. |
| Records with their own fields | A model of the site's own, with a migration and a Filament resource, and blocks that pick or list them (below). |
| A page of text | A page record with blocks, created by a seeder or an import command. |
| A contact form | A form under Content › Forms, shown by a form block. |
| An old address | A redirect (`Aurora\Models\Redirect`), or the same slug. |
| An icon set (Font Awesome, an icon font, a sprite) | The same set, as Blade Icons ("Icons", below): never Aurora's Phosphor or Heroicons instead. |
| Image sizes (presets, resize parameters) | Image formats (Media library › Formats), made in the import ("Image formats", below). |
| An error page (404, 500, maintenance) | A Laravel error view of the site's own, `resources/views/errors/{code}.blade.php` ("Error pages", below). |

**Block or model?** A block is how part of a page looks; a model is a thing that exists on its own. If the same facts (a person's name, title, phone and photo) would be typed into blocks on several pages, or a list of them is shown somewhere, they're a model, and the blocks only point at records. If it's only ever on one page, with no fields beyond what it shows, it's a block.

Port the old markup into the block views as it is, then replace each hard-coded text, image and link with a field. Keep the old site's CSS classes in the site's stylesheet (`resources/css/app.css`): the visual editor draws blocks with it. Turn the old site's brand colours into the `brand` and `accent` theme colours (eleven shades each, `--color-brand-50` to `--color-brand-950`, as defaults in `@theme`), and paint the blocks with `brand-*` and `accent-*` instead of its fixed colours, so editors can change them under Settings › Website › Branding ("Brand colours" in `aurora-development`).

**Error pages.** Aurora draws none of its own: a missing page is Laravel's `abort(404)`, shown with the app's `resources/views/errors/404.blade.php` if it has one, else Laravel's plain page. Port each error page the old site has as such a view, and add a 404 even if it had none:

- **404, 403, 410, 419** go inside the site's layout, so the header, menus and footer are there and the visitor can find their way on: `<x-aurora::layout :title="__('Page not found')" noindex>…</x-aurora::layout>` around the old page's content. Aurora's page routes take every address, so the site and its language are known by then: a missing `/en/…` address gets the English page. Its text goes through `__()`, with lines in `lang/{locale}.json`; when editors changed the old page's text in the old CMS, make it fields on a tab on Settings › Website instead (`SettingsTabs::register(…, page: 'website')`, read with `Setting::get()`).
- **500 and 503** must work when the database or the app is what broke: plain HTML with the CSS inline, no layout, no `Setting::get()`, no menus, no Vite. `503.blade.php` is also what `php artisan down` shows (`--render="errors::503"` to prerender it).
- Check with `APP_DEBUG=false` (with debug on, Laravel shows its own error screen), and add a test: a missing address answers 404 with the site's layout, in each language.

**Image formats.** Every size the old site makes of its images (a CMS's presets, an image server's resize parameters in the templates) becomes an Aurora image format (`Aurora\Models\ImageFormat`, under Media library › Formats): a `handle`, a `width`, a `height` only when it crops (around the image's focal point), the `file_format` (`webp`, `avif`, `jpg` or `png`), the `quality`, and `is_responsive` (whether it goes in `srcset`). Aurora comes with responsive `sm` (640), `md` (1280) and `lg` (1920); `thumb`, `og` and `original` are taken.

- Make them in the import with `Aurora\Support\ImageFormats::put(null, ['name' => 'Hero', 'handle' => 'hero', 'width' => 1920, 'height' => 800, 'file_format' => 'webp', 'quality' => 80, 'is_responsive' => false])` (it also makes the new size of every image already there), **before** the files, so each image is processed once. Skip a format whose handle exists, so the import can run again.
- Keep the old preset's name as the handle when the views ask for it by name (`<x-aurora::media :value="…" size="hero" />`, `AssetLibrary::url($value, 'hero')`). Presets that only set a width, or differ a little, are usually covered by the responsive widths: merge them rather than copy each.
- A preset that fits an image inside a box without cropping is a width only: Aurora's formats don't fit to a height. Say so when that changes how an image looks (a portrait image in a landscape box).
- A focal point (where crops centre) is the asset's `focal_point`, `['x' => 0.4, 'y' => 0.3]` as fractions of the width and height. `fromFile()` doesn't take it: set it on the asset afterwards and call `ImageFormats::regenerateImage($asset)`.

**Icons.** Keep the old site's icon set. Aurora's admin uses Phosphor and `Fields::icon()` offers Heroicons, but swapping the site's own icons for either changes its design, which a port mustn't do.

- **Font Awesome:** `composer require owenvoke/blade-fontawesome` (Blade Icons sets `fontawesome-solid`, `fontawesome-regular` and `fontawesome-brands`, prefixes `fas`, `far`, `fab`). A site on Font Awesome Pro or a kit gets those icons with `npm i @fortawesome/fontawesome-pro` (the npm token in `.npmrc`) and `php artisan blade-fontawesome:sync-icons --pro`, or `--kit=KIT_CODE` (sets `fontawesome-light`, `fontawesome-duotone` and the other styles it uses); copy the token from the old project ("Keys and accounts", below), and ask the user only if it isn't there. In the views, `<i class="fa-solid fa-house"></i>` becomes `<x-fas-house class="…" />` or `@svg('fas-house', '…')`, keeping the old classes so the site's CSS still sizes and colours them; an SVG needs `height: 1em; width: auto; fill: currentColor` in the stylesheet where the font sized itself. Blade Font Awesome has Font Awesome 6 and later: a site on version 5 (`fa fa-times`, `fas fa-home`) needs the renamed icons mapped (`fa-times` is `xmark`, `fa-home` is `house`); check each name draws (`svg('fas-xmark')`) rather than guess.
- **Icons editors choose** (an icon field in a section): a picker of the same set, `IconPickerField::make('icon')->allowedSets(['fontawesome-solid', 'fontawesome-brands'])` (`Wallacemartinss\FilamentIconPicker\Forms\Components\IconPickerField`, the picker `Fields::icon()` uses), stored as `fas-house` and drawn with `svg(SiteIcon::name($data['icon'] ?? null) ?? 'fas-circle', '…')`. The import turns the old stored names (`fa-solid fa-house`, `fa fa-home`) into that form.
- **Another icon font or a sprite:** its Blade Icons package if there is one, else the site's own SVGs as a Blade Icons set (`config/blade-icons.php`: a set with the folder and a prefix of its own). Keep the font as it was only when neither works.

**Keys and accounts.** Carry the old site's credentials over rather than asking the user to find them again: copy each from the old project (`.env`, `config/*.php`, `.npmrc`, `auth.json`), or from where the old platform keeps them (on Netflex, many are variables in its API: `aurora-netflex`, "Variables and keys"), into the new site, under the names the new code reads. Each goes in `.env` or in Settings by what it is:

- Server-level ones (storage: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, and for Hetzner or another S3-compatible store `AWS_ENDPOINT` and `AWS_DEFAULT_REGION`; mail; the queue) go in the new `.env`, and their names, without values, in `.env.example`.
- A package registry token (Font Awesome Pro, a private Composer repository) stays out of the repository: `.npmrc` gets `@fortawesome:registry=https://npm.fontawesome.com/` and `//npm.fontawesome.com/:_authToken=${FONTAWESOME_NPM_AUTH_TOKEN}`, and the token goes in the environment npm runs in (the shell, the deploy's and CI's environment variables: npm doesn't read Laravel's `.env`); Composer's go in `auth.json`, never committed. If the old project committed its token, say so: it should be rotated.
- Keys editors manage (a newsletter provider, a booking system) go in a `SecretInput` on the service's Settings tab (`aurora-development`, "The website's settings"); set them once from the old values, in a seeder that reads them from `.env` or by hand in the admin.
- Never print a secret in your answers, a log or a commit, and never commit `.env`. Tell the user which keys came across and which are missing, and that keys shared by the old and new site keep working for both until one is retired (rotate them after the switch if the old site was handled by others).

## 3. A model of the site's own: employees

An old "Our team" page with a card per person, and the same person shown again on the contact page and on articles they wrote. In Aurora that's an `Employee` model, managed under its own menu in the admin, and a "Team member" block that picks one.

**The migration.** Translated text is JSON, one value per language. A media library image is a JSON column too: it holds the asset's id, or the id with the editor's crop.

```php
Schema::create('employees', function (Blueprint $table) {
    $table->id();
    $table->foreignUuid('site_id')->constrained()->cascadeOnDelete(); // leave out if staff are the same on every site
    $table->string('name');
    $table->json('job_title');
    $table->json('bio')->nullable();
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->json('photo')->nullable();
    $table->unsignedInteger('sort')->default(0);
    $table->timestamps();
});
```

**The model.** `BelongsToSite` keeps each site's staff apart (queries see the current site's, new records join it). `HasTranslations` reads a translated field in the language being shown. Tell the media library where its files are used, so a used file isn't deleted unawares.

```php
namespace App\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Support\AssetLibrary;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Employee extends Model
{
    use BelongsToSite, HasTranslations;

    protected $guarded = [];

    public array $translatable = ['job_title', 'bio'];

    protected function casts(): array
    {
        return ['photo' => 'json'];
    }

    protected static function booted(): void
    {
        static::saved(fn (Employee $employee) => AssetLibrary::syncUsages($employee, ['photo' => $employee->photo]));
        static::deleted(fn (Employee $employee) => AssetLibrary::syncUsages($employee, []));
    }
}
```

**The resource.** A Filament resource in `app/Filament/Resources/Employees` (`php artisan make:filament-resource Employee`), registered in a service provider's `register()` method (not `boot()`: the admin panel is built before the app's `boot()` runs) with `AdminPanel::discover(app_path('Filament/Resources'), 'App\Filament\Resources', perSite: true)` (`perSite` for a model with `BelongsToSite`) and given a group of its own, `AdminPanel::navigationGroup('Team', fn (): string => __('Team'))`, set as the resource's `$navigationGroup`. In its form, Aurora's fields keep it like the rest of the admin:

```php
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Forms\Components\TranslatedField;
use Aurora\Support\ContentLocale;

$languages = array_keys(ContentLocale::all());

return $schema->components([
    TextInput::make('name')->label(__('Name'))->required(),
    TranslatedField::make(TextInput::make('job_title')->label(__('Job title'))->required(), $languages),
    TranslatedField::make(Textarea::make('bio')->label(__('About'))->rows(4), $languages),
    TextInput::make('email')->label(__('Email'))->email(),
    TextInput::make('phone')->label(__('Phone'))->tel(),
    AssetPicker::make('photo')->label(__('Photo')),
]);
```

**Its permissions and policy.** Each model of the site's own gets permissions of its own and a policy that checks them, so roles control it as finely as Aurora's pages: `Permissions::register('Team', ['view-any Employee' => …, 'create Employee' => …, 'update Employee' => …, 'delete Employee' => …], 'phosphor-users', grantTo: ['Editor'])` and an `EmployeePolicy` with `return $user->can('update Employee');` and the like ("Permissions and policies" in `aurora-development`). Ask the user which built-in roles get them.

**AI tools: ask.** Ask the user whether AI apps and the in-panel assistant should work with the model too (list, read, create, update), and which. If so, tools of the site's own registered with `McpTools::register()`, each authorizing with the model's policy ("AI tools (MCP)" in `aurora-development`). Records editors manage often (events, job openings, products) are the usual yes.

Give the table `->reorderable('sort')` and `->defaultSort('sort')`, so editors put the team in order. Labels go through `__()` with lines in `lang/{locale}.json`.

**The block** points at a record; it doesn't copy it. Changing a person's title in the admin changes it on every page.

```php
class TeamMemberBlock extends Block
{
    public static function type(): string { return 'team-member'; }
    public static function label(): string { return __('Team member'); }
    public static function description(): string { return __('A person from the team, with photo and contact details.'); }
    public static function icon(): ?string { return 'phosphor-user'; }

    public static function schema(): array
    {
        return [
            Select::make('employee_id')->label(__('Person'))
                ->options(fn (): array => Employee::query()->orderBy('sort')->pluck('name', 'id')->all())
                ->searchable()->required(),
            Toggle::make('show_contact')->label(__('Show email and phone'))->default(true),
        ];
    }

    public static function defaults(): array
    {
        return ['employee_id' => Employee::query()->orderBy('sort')->value('id'), 'show_contact' => true];
    }

    public static function aiHints(): ?string
    {
        return 'Shows one person from Team in the admin. Choose employee_id from its options by name; never invent people. For the whole team, use the team block.';
    }
}
```

```blade
@props(['data' => []])

@php($employee = \App\Models\Employee::query()->find($data['employee_id'] ?? null))

@if ($employee)
    <figure class="team-member">
        <x-aurora::media :value="$employee->photo" size="sm" />
        <figcaption>
            <p class="name">{{ $employee->name }}</p>
            <p class="title">{{ $employee->job_title }}</p>
            @if (($data['show_contact'] ?? true) && $employee->email)
                <a href="mailto:{{ $employee->email }}">{{ $employee->email }}</a>
            @endif
        </figcaption>
    </figure>
@else
    @editing <p class="placeholder">{{ __('Choose a person in the panel →') }}</p> @endediting
@endif
```

A record that's deleted makes its block draw nothing on the site and a hint in the editor; never an error. A **list** block ("Team") is the same with `Select::make('employee_ids')->multiple()`, or a toggle for "everyone, in order", drawing `Employee::query()->orderBy('sort')->get()`.

**A page per record** (`/team/ada-lovelace`), if the old site had one: a website route in `routes/web.php` with the `RestrictWebsiteAccess` middleware, a view in `<x-aurora::layout :title="$employee->name">`, the same route under each language's prefix, and its addresses in `Sitemap::add(fn () => Employee::query()->get()->map(fn ($e) => route('team.show', $e)))`. Give the model a `slug` column for it.

The same pattern fits products, events, offices and jobs. Use `HasPublishing` (and `Publishing::section()` in the resource) for records that go live and end on dates, as events and job openings do.

## 4. Shops, customers and newsletters

Aurora has no shop, no customer register and no newsletters. When the old site has any of them, list what it uses of each, with counts, and **ask the user what it becomes** before building anything. Offer:

| On the old site | Recommend | Read |
| --- | --- | --- |
| A shop (products, carts, orders, payments) | Shopify, headless: Shopify keeps products, stock, checkout and orders, and the shop is served on the Aurora website (product pages, collections, cart; only the checkout is Shopify's), with a thin product and order layer in Aurora's admin and blocks for the shop (product cards, grids, collections) to offer. Not a Shopify-hosted storefront. In Aurora's own admin instead: Vanilo now, Lunar or Shopper once they install beside Aurora (check first). Or keep the old shop, or a small shop of the site's own for a few products | [references/shop.md](references/shop.md) |
| Customers, members, contacts | A `Customer` model of the site's own, shaped by the old data, with its migration, Filament resource and import | [references/customers.md](references/customers.md) |
| Newsletters | Campaigns built in Aurora's admin with email blocks in the visual editor, lists of the site's customers, scheduled by Aurora and sent by Brevo (or Mailchimp) | [references/newsletters.md](references/newsletters.md) |

They hang together: newsletters are sent to lists of the site's customers, and a shop's customers are linked to them. Build customers first when the site needs more than one. Each is the site's own code, never a change to Aurora.

## 5. Bring the content across

**The starter app's example goes.** A site begun with `composer create-project shipaurora/aurora` comes with an example: a Welcome block and a homepage holding it. Once the site's own blocks and homepage are there, remove what's left of it:

- `app/Blocks/WelcomeBlock.php`, `resources/views/components/blocks/welcome.blade.php`, and its screenshot in `resources/block-previews/welcome.webp` if there is one;
- `database/seeders/HomepageSeeder.php` and its `$this->call()` in `DatabaseSeeder`: the import, or the site's own seeders, make the homepage now;
- the tests in `tests/Feature/ExampleTest.php` that expect the welcome page, replaced by the site's own;
- the homepage it made, if its seeder ran: a page with only a `welcome` block. Delete it, or let the import's homepage take its place (`is_homepage`), so no page points at a block that's gone.

Then `grep -rni welcome app database resources tests` should find nothing of it. Aurora's own layouts (the blank one the welcome page used, too) stay: they're the CMS's, made the site's own, not the example. A Statamic site, or another app Aurora is installed into with `composer require`, never had the example.

Write the import as a seeder (or an artisan command for a big site) that can run again without making doubles:

- **Files first**: `AssetLibrary::fromFile($path, ['id' => $uuid, 'title' => …, 'alt' => …, 'folder' => 'Team'])`, with an id made from the old file's path (`Str::uuid()` is new every run; a UUID v5 of the path is the same each time). Keep a map from old path to asset id for the records and pages that use them.
- **Records**: `Employee::query()->updateOrCreate(['email' => …], [...])`, the photo set to its asset id.
- **Pages**: `Page` records whose `blocks` are made with `BlockTreeBuilder::flatten()` from a nested tree of the new blocks, with each translation in `block_translations` (see "Importing content" in `aurora-development`). Blocks hold the ids of records and assets, never copies of their text. Each page at the address it had (below).
- **Menus**: `Menu` and `MenuItem` records in the locations the layout draws, linking pages (`linkable`) rather than typed addresses, so they follow a renamed page.
- **Old addresses**: a `Redirect` for each address that changes, to its new page.

**Keep the addresses.** Every page keeps the address it had, nesting included, so links and search results keep working; a `Redirect` is for the few that can't. A page's address is its parents' slugs and its own (`/services/web/design`); a slug can't hold a `/`. So:

- **Parents first.** Import the pages top-down: the parent, then its children with `parent_id` set to it, and so on (walk the old site's tree, or sort its addresses by depth). A page imported before its parent, or without `parent_id`, ends up at `/design` instead of `/services/web/design`.
- **A parent the old site didn't have.** If `/services/web/design` existed but `/services` wasn't a page, the address still needs a `services` page above it. Ask the user: an overview page (its children listed) is usually what visitors expect; a draft page keeps the old behaviour, its own address answering 404 while its children are live (a page's status doesn't depend on its parent's).
- **Slugs are unique beside each other, not across the site.** Aurora finds a page by its whole address, part by part, so pages in different places may share their own part (`/services/design` and `/work/design`); only pages under the same parent (or at the top, which includes the pages under the homepage) need different ones, and `Page::uniqueSlug(…, parentId:)` gives the next free number there. An old address that no longer exists is sent on to the one page whose own part it ends with; when several pages end with it, it can't tell which, so give each old address that moves a `Redirect` of its own.
- **The old addresses' quirks:** a trailing slash or a `.html` (`/about.html`) becomes the plain address (`/about`) with a `Redirect` from the old form where it had links; capitals become lowercase. Each language's address uses that language's slug (`setTranslation('slug', $locale, …)`), under its prefix (`/en/…`).
- **Check** after the import: each old address (from the old `sitemap.xml`, or the crawl of section 7) answers 200 at the same address, or redirects once to the right page; `Page::paths()` lists every new address in one go, to compare.
- Keep the site's own content in its seeders, never in Aurora (`aurora:install` offers to run them).

## 6. Check it

- Every block has `defaults()` that look finished, and a screenshot (`php artisan aurora:block-previews`, then save each to `resources/block-previews/{type}.webp`).
- Each page works in the visual editor's canvas: it looks as on the website, and editing a text on the canvas works. How it compares with the old site is section 7.
- The admin shows the site's records under their own group, in every language the site has.
- The old addresses redirect, `sitemap.xml` lists the pages and records, and forms arrive.
- Tests for what the site adds: the blocks render with their defaults, a block whose record is gone draws nothing, the import runs twice without doubles.

## 7. Compare it with the old site

The last step of every port, before the switch: put the old site and the new one side by side, page by page, look and markup, and adjust until they match well enough. Small differences in the HTML are fine; what it means has to come across.

**Which pages.** A list of pairs, old address → new address: the home page, a page of each layout, each block type at least once (with its variations), each list and detail page of the site's own records, each form, the 404, and each of these in every language. Keep the list (a JSON file outside the site's code, in a scratch folder) so the comparison can run again after each fix. Capture the old site's side first, from where it runs (its live address, or locally before switching over), so it can still be compared once the switch is done; go easy on a live site: only the listed pages, one at a time.

**Visually.** Screenshot each pair the same way, with the browser you have (a browser tool, Playwright, or headless Chrome: `"<chrome>" --headless=new --hide-scrollbars --window-size=1440,900 --screenshot=old.png "<url>"`), at a desktop width (1440) and a phone width (390), the whole page. Close cookie banners and pop-ups, and stop sliders, on both sides first. Look at each pair yourself; a pixel diff (ImageMagick's `compare -metric AE`) helps find where to look but doesn't decide. Fix what a visitor would notice: layout and spacing, fonts and colours, an image missing or cropped differently, text missing or in another order, a menu or footer that differs, a breakpoint where the layout changes at another width. Ignore what changes by itself: dates, a carousel's slide, ads, anti-aliasing.

**Semantically.** Fetch both pages' HTML and compare what it means, not how it's written. A throwaway script outside the site's code (PHP with `Symfony\Component\DomCrawler\Crawler`, which comes with Laravel) pulls out, for each page:

- **Head:** status code, `<title>`, meta description, canonical, `robots`/noindex, `og:` and `twitter:` tags, `hreflang` links, `<html lang>`, structured data (JSON-LD: each `@type` and its main fields).
- **Outline:** the headings, `h1` to `h6`, in order, with their text (one `h1`); the landmarks (`header`, `nav`, `main`, `aside`, `footer`).
- **Content:** the text of `main`, whitespace evened out, compared paragraph by paragraph: nothing missing, nothing added, the same order.
- **Links and media:** each navigation's links (text and target, in order), the links in the content (each target answering 200, or redirecting to where the old one went), images (present, with the same alt text; a decorative image stays `alt=""`), embeds (videos, maps).
- **Forms:** each form's fields (name, type, required, label, options) and its buttons.

Fine to differ: class names and wrapper elements, attribute order, inline styles become the stylesheet's, image addresses (the media library's instead of the old CDN's) and their sizes in `srcset`, the old platform's own scripts, tokens and cache-busting. Not fine: a lost heading or a changed heading level, missing text, a link that goes elsewhere or nowhere, missing alt text, a form field gone, a different title, description or canonical, lost structured data, a language missing.

**Adjust until it matches.** Fix the blocks, the layout, the stylesheet or the import (then run the import again), and compare again, until for every pair the screenshots match at a glance at both widths and the semantic differences are only of the fine kind. Then report to the user, a line per page: matches, or what still differs and why it's left so (the old site's bug not carried over, a deliberate improvement, a platform limit such as an image format that can't crop the same way). They decide whether it's good enough to switch.
