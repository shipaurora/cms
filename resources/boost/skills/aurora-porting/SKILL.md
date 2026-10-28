---
name: aurora-porting
description: "Port an existing website (static HTML, another CMS such as WordPress, or a Laravel app) to Aurora CMS: decide what becomes a page layout, a block, a settings tab or a model of the site's own with its Filament resource, then rebuild it and bring the content, files, menus and old addresses across."
metadata:
  author: Aurora
---

# Porting a website to Aurora

Use this together with the `aurora-development` skill, which has the API for each piece named here. The aim is a site editors can run themselves: everything they'd want to change is a page, a block, a setting, a menu or a record in the admin, and nothing they'd want to change is left in a template.

## 1. Take stock before writing code

List, from the old site's templates and its data:

- **Page templates**: the frames pages share (header, footer, sidebar), and how they differ (a landing page without the menu, a docs page with a side menu).
- **Sections**: the parts pages are put together from (hero, feature grid, FAQ, testimonial, call to action, price table), with every variation in use.
- **Records**: data with fields of its own that appears in more than one place, or in lists (staff, products, events, offices, job openings, case studies).
- **Global bits**: header and footer text, social links, a cookie notice, contact details.
- **Navigation**: each menu and where it's shown.
- **Forms**: their fields and who gets the submissions.
- **Files**: images and documents, with their alt text and credits.
- **Addresses**: every URL that has visitors or links pointing at it, and the languages.

## 2. Decide what each one becomes

| On the old site | In Aurora |
| --- | --- |
| A page template | A page layout: the published standard layout made the site's own, or `php artisan make:aurora-layout "Landing"` for each other frame. Settings that change one frame (a background image, a dark header) are `Layouts::schema()` fields, not separate layouts. |
| A section editors place on pages | A block (`php artisan aurora:make-block`). Variations of one section are one block with a choice (`ToggleButtons` for layout, `Fields::tone()` for colour), not one block each. |
| A repeated item inside a section (a card, a stat) | Either a repeater in the section's block (fixed shape, edited as a list) or a container block holding item blocks (`--container`; editors can mix and reorder). |
| Header, footer, other bits every page shows | Blade components in the layout. Their text and links come from a settings tab (`SettingsTabs::register()`), their links from menus. |
| A menu | A menu in a location: `Menu::location('header')` in the layout, a location of the site's own for others. |
| Records with their own fields | A model of the site's own, with a migration and a Filament resource, and blocks that pick or list them (below). |
| A page of text | A page record with blocks, created by a seeder or an import command. |
| A contact form | A form under Content › Forms, shown by a form block. |
| An old address | A redirect (`Aurora\Models\Redirect`), or the same slug. |

**Block or model?** A block is how part of a page looks; a model is a thing that exists on its own. If the same facts (a person's name, title, phone and photo) would be typed into blocks on several pages, or a list of them is shown somewhere, they're a model, and the blocks only point at records. If it's only ever on one page, with no fields beyond what it shows, it's a block.

Port the old markup into the block views as it is, then replace each hard-coded text, image and link with a field. Keep the old site's CSS classes in the site's stylesheet (`resources/css/app.css`): the visual editor draws blocks with it. Turn the old site's brand colours into the `brand` and `accent` theme colours (eleven shades each, `--color-brand-50` to `--color-brand-950`, as defaults in `@theme`), and paint the blocks with `brand-*` and `accent-*` instead of its fixed colours, so editors can change them under Settings › Website › Branding ("Brand colours" in `aurora-development`).

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

**The resource.** A Filament resource in `app/Filament/Resources/Employees` (`php artisan make:filament-resource Employee`), registered from a service provider with `AdminPanel::discover(app_path('Filament/Resources'), 'App\Filament\Resources', perSite: true)` (`perSite` for a model with `BelongsToSite`) and given a group of its own, `AdminPanel::navigationGroup('Team', fn (): string => __('Team'))`, set as the resource's `$navigationGroup`. In its form, Aurora's fields keep it like the rest of the admin:

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

## 4. Bring the content across

Write the import as a seeder (or an artisan command for a big site) that can run again without making doubles:

- **Files first**: `AssetLibrary::fromFile($path, ['id' => $uuid, 'title' => …, 'alt' => …, 'folder' => 'Team'])`, with an id made from the old file's path (`Str::uuid()` is new every run; a UUID v5 of the path is the same each time). Keep a map from old path to asset id for the records and pages that use them.
- **Records**: `Employee::query()->updateOrCreate(['email' => …], [...])`, the photo set to its asset id.
- **Pages**: `Page` records whose `blocks` are made with `BlockTreeBuilder::flatten()` from a nested tree of the new blocks, with each translation in `block_translations` (see "Importing content" in `aurora-development`). Blocks hold the ids of records and assets, never copies of their text.
- **Menus**: `Menu` and `MenuItem` records in the locations the layout draws, linking pages (`linkable`) rather than typed addresses, so they follow a renamed page.
- **Old addresses**: a `Redirect` for each address that changes, to its new page.
- Keep the site's own content in its seeders, never in Aurora (`aurora:install` offers to run them).

## 5. Check it

- Every block has `defaults()` that look finished, and a screenshot (`php artisan aurora:block-previews`, then save each to `resources/block-previews/{type}.webp`).
- Each page looks as it did on the old site, on the website and in the visual editor's canvas; editing a text on the canvas works.
- The admin shows the site's records under their own group, in every language the site has.
- The old addresses redirect, `sitemap.xml` lists the pages and records, and forms arrive.
- Tests for what the site adds: the blocks render with their defaults, a block whose record is gone draws nothing, the import runs twice without doubles.
