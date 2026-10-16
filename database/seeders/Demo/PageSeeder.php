<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Database\Seeders\Demo\Support\B;
use Aurora\Enums\PageStatus;
use Aurora\Enums\RevisionSource;
use Aurora\Models\Asset;
use Aurora\Models\AssetTag;
use Aurora\Models\Form;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\BlockTranslations;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\ContentLocale;
use Aurora\Support\RevisionContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PageSeeder extends Seeder
{
    /**
     * Page ids by slug, so blocks can link to pages (links follow a page when its slug changes).
     *
     * @var array<string, string>
     */
    protected array $ids = [];

    /**
     * Media library asset ids by demo path ("demo/fjord.jpg"), so seeded content uses assets.
     *
     * @var array<string, string>
     */
    protected array $assets = [];

    protected ?Form $contactForm = null;

    /**
     * Titles, alt text and folders for the demo images (an empty folder means the top level).
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    protected const IMAGES = [
        'fjord' => ['Preikestolen over Lysefjorden', 'A steep cliff above a deep blue fjord under a clear sky', 'Photos/Norway'],
        'mountains' => ['Green ridge in the mist', 'Grassy mountain ridges disappearing into low clouds', 'Photos/Norway'],
        'forest' => ['Valley with granite cliffs', 'Pine trees in a valley beneath tall granite cliffs', 'Photos/Nature'],
        'lake' => ['River through the forest', 'A person standing by a rushing river in a misty forest', 'Photos/Nature'],
        'camp' => ['Winter base camp', 'Yellow tents in deep snow below snowy peaks', ''],
        'city' => ['City at dusk', 'A city skyline at sunset seen from above', ''],
        'desk' => ['Laptop and notebook', 'A laptop beside an open notebook with sketches on a wooden desk', 'Studio'],
        'workspace' => ['Designer\'s desk', 'Top-down view of a desk with a laptop, sketches, a phone and books', 'Studio'],
        'laptop' => ['Laptop on a café table', 'A laptop, a phone and a coffee cup on a wooden table', 'Studio'],
        'coffee' => ['Coffee mug', 'A red patterned coffee mug on a table', ''],
        'person1' => ['Portrait: Hanna', 'Portrait of a smiling woman with short blonde hair', 'People/Team'],
        'person2' => ['Portrait: Jonas', 'Portrait of a man with a beard', 'People/Team'],
        'person3' => ['Portrait: Emilie', 'Portrait of a smiling woman with long hair', 'People/Team'],
        'person4' => ['Portrait: Aksel', 'Portrait of a man with short hair', 'People/Team'],
        'person5' => ['Portrait: Vera', 'Portrait of a woman with dark hair', 'People/Team'],
        'person6' => ['Portrait: Ola', 'Portrait of a smiling man', 'People/Team'],
        'screen-dashboard' => ['Screenshot: dashboard', 'The Aurora dashboard: a greeting, shortcuts, page statistics, recent changes and pages waiting for review', 'Screenshots'],
        'screen-pages' => ['Screenshot: pages', 'The pages list in the Aurora admin, with each page\'s status, publishing date and last edit', 'Screenshots'],
        'screen-editor' => ['Screenshot: visual editor', 'The Aurora visual editor: the block palette on the left, the page on the canvas and the inspector on the right', 'Screenshots'],
        'screen-media' => ['Screenshot: media library', 'The Aurora media library, with folders and image cards', 'Screenshots'],
        'screen-menu' => ['Screenshot: menu editor', 'The Aurora menu editor, with nested menu items linked to pages', 'Screenshots'],
        'screen-settings' => ['Screenshot: site settings', 'Site settings in Aurora: the site name, contact address, tagline and brand colours', 'Screenshots'],
        'screen-security' => ['Screenshot: security settings', 'Security settings in Aurora: switches to require two-factor sign-in and passkeys for everyone', 'Screenshots'],
    ];

    /**
     * The site's structure: parent page => the pages under it.
     *
     * @var array<string, array<int, string>>
     */
    protected const PARENTS = [
        'docs' => ['getting-started', 'creating-blocks', 'website-settings', 'translations', 'ai-and-mcp', 'security', 'architecture', 'components'],
        'guides' => ['build-a-landing-page', 'let-claude-draft-a-page', 'add-your-own-block', 'run-several-sites', 'upgrading-from-other-cmses'],
        'legal' => ['privacy', 'terms'],
    ];

    /**
     * Demo tags: name => [colour, image names].
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    protected const TAGS = [
        'Hero' => ['#8b5cf6', ['fjord', 'mountains', 'camp', 'city']],
        'Landscape' => ['#22c55e', ['fjord', 'mountains', 'forest', 'lake', 'camp']],
        'Winter' => ['#0ea5e9', ['camp']],
        'Office' => ['#f59e0b', ['desk', 'workspace', 'laptop', 'coffee']],
        'Portrait' => ['#ec4899', ['person1', 'person2', 'person3', 'person4', 'person5', 'person6']],
        'Licensed' => ['#64748b', ['fjord', 'city', 'person1', 'person2']],
        'Screenshot' => ['#7c3aed', ['screen-dashboard', 'screen-pages', 'screen-editor', 'screen-media', 'screen-menu', 'screen-settings', 'screen-security']],
    ];

    public function run(): void
    {
        $this->createAssets();

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $editor = User::query()->where('email', 'editor@example.com')->first();
        $author = User::query()->where('email', 'author@example.com')->first();

        // Aurora's own website: the product, the docs and the guides. One guide waits for
        // review and one is a draft, so the workflow has something to show.
        $pages = [
            ['home', 'Home', 'landing', PageStatus::Published, $admin, 'Aurora is a content management system for Laravel, built on Filament.', true],
            ['why-aurora', 'Why Aurora', 'standard', PageStatus::Published, $editor, 'Pages are layouts, not forms: the thinking behind Aurora.'],
            ['features', 'Features', 'standard', PageStatus::Published, $editor, 'A tour of the CMS, from the canvas to the command line.'],
            ['architecture', 'Architecture', 'standard', PageStatus::Published, $admin, 'How Aurora is built: a Laravel package, your blocks, and one set of tools for editors and AI.'],
            ['docs', 'Docs', 'standard', PageStatus::Published, $admin, 'Install Aurora, build a website on it and run it with your team.'],
            ['getting-started', 'Getting started', 'standard', PageStatus::Published, $admin, 'Install Aurora into a Laravel app and sign in for the first time.'],
            ['creating-blocks', 'Creating blocks', 'standard', PageStatus::Published, $admin, 'Write a block: a PHP class, a Blade view and one line to register it.'],
            ['website-settings', 'Website settings', 'standard', PageStatus::Published, $admin, 'Give your templates settings editors can change, per site.'],
            ['translations', 'Languages & translations', 'standard', PageStatus::Published, $editor, 'Content in every language, and every label in the admin.'],
            ['ai-and-mcp', 'AI assistant & MCP', 'standard', PageStatus::Published, $admin, 'Switch on the assistant and connect Claude and other apps.'],
            ['security', 'Roles & security', 'standard', PageStatus::Published, $admin, 'Roles, permissions, passkeys and two-factor sign-in.'],
            ['guides', 'Guides', 'standard', PageStatus::Published, $editor, 'Short, practical how-tos for editors and developers.'],
            ['build-a-landing-page', 'Build a landing page in the visual editor', 'standard', PageStatus::Published, $editor, 'From an empty canvas to a published campaign page, step by step.'],
            ['let-claude-draft-a-page', 'Let Claude draft a page for you', 'standard', PageStatus::Published, $editor, 'Connect Claude over MCP and ask it for a first draft you can refine.'],
            ['add-your-own-block', 'Add your own block', 'standard', PageStatus::Published, $admin, 'How the code sample block on these pages was made.'],
            ['run-several-sites', 'Run several websites from one admin', 'standard', PageStatus::Review, $author, 'Domains, languages and access for more than one website.'],
            ['upgrading-from-other-cmses', 'Moving to Aurora from another CMS', 'standard', PageStatus::Draft, $author, 'Notes for teams moving content from a form-based CMS.'],
            ['release-notes', 'Release notes', 'standard', PageStatus::Published, $admin, 'What changed in each version of Aurora.'],
            ['components', 'Component library', 'standard', PageStatus::Published, $admin, 'Every block editors can use, on one page.'],
            ['contact', 'Contact', 'standard', PageStatus::Published, $editor, 'Questions about Aurora, or a demo for your team.'],
            ['legal', 'Legal', 'standard', PageStatus::Published, $admin, 'The terms and policies for this website.'],
            ['privacy', 'Privacy policy', 'standard', PageStatus::Published, $admin, 'How we handle your data.'],
            ['terms', 'Terms of service', 'standard', PageStatus::Published, $admin, 'The terms that apply to this website.'],
        ];

        // Create every page first so blocks can link to them by id.
        foreach ($pages as $index => [$slug, $title, $layout, $status, $owner, $excerpt]) {
            $page = RevisionContext::as(RevisionSource::System, 'Created the page', fn () => Page::query()->create([
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'layout_id' => Layout::query()->where('handle', $layout)->value('id'),
                'status' => $status,
                'published_at' => $status === PageStatus::Published ? now()->subDays(count($pages) - $index) : null,
                'is_homepage' => $pages[$index][6] ?? false,
                'author_id' => $owner?->id,
                'last_edited_by_id' => $owner?->id,
                'og_image' => $this->asset(match ($slug) {
                    'build-a-landing-page' => 'demo/screen-editor.jpg',
                    'let-claude-draft-a-page' => 'demo/screen-dashboard.jpg',
                    'add-your-own-block' => 'demo/workspace.jpg',
                    'run-several-sites' => 'demo/screen-settings.jpg',
                    default => null,
                }),
            ]));

            $this->ids[$slug] = $page->id;
        }

        // Where each page sits (/docs/getting-started, /legal/privacy): set once every page
        // exists, so a parent may come later in the list than its subpages.
        foreach (self::PARENTS as $parent => $children) {
            Page::query()->whereKey(array_map(fn (string $slug): string => $this->ids[$slug], $children))->toBase()->update(['parent_id' => $this->ids[$parent]]);
        }

        $this->contactForm = $this->createContactForm();

        foreach ($this->ids as $slug => $id) {
            $method = 'blocksFor'.str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
            $tree = method_exists($this, $method) ? $this->{$method}() : $this->legalPage($slug);
            $page = Page::query()->find($id);

            auth()->setUser($page->author ?? $admin);

            RevisionContext::as(RevisionSource::Canvas, 'Designed the page', fn () => $page->update([
                'blocks' => BlockTreeBuilder::flatten($this->withAssets($tree)),
            ]));
        }

        auth()->logout();

        $this->translate();

        // The pages list shows the latest edited first: in the order above, so Home leads.
        foreach (array_values($this->ids) as $position => $id) {
            Page::query()->whereKey($id)->toBase()->update(['updated_at' => now()->subMinutes($position)]);
        }

        // A little history, so the revision list and the AI activity feed have a story to tell.
        $home = Page::query()->find($this->ids['home']);
        $home->revisions()->create([
            'user_id' => $admin->id,
            'source' => RevisionSource::Mcp,
            'summary' => 'Rewrote the hero headline and added the developer section',
            'snapshot' => $home->toSnapshot(),
            'created_at' => now()->subHours(3),
        ]);
    }

    /**
     * Every language with a dictionary in seeders/translations ("nb.json": English text =>
     * that language's) gets the pages', images' and form's text. Names, e-mail addresses and
     * anything else not in it stay as they are.
     */
    protected function translate(): void
    {
        foreach (ContentLocale::translations() as $locale => $language) {
            $file = __DIR__."/translations/{$locale}.json";

            if (! is_file($file)) {
                continue;
            }

            $dictionary = json_decode(file_get_contents($file), true);

            foreach (Page::query()->get() as $page) {
                $this->translatePage($page, $locale, $language, $dictionary);
            }

            $this->translateForm($this->contactForm, $locale, $dictionary);

            foreach (Asset::query()->get() as $asset) {
                foreach (['alt', 'caption'] as $attribute) {
                    $text = $asset->getTranslation($attribute, ContentLocale::default(), false);

                    if (isset($dictionary[$text])) {
                        $asset->setTranslation($attribute, $locale, $dictionary[$text]);
                    }
                }

                $asset->save();
            }
        }
    }

    /**
     * The form's text in another language; its fields stay the same.
     *
     * @param  array<string, string>  $dictionary
     */
    protected function translateForm(Form $form, string $locale, array $dictionary): void
    {
        $fields = [];

        foreach ($form->fields as $field) {
            foreach (Form::FIELD_TEXT as $attribute) {
                $original = $field['data'][$attribute] ?? null;
                $translated = $this->translated($original, $locale, $dictionary);

                if (filled($original) && $translated !== $original) {
                    $fields[$field['data']['name']][$attribute] = $translated;
                }
            }
        }

        foreach (['submit_label', 'success_message'] as $attribute) {
            $text = $form->getTranslation($attribute, ContentLocale::default(), false);

            if (isset($dictionary[$text])) {
                $form->setTranslation($attribute, $locale, $dictionary[$text]);
            }
        }

        $form->field_translations = [...$form->field_translations ?? [], $locale => $fields];
        $form->save();
    }

    /**
     * @param  array<string, string>  $dictionary
     */
    protected function translatePage(Page $page, string $locale, string $language, array $dictionary): void
    {
        $default = ContentLocale::default();
        $blocks = [];

        foreach ($page->blocks ?? [] as $block) {
            foreach (BlockTranslations::fieldsOf($block['type']) as $field) {
                $original = $block['data'][$field] ?? null;
                $translated = $this->translated($original, $locale, $dictionary);

                if (filled($original) && $translated !== $original) {
                    $blocks[$block['id']][$field] = $translated;
                }
            }
        }

        foreach (['title', 'excerpt', 'meta_title', 'meta_description'] as $attribute) {
            $text = $page->getTranslation($attribute, $default, false);

            if (isset($dictionary[$text])) {
                $page->setTranslation($attribute, $locale, $dictionary[$text]);
            }
        }

        $page->block_translations = [...$page->block_translations ?? [], $locale => $blocks];

        auth()->setUser($page->author);

        RevisionContext::as(RevisionSource::Canvas, "Translated into {$language}", fn () => $page->save());

        auth()->logout();
    }

    /**
     * A text field's value in another language: its strings looked up, lists item by item.
     *
     * @param  array<string, string>  $dictionary
     */
    protected function translated(mixed $value, string $locale, array $dictionary): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->translated($item, $locale, $dictionary), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        // The legal pages' date is today's: written out in the language.
        if (preg_match('/^Last updated (.+)\.$/', $value, $match)) {
            return __('Last updated :date.', ['date' => Carbon::parse($match[1])->locale($locale)->isoFormat('LL')], $locale);
        }

        return $dictionary[$value] ?? $value;
    }

    protected function id(string $slug): ?string
    {
        return $this->ids[$slug] ?? null;
    }

    protected function createContactForm(): Form
    {
        $form = Form::query()->create([
            'name' => 'Contact',
            'submit_label' => 'Send message',
            'success_message' => 'Thanks! We reply within one working day.',
            'notify' => ['hello@aurora.test'],
            'fields' => [
                ['type' => 'text', 'data' => ['label' => 'Your name', 'name' => 'name', 'required' => true, 'width' => 'half']],
                ['type' => 'email', 'data' => ['label' => 'Email', 'name' => 'email', 'required' => true, 'width' => 'half']],
                ['type' => 'text', 'data' => ['label' => 'Company', 'name' => 'company', 'width' => 'half']],
                ['type' => 'select', 'data' => ['label' => 'Team size', 'name' => 'team_size', 'options' => ['Just me', '2–10', '11–50', 'More than 50'], 'width' => 'half']],
                ['type' => 'checkboxes', 'data' => ['label' => 'What are you interested in?', 'name' => 'interests', 'options' => ['The visual editor', 'AI and MCP', 'Several sites', 'Moving from another CMS']]],
                ['type' => 'textarea', 'data' => ['label' => 'How can we help?', 'name' => 'message', 'required' => true, 'placeholder' => 'What are you building, and what do you need?']],
                ['type' => 'consent', 'data' => ['label' => 'Consent', 'name' => 'consent', 'required' => true, 'text' => 'I agree that the Aurora team stores my details to answer this request.']],
            ],
        ]);

        foreach ([
            ['Kari Nordmann', 'kari@fjordline.test', 'Fjordline', '11–50', ['The visual editor', 'Moving from another CMS'], 'We run three brand sites on a form-based CMS and our editors are fed up. Could we see a demo next week?', 'new', 2],
            ['Lars Berg', 'lars@bryggen.test', 'Bryggen Bank', 'More than 50', ['AI and MCP'], 'Our security team wants to know exactly what an AI assistant can do with an editor\'s account. Is there documentation?', 'new', 20],
            ['Mia Holm', 'mia@tindra.test', 'Tindra Health', '2–10', ['Several sites'], 'Can one admin serve our Norwegian and Swedish sites on different domains?', 'read', 50],
        ] as [$name, $email, $company, $teamSize, $interests, $message, $status, $hoursAgo]) {
            $form->submissions()->create([
                'status' => $status,
                'page_id' => $this->ids['contact'] ?? null,
                'data' => [
                    'name' => ['label' => 'Your name', 'value' => $name],
                    'email' => ['label' => 'Email', 'value' => $email],
                    'company' => ['label' => 'Company', 'value' => $company],
                    'team_size' => ['label' => 'Team size', 'value' => $teamSize],
                    'interests' => ['label' => 'What are you interested in?', 'value' => $interests],
                    'message' => ['label' => 'How can we help?', 'value' => $message],
                    'consent' => ['label' => 'Consent', 'value' => 'Yes'],
                ],
            ])->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();
        }

        return $form;
    }

    protected function createAssets(): void
    {
        foreach (glob(__DIR__.'/images/*.jpg') as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            [$title, $alt, $folder] = self::IMAGES[$name] ?? [null, null, null];

            $this->assets['demo/'.basename($file)] = AssetLibrary::fromFile($file, array_filter([
                'title' => $title,
                'alt' => $alt,
                'folder' => $folder,
            ]))->id;
        }

        // Not everything is an image: a PDF, a spreadsheet and a sound, to show the library's other files.
        foreach (['brand-guidelines.pdf' => 'Brand guidelines', 'price-list.csv' => 'Price list', 'studio-jingle.wav' => 'Studio jingle'] as $file => $title) {
            AssetLibrary::fromFile(__DIR__.'/files/'.$file, ['title' => $title, 'folder' => 'Documents']);
        }

        foreach (self::TAGS as $name => [$color, $images]) {
            AssetTag::query()
                ->create(['name' => $name, 'color' => $color])
                ->assets()
                ->attach(array_map(fn (string $image): string => $this->assets['demo/'.$image.'.jpg'], $images));
        }
    }

    protected function asset(?string $path): ?string
    {
        return $path === null ? null : ($this->assets[$path] ?? $path);
    }

    /**
     * Swap every "demo/…" image path in a block tree for its media library asset id.
     */
    protected function withAssets(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->assets[$value] ?? $value;
        }

        return is_array($value) ? array_map(fn (mixed $child): mixed => $this->withAssets($child), $value) : $value;
    }

    /* ── Product pages ────────────────────────────────────────────────── */

    protected function blocksForHome(): array
    {
        return [
            ['type' => 'hero', 'data' => [
                'layout' => 'centered',
                'badge' => 'New',
                'badge_text' => 'Two-factor sign-in and passkeys, built in',
                'headline' => 'The CMS your editors *enjoy* and your developers trust',
                'subheadline' => "Aurora is a content management system for Laravel, built on Filament.\nPages are composed visually, every change is a revision, and AI assistants work with exactly the permissions of the person using them.",
                'primary_label' => 'Get started',
                'primary_type' => 'page',
                'primary_page' => $this->id('getting-started'),
                'secondary_label' => 'Why Aurora?',
                'secondary_type' => 'page',
                'secondary_page' => $this->id('why-aurora'),
            ]],
            B::band([
                B::image('demo/screen-editor.jpg', 'The Aurora visual editor', null, '16/9', true),
            ], 'white', 'md', 'wide'),
            B::band([
                ['type' => 'logo-cloud', 'data' => [
                    'title' => 'Built on tools Laravel teams already know',
                    'marquee' => true,
                    'logos' => array_map(fn ($name) => ['name' => $name], ['Laravel', 'Filament', 'Livewire', 'Alpine.js', 'Tailwind CSS', 'Laravel MCP', 'Spatie Media Library', 'Passport']),
                ]],
            ], 'white', 'md'),
            B::band([
                B::heading('One system for the whole team', 'Who it is for', 'Editors compose pages, developers own the blocks and the code, and AI assistants help both, inside the same permissions.', 'xl', 'center'),
                B::spacer(),
                ['type' => 'tabs', 'data' => ['style' => 'pills', 'items' => [
                    ['label' => 'Editors', 'title' => 'Write on the page itself', 'body' => "Drag blocks onto the canvas, type straight into headings and see exactly what visitors will see. Nest sections, columns and grids, undo anything, and send a page for review when it is ready.\nEvery save is a revision you can restore in one click."],
                    ['label' => 'Developers', 'title' => 'Blocks are code you own', 'body' => "A block is a PHP class and a Blade view in your app. Register it in a service provider and it appears in the editor, the AI tools and the translation screens at once.\nAurora installs as a Composer package into any Laravel site and stays out of your way."],
                    ['label' => 'AI assistants', 'title' => 'Assistants with the same keys as you', 'body' => "Chat with the assistant in the admin, or connect Claude and other apps over MCP. They read and edit pages through the same rules as the person using them: an author's assistant drafts, an editor's assistant publishes.\nEvery change is logged and can be undone."],
                ]]],
            ], 'muted', 'xl', 'wide'),
            B::band([
                B::columns([
                    [B::image('demo/screen-pages.jpg', 'The pages list in the Aurora admin', null, '16/9', true)],
                    [
                        B::heading('A visual editor that *is* the page', 'Visual editor', 'The canvas renders your real templates, so what an editor sees is what ships. No previews to refresh, no forms to imagine.'),
                        ['type' => 'list', 'data' => ['marker' => 'check', 'items' => "Sections, columns and grids, nested as deep as the layout needs\nInline editing, with undo and redo\nA revision for every save, restorable in one click\nScheduled publishing and a review workflow"]],
                        B::buttons([
                            B::button('See every block', $this->id('components'), 'primary', icon: 'arrow-right'),
                            B::button('All features', $this->id('features'), 'link'),
                        ]),
                    ],
                ], '1-1'),
            ], 'white', 'xl', 'wide'),
            B::band([
                B::heading('Everything a content team needs', 'Features', null, 'lg', 'center'),
                B::spacer(),
                B::grid([
                    B::feature('squares-plus', 'Visual page builder', 'Compose pages from blocks on a live canvas, with your own design system.', 'card'),
                    B::feature('photo', 'Media library', 'Folders, tags, crops and focal points, and every place a file is used.', 'card'),
                    B::feature('language', 'Translations', 'Every page, menu and label in every language, with an AI translator on hand.', 'card'),
                    B::feature('globe-europe-africa', 'Multiple sites', 'Run several websites from one admin, each with its own domain and settings.', 'card'),
                    B::feature('sparkles', 'AI assistant & MCP', 'An assistant in the admin, and Claude and other apps connected over MCP.', 'card', 'accent'),
                    B::feature('shield-check', 'Roles & security', 'Custom roles, two-factor sign-in, passkeys and a full activity log.', 'card', 'success'),
                ], 3),
            ], 'muted', 'xl', 'wide'),
            B::band([
                B::heading('Small enough to understand, complete enough to ship', 'In numbers', null, 'lg', 'center'),
                B::spacer(),
                B::grid([
                    B::stat('25', 'Blocks out of the box', '+', null, 'Every one editable on the canvas'),
                    B::stat('22', 'MCP tools', null, null, 'For pages, blocks, menus and media'),
                    B::stat('2', 'Languages in the admin', null, null, 'English and Norwegian, more by import'),
                    B::stat('300', 'Feature tests', '+', null, 'Run on every change'),
                ], 4),
            ], 'aurora', 'xl', 'wide'),
            B::band([
                B::columns([
                    [
                        B::heading('Install it into the Laravel site you already have', 'For developers', 'Aurora is a Composer package. Add it, run the installer, register your blocks, and your team has a CMS by lunch.'),
                        B::buttons([
                            B::button('Read the docs', $this->id('docs'), 'primary', icon: 'book-open'),
                            B::button('How it is built', $this->id('architecture'), 'link'),
                        ]),
                    ],
                    [B::code("composer require aurora/cms\nphp artisan aurora:install", 'bash', null, 'The installer sets up the database, roles and your first admin account.')],
                ], '1-1'),
            ], 'white', 'xl', 'wide'),
            B::band([
                B::heading('From the guides', 'Learn', null, 'lg'),
                B::spacer('sm'),
                ['type' => 'page-list', 'data' => ['source' => 'selected', 'page_ids' => [$this->id('build-a-landing-page'), $this->id('let-claude-draft-a-page'), $this->id('add-your-own-block')], 'link_label' => 'Read the guide']],
            ], 'muted', 'xl', 'wide'),
            B::band([
                ['type' => 'cta', 'data' => [
                    'style' => 'gradient',
                    'title' => 'See it for yourself',
                    'text' => 'Sign in to the admin of this site, open a page in the visual editor and change anything. Nothing you do here can break it.',
                    'button_label' => 'Get started',
                    'button_type' => 'page',
                    'button_page' => $this->id('getting-started'),
                ]],
            ], 'white', 'lg', 'wide'),
        ];
    }

    protected function blocksForWhyAurora(): array
    {
        return [
            ['type' => 'hero', 'data' => [
                'layout' => 'split',
                'image' => 'demo/screen-dashboard.jpg',
                'badge' => 'Why',
                'badge_text' => 'The thinking behind Aurora',
                'headline' => 'Pages are layouts, not *forms*',
                'subheadline' => 'Most content systems ask editors to fill in fields and imagine the result. Aurora lets them work on the page itself, without giving up structure, permissions or code you own.',
                'primary_label' => 'See the features',
                'primary_type' => 'page',
                'primary_page' => $this->id('features'),
                'secondary_label' => '',
            ]],
            B::band([
                B::heading('Three things we believe', 'Principles', null, 'lg', 'center'),
                B::spacer(),
                B::grid([
                    B::feature('cursor-arrow-rays', 'Edit what visitors see', 'The editor renders the real templates. When the editing surface is the page, people stop asking developers to "just move that box".', 'card'),
                    B::feature('cube', 'Structure stays structure', 'Every block is typed, validated and stored as JSON. That is what lets translations, search and AI assistants work with content safely.', 'card'),
                    B::feature('key', 'Nobody gets more power than they have', 'Roles decide what people can do, and an assistant acting for someone has exactly their rights. Nobody can grant a permission they do not hold.', 'card', 'success'),
                ], 3),
            ], 'muted', 'xl', 'wide'),
            B::band([
                B::columns([
                    [B::heading('Why not a form-based CMS?', 'The problem', null, 'md')],
                    [B::rich('<p>A form is a great way to capture data, and a poor way to make a landing page. Editors think in <strong>sections, columns and emphasis</strong>, not field names. When every layout change needs a new field, a developer, or both, pages end up looking the same.</p><p>Aurora keeps the parts of structured content that matter (types, validation, revisions, translations) and drops the guessing. Blocks are still fields under the hood: the inspector shows them, and the canvas lets people type straight into them.</p>')],
                ], '1-2', 'xl', 'start'),
                B::spacer('lg'),
                B::columns([
                    [B::heading('Why build on Laravel and Filament?', 'The platform', null, 'md')],
                    [B::rich('<p>Your team already knows Laravel. Aurora is a package in it, not a platform beside it: your models, queues, tests and deployments stay the same. Filament gives the admin a consistent, accessible interface that developers can extend with the tools they already use.</p><p>Nothing is hidden in a hosted service. The database is yours, the code is in your repository, and the blocks are classes you can read.</p>')],
                ], '1-2', 'xl', 'start'),
                B::spacer('lg'),
                B::columns([
                    [B::heading('Why put AI in a CMS?', 'The assistant', null, 'md')],
                    [B::rich('<p>Because the hard part of AI in content work is not writing, it is <em>trust</em>. Aurora\'s assistant uses the same tools the editor does, with the same permissions, and every change it makes is a revision with the person\'s name on it.</p><p>The same tools are offered over the Model Context Protocol, so Claude and other apps can draft, translate and tidy pages too, and nothing they do is out of reach of the undo button.</p>')],
                ], '1-2', 'xl', 'start'),
            ], 'white', 'xl', 'wide'),
            B::band([
                B::heading('Questions people ask', 'FAQ', null, 'lg', 'center'),
                B::spacer(),
                ['type' => 'accordion', 'data' => ['first_open' => true, 'faq_schema' => true, 'items' => [
                    ['question' => 'Can I use my own design?', 'answer' => 'Yes. Blocks are Blade views in your app, styled however you like. The visual editor renders them with your stylesheet, so editors always see your design.'],
                    ['question' => 'Does Aurora work with an existing Laravel site?', 'answer' => 'That is what it is for. Install the package, run aurora:install, and register your blocks and settings from your own service provider.'],
                    ['question' => 'Where does my content live?', 'answer' => 'In your database. Pages store their blocks as JSON, media goes to whichever filesystem disk you configure, and nothing is sent anywhere unless you connect an AI provider.'],
                    ['question' => 'Do I have to use the AI features?', 'answer' => 'No. The assistant stays switched off until someone adds a provider and an API key under Settings, and MCP access is a separate permission per role.'],
                    ['question' => 'Which languages does the admin speak?', 'answer' => 'English and Norwegian out of the box. Every label can be translated from the Translations screen, and new languages can be imported from lang files.'],
                ]]],
            ], 'muted', 'xl'),
            B::band([
                ['type' => 'cta', 'data' => ['style' => 'dark', 'title' => 'Convinced, or curious?', 'text' => 'The quickest way to judge a CMS is to use it. Install Aurora in a fresh Laravel app in a few minutes.', 'button_label' => 'Getting started', 'button_type' => 'page', 'button_page' => $this->id('getting-started')]],
            ], 'white', 'lg', 'wide'),
        ];
    }

    protected function blocksForFeatures(): array
    {
        $group = fn (string $eyebrow, string $title, string $intro, array $features, string $background = 'white', ?string $screenshot = null): array => B::band(array_values(array_filter([
            B::heading($title, $eyebrow, $intro, 'lg'),
            B::spacer(),
            B::grid($features, 3),
            $screenshot !== null ? B::spacer('lg') : null,
            $screenshot !== null ? B::image($screenshot, '', null, '16/9', true) : null,
        ])), $background, 'xl', 'wide');

        return [
            B::band([
                B::heading('Everything Aurora does', 'Features', 'A tour of the CMS, from the canvas to the command line. Every feature here is in this site\'s admin: sign in and try it.', 'xl', 'left', 'h1'),
            ], 'brand-soft', 'lg', 'wide'),
            $group('Editing', 'Compose pages visually', 'The page is the editor.', [
                B::feature('squares-plus', 'Visual canvas', 'Drag blocks in, type in place, and nest sections, columns and grids.'),
                B::feature('arrow-uturn-left', 'Revisions', 'Every save is a revision. Compare, restore, and see who changed what.'),
                B::feature('calendar-days', 'Scheduling & review', 'Publish later, or send a page for review: reviewers are notified.'),
                B::feature('magnifying-glass', 'SEO built in', 'Titles, descriptions, share images, a sitemap and redirects when addresses change.'),
                B::feature('command-line', 'Command palette', 'Jump anywhere, create anything and ask the assistant, from the keyboard.'),
                B::feature('trash', 'Trash', 'Deleted pages, forms and files wait in the trash until you empty it.'),
            ], 'white', 'demo/screen-editor.jpg'),
            $group('Content', 'Media, forms and menus', 'The parts every website needs, done properly.', [
                B::feature('photo', 'Media library', 'Folders, tags, crops, focal points, and where each file is used.'),
                B::feature('document-text', 'Forms', 'Build forms from fields, collect submissions and get notified by email.'),
                B::feature('bars-3', 'Menus', 'Menus with any depth, linking to pages that keep their links when they move.'),
            ], 'muted', 'demo/screen-media.jpg'),
            $group('Languages and sites', 'Built for more than one', 'One admin for every language and every website.', [
                B::feature('language', 'Content in every language', 'Pages, menus, media text and forms, each with its own address per language.'),
                B::feature('chat-bubble-left-right', 'Labels you can edit', 'Every label in the admin and the website, translated from one screen.'),
                B::feature('globe-europe-africa', 'Multiple sites', 'Several websites, each with its own domain, language and settings.'),
            ], 'white', 'demo/screen-menu.jpg'),
            $group('People and security', 'The right access for everyone', 'Roles, sign-in and an audit trail.', [
                B::feature('user-group', 'Roles & permissions', 'Ready-made roles, your own roles, and nobody can grant more than they hold.'),
                B::feature('finger-print', 'Passkeys & two-factor', 'Passkeys, authenticator apps and recovery codes, and a switch to require them.'),
                B::feature('envelope', 'Invitations', 'Invite people with a role; they choose their own password.'),
            ], 'muted', 'demo/screen-security.jpg'),
            $group('AI', 'Assistants you can trust', 'Helpful, and never more powerful than the person using them.', [
                B::feature('sparkles', 'Assistant in the admin', 'Ask it to draft, restructure or translate. It knows every block.', 'soft', 'accent'),
                B::feature('link', 'MCP for Claude and more', 'Connect Claude and other apps with a URL and a sign-in, no keys to copy.', 'soft', 'accent'),
                B::feature('clipboard-document-list', 'Activity log', 'Every AI action, who asked for it, and a revision to undo it.', 'soft', 'accent'),
            ]),
            B::band([
                ['type' => 'cta', 'data' => ['style' => 'brand', 'title' => 'See the building blocks', 'text' => 'Every block editors can use, on one page.', 'button_label' => 'Open the component library', 'button_type' => 'page', 'button_page' => $this->id('components')]],
            ], 'white', 'lg', 'wide'),
        ];
    }

    protected function blocksForArchitecture(): array
    {
        return [
            B::band([
                B::heading('How Aurora is built', 'Architecture', 'A Laravel package with a Filament admin, a block system your app extends, and one set of tools shared by editors, the assistant and MCP.', 'xl', 'left', 'h1'),
            ], 'brand-soft', 'lg', 'wide'),
            B::band([
                B::heading('The package and your app', 'The big picture', null, 'md'),
                B::spacer('sm'),
                B::columns([
                    [B::card('Aurora (the package)', 'Models, the admin panel, the media library, forms, menus, translations, the AI assistant and the MCP server. Installed with Composer, updated like any package.', null, 'vendor/aurora/cms', null, 'bordered')],
                    [B::card('Your app (the website)', 'Your page template, your blocks and their views, your settings tabs and your styles. Registered with Aurora from your own service provider.', null, 'app/', null, 'bordered')],
                ], '1-1', 'lg', 'stretch'),
                B::spacer(),
                B::rich('<p>The rule is simple: <strong>Aurora never depends on your app.</strong> Where it needs something from the website (its blocks, its settings, the view a page is drawn with) it offers a hook, and your app calls it. That keeps upgrades boring.</p>'),
            ], 'white', 'xl', 'wide'),
            B::band([
                B::heading('The stack', null, null, 'md'),
                B::spacer('sm'),
                B::grid([
                    B::feature('server-stack', 'Laravel 13', 'Models, queues, mail, authentication and the HTTP layer.', 'outline'),
                    B::feature('rectangle-group', 'Filament 5', 'The admin panel: resources, forms, tables and actions.', 'outline'),
                    B::feature('bolt', 'Livewire & Alpine', 'The canvas and every interactive screen.', 'outline'),
                    B::feature('paint-brush', 'Tailwind CSS', 'The admin theme and, if you like, your website.', 'outline'),
                ], 4),
            ], 'muted', 'xl', 'wide'),
            B::band([
                B::columns([
                    [
                        B::heading('Pages are blocks, stored as JSON', 'Content model', 'A page has a title and address per language, a status, SEO fields and a list of blocks. Containers (sections, grids, button groups) hold other blocks in named slots.', 'md'),
                        ['type' => 'list', 'data' => ['marker' => 'check', 'items' => "Text that differs per language sits beside the blocks, per block\nEvery save writes a revision with a full snapshot\nFiles are referenced by media library id, so replacing a file updates every page"]],
                    ],
                    [B::code(<<<'JSON'
{
  "id": "b7f2…",
  "type": "hero",
  "data": {
    "layout": "centered",
    "headline": "Pages are layouts, not *forms*",
    "primary_label": "Get started",
    "primary_page": "01a1…"
  }
}
JSON, 'json', 'pages.blocks[0]', 'One block as it is stored: its type and its data.')],
                ], '1-1', 'xl', 'start'),
            ], 'white', 'xl', 'wide'),
            B::band([
                B::heading('One set of tools, three ways in', 'Editors, the assistant and MCP', 'The canvas, the in-panel assistant and MCP clients all change content through the same operations, checked against the same permissions, and each change is recorded as a revision.', 'md'),
                B::spacer(),
                ['type' => 'steps', 'data' => ['layout' => 'horizontal', 'items' => [
                    ['title' => 'Someone asks', 'body' => 'An editor drags a block, or asks the assistant, or Claude calls add_block over MCP.'],
                    ['title' => 'Aurora checks', 'body' => 'The person\'s role decides whether the change is allowed, whoever is doing the typing.'],
                    ['title' => 'Content changes', 'body' => 'The page is saved as a new revision, with the person and the channel (canvas, assistant or MCP).'],
                    ['title' => 'Everyone sees it', 'body' => 'Open editors reload, reviewers are notified, and the activity log shows what happened.'],
                ]]],
            ], 'muted', 'xl', 'wide'),
            B::band([
                B::heading('Extending Aurora', 'Hooks', 'What your app registers, and where.', 'md'),
                B::spacer('sm'),
                B::code(<<<'PHP'
use Aurora\Blocks\Blocks;
use Aurora\Support\SettingsTabs;

public function boot(): void
{
    // The blocks editors can use, in palette order.
    Blocks::register([HeroBlock::class, CardBlock::class, CodeSampleBlock::class]);

    // Settings your templates read, kept per site.
    SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))->schema([
        TextInput::make('footer_note'),
    ]), siteKeys: ['footer_note']);
}
PHP, 'php', 'app/Providers/AppServiceProvider.php'),
                B::spacer(),
                B::buttons([
                    B::button('Write your first block', $this->id('creating-blocks'), 'primary', icon: 'arrow-right'),
                    B::button('All docs', $this->id('docs'), 'link'),
                ]),
            ], 'white', 'xl', 'wide'),
        ];
    }

    /* ── Docs ─────────────────────────────────────────────────────────── */

    /**
     * The docs, in reading order: slug => [title, summary].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const DOCS = [
        'getting-started' => ['Getting started', 'Install Aurora into a Laravel app and sign in for the first time.'],
        'creating-blocks' => ['Creating blocks', 'Write a block: a PHP class, a Blade view and one line to register it.'],
        'website-settings' => ['Website settings', 'Give your templates settings editors can change, per site.'],
        'translations' => ['Languages & translations', 'Content in every language, and every label in the admin.'],
        'ai-and-mcp' => ['AI assistant & MCP', 'Switch on the assistant and connect Claude and other apps.'],
        'security' => ['Roles & security', 'Roles, permissions, passkeys and two-factor sign-in.'],
    ];

    protected function blocksForDocs(): array
    {
        return [
            B::band([
                B::heading('Documentation', 'Docs', 'Everything you need to install Aurora, build a website on it and run it with your team. Start at the top, or jump to what you need.', 'xl', 'left', 'h1'),
            ], 'brand-soft', 'lg', 'wide'),
            B::band([
                B::grid(array_map(
                    fn (string $slug, array $doc): array => B::card($doc[0], $doc[1], null, null, $this->id($slug), 'bordered', 'Read'),
                    array_keys(self::DOCS),
                    self::DOCS,
                ), 3),
            ], 'white', 'xl', 'wide'),
            B::band([
                B::heading('Reference', null, 'How Aurora fits together, and every block it comes with.', 'md'),
                B::spacer('sm'),
                B::grid([
                    B::card('Architecture', 'The package and your app, the stack, the content model and the hooks.', null, null, $this->id('architecture'), 'bordered', 'Read'),
                    B::card('Component library', 'Every block editors can use, on one page.', null, null, $this->id('components'), 'bordered', 'Open'),
                ], 2),
            ], 'white', 'lg', 'wide'),
            B::band([
                B::columns([
                    [B::heading('Prefer to learn by doing?', 'Guides', 'Step-by-step how-tos: build a landing page, let Claude draft one, add your own block.', 'md')],
                    [B::buttons([B::button('Browse the guides', $this->id('guides'), 'primary', icon: 'arrow-right')], 'end')],
                ], '2-1'),
            ], 'muted', 'lg', 'wide'),
        ];
    }

    /**
     * A docs page: its title and summary, the content, and the way to the next one.
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    protected function doc(string $slug, array $content): array
    {
        $slugs = array_keys(self::DOCS);
        $next = $slugs[array_search($slug, $slugs, true) + 1] ?? null;

        return [
            B::band([
                B::heading(self::DOCS[$slug][0], 'Docs', self::DOCS[$slug][1], 'xl', 'left', 'h1'),
            ], 'brand-soft', 'md', 'narrow'),
            B::band($content, 'white', 'lg', 'narrow'),
            B::band([
                B::columns([
                    [$next !== null
                        ? B::card('Next: '.self::DOCS[$next][0], self::DOCS[$next][1], null, null, $this->id($next), 'bordered', 'Continue')
                        : B::card('You made it to the end', 'Put it into practice with the guides.', null, null, $this->id('guides'), 'bordered', 'Browse the guides')],
                    [B::card('All docs', 'Back to the overview.', null, null, $this->id('docs'), 'bordered', 'Open')],
                ], '1-1', 'lg', 'stretch'),
            ], 'muted', 'lg', 'narrow'),
        ];
    }

    protected function blocksForGettingStarted(): array
    {
        return $this->doc('getting-started', [
            B::rich('<h2>What you need</h2><ul><li>PHP 8.3 or newer and a Laravel 13 application</li><li>A database Laravel supports (SQLite is fine to start with)</li><li>Node.js, to build the admin theme</li></ul><h2>Install the package</h2><p>Add Aurora with Composer, then run the installer. It asks a few questions (the site name, its language, your admin account) and sets up the database, roles and permissions.</p>'),
            B::code("composer require aurora/cms\nphp artisan aurora:install", 'bash'),
            ['type' => 'alert', 'data' => ['variant' => 'info', 'title' => 'Want something to click on first?', 'body' => 'Run the installer with --demo to get this very website: its pages, menus, media and demo accounts for each role.']],
            B::rich('<h2>Sign in</h2><p>Open <code>/admin</code> and sign in with the account the installer created. You land on the dashboard: recent changes, pages waiting for review and the newest form submissions.</p>'),
            B::image('demo/screen-dashboard.jpg', 'The Aurora dashboard', null, '16/9', true),
            B::rich('<h2>Draw your pages</h2><p>Each page picks a layout (Standard, Landing page, Minimal, or one of your own under Pages › More › Layouts), and each layout is a Blade view. The installer publishes Aurora\'s to <code>resources/views/vendor/aurora/layouts</code>: make them yours there. A view gets the page, and <code>&lt;x-aurora::blocks&gt;</code> renders its blocks in the language being shown; everything around them (your header, footer and styles) is yours.</p>'),
            B::code(<<<'BLADE'
<x-layouts.site :title="$page->seoTitle()">
    <x-aurora::blocks :page="$page" />
</x-layouts.site>
BLADE, 'blade', 'resources/views/vendor/aurora/layouts/standard.blade.php', 'The visual editor shows pages with these views too, so editors see your real design.'),
            B::rich('<h2>Configure</h2><p>Publish the config file to change the CMS\'s name, its admin theme and more:</p>'),
            B::code('php artisan vendor:publish --tag=aurora-config', 'bash'),
            B::code(<<<'PHP'
return [
    'name' => env('AURORA_NAME', 'Aurora CMS'),
];
PHP, 'php', 'config/aurora.php'),
        ]);
    }

    protected function blocksForCreatingBlocks(): array
    {
        return $this->doc('creating-blocks', [
            B::rich('<p>A block is two files: a class that describes it (its fields, defaults and what the AI should know) and a Blade view that draws it. The same view renders on your website, in the visual editor and in previews. <code>php artisan aurora:make-block Quote</code> creates both, ready to fill in.</p><h2>1. The class</h2>'),
            B::code(<<<'PHP'
namespace App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\TextInput;

class QuoteBlock extends Block
{
    public static function type(): string
    {
        return 'quote';
    }

    public static function label(): string
    {
        return __('Quote');
    }

    public static function description(): string
    {
        return __('A short quote with who said it.');
    }

    public static function defaults(): array
    {
        return ['quote' => 'Simple things should be simple.', 'author' => 'Alan Kay'];
    }

    public static function editables(): array
    {
        return ['quote' => Editable::text()->multiline()];
    }

    public static function schema(): array
    {
        return [
            TextInput::make('quote')->label(__('Quote')),
            TextInput::make('author')->label(__('Who said it')),
        ];
    }
}
PHP, 'php', 'app/Blocks/QuoteBlock.php'),
            B::rich('<h2>2. The view</h2><p>An anonymous component named after the block\'s type. Mark text editors can change on the canvas with <code>@editable</code>.</p>'),
            B::code(<<<'BLADE'
@props(['data' => []])

<figure class="rounded-2xl bg-zinc-50 p-8">
    <blockquote class="text-2xl font-semibold" @editable('quote')>{{ $data['quote'] ?? '' }}</blockquote>
    <figcaption class="mt-4 text-zinc-500">{{ $data['author'] ?? '' }}</figcaption>
</figure>
BLADE, 'blade', 'resources/views/components/blocks/quote.blade.php'),
            B::rich('<h2>3. Its place in the palette</h2><p>Blocks in <code>app/Blocks</code> are found on their own: there is nothing to register. To choose where they sit in the block palette, list them in your service provider; the rest follow.</p>'),
            B::code(<<<'PHP'
use Aurora\Blocks\Blocks;

public function boot(): void
{
    Blocks::register([
        HeroBlock::class,
        QuoteBlock::class,
    ]);
}
PHP, 'php', 'app/Providers/AppServiceProvider.php'),
            ['type' => 'alert', 'data' => ['variant' => 'success', 'title' => 'That is all', 'body' => 'The block is now in the editor\'s palette, in the AI assistant\'s and MCP tools\' catalogue, and its text fields can be translated.']],
            B::rich('<h2>Blocks that hold blocks</h2><p>A section, a grid or a row of buttons implements <code>Aurora\Blocks\Contracts\Container</code> and draws each slot with <code>Blocks::slot(\'name\')</code>. Use <code>Blocks::isEditing()</code> in a view to skip animations or show placeholders while someone is editing.</p>'),
        ]);
    }

    protected function blocksForWebsiteSettings(): array
    {
        return $this->doc('website-settings', [
            B::rich('<p>Aurora\'s Settings page holds the CMS\'s own settings: the site name, colours, languages, sites and the AI assistant. Your templates usually need a few more (a button in the header, a note in the footer). Register them as a tab of your own.</p>'),
            B::code(<<<'PHP'
use Aurora\Support\SettingsTabs;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;

SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))
    ->icon('phosphor-rows')
    ->schema([
        TextInput::make('footer_note')->label(__('Footer note')),
    ]), siteKeys: ['footer_note']);
PHP, 'php', 'app/Providers/AppServiceProvider.php'),
            B::rich('<p>Keys listed in <code>siteKeys</code> are kept per site, so each of your websites can say something different. Read them anywhere:</p>'),
            B::code("<p>{{ \\Aurora\\Models\\Setting::get('footer_note') }}</p>", 'blade', 'resources/views/components/site/footer.blade.php'),
            ['type' => 'alert', 'data' => ['variant' => 'info', 'title' => 'This site does the same', 'body' => 'The Header and Footer tabs under Settings are this website\'s own, registered exactly like this.']],
            B::image('demo/screen-settings.jpg', 'Site settings with the website\'s own Header and Footer tabs', null, '16/9', true),
        ]);
    }

    protected function blocksForTranslations(): array
    {
        return $this->doc('translations', [
            B::rich('<h2>Content</h2><p>Add a language under Settings → Languages and every page, menu item, form and media description gets a version in it. Each page has its own address per language (<code>/about-us</code> and <code>/nb/om-oss</code>), and anything not translated yet shows the default language\'s text.</p><p>In the visual editor, switch language in the toolbar and type over the text. The editor shows how many blocks are left to translate, and the AI translator can fill them in for you to check.</p><h2>Labels</h2><p>Every label in the admin and on your website is in Administration → Translations, where people can change the wording without a deploy. The lang files are the starting point:</p>'),
            B::code("php artisan aurora:sync-translations\nphp artisan aurora:import-translations\nphp artisan aurora:export-translations", 'bash', null, 'Sync adds new labels from your code, import loads the lang files, export writes edited labels back so they can be committed.'),
        ]);
    }

    protected function blocksForAiAndMcp(): array
    {
        return $this->doc('ai-and-mcp', [
            B::rich('<h2>The assistant in the admin</h2><p>Under Settings → AI Assistant, choose a provider and a model and save an API key. People whose role has the "Use the AI assistant" permission get a chat in the corner of the admin (or press <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>K</kbd>). It can read and change content with exactly their permissions.</p><h2>Claude and other apps, over MCP</h2><p>Aurora is an MCP server. In Claude, add a custom connector with your site\'s MCP address; Claude signs you in to Aurora and asks you to approve. No keys to copy.</p>'),
            B::code('https://your-site.example/mcp', 'text', null, 'The address to give Claude Desktop, claude.ai or any MCP client.'),
            B::rich('<p>For a local agent, run the server over standard input and output:</p>'),
            B::code('php artisan mcp:start cms', 'bash'),
            B::rich('<h2>What they can do</h2><p>Read the site and its block catalogue, create and update pages, add, move and remove blocks, restore revisions, manage menus, translate pages and upload images. Each call is checked against the person\'s role and written to the AI activity log, with a revision to undo it.</p>'),
            ['type' => 'alert', 'data' => ['variant' => 'brand', 'title' => 'Nobody gets more power by asking a machine', 'body' => 'An author\'s assistant can draft pages and send them for review. Only an editor\'s can publish.']],
        ]);
    }

    protected function blocksForSecurity(): array
    {
        return $this->doc('security', [
            B::rich('<h2>Roles</h2><p>Aurora comes with four roles (Super Admin, Editor, Author and Viewer) and lets you make your own from a list of permissions. Nobody can grant a permission or a role they don\'t hold themselves, and nobody can change their own roles.</p><h2>Signing in</h2><ul><li><strong>Passkeys</strong>: sign in with a fingerprint, face, screen lock or security key.</li><li><strong>Authenticator apps</strong>: as many as you like, with recovery codes for a lost phone.</li><li><strong>Requirements</strong>: under Settings → Security, require an authenticator app or a passkey for everyone. People without one are asked to add it before anything else.</li></ul><h2>Keeping track</h2><p>Every change to a page is a revision with a name on it, AI actions have their own activity log, and people who manage users can view the admin as someone else to see exactly what they see.</p>'),
            B::image('demo/screen-security.jpg', 'Security settings: require two-factor sign-in or passkeys for everyone', null, '16/9', true),
            B::code(<<<'PHP'
// In your own code, Aurora's permissions are plain Laravel gates.
if ($request->user()->can('publish Page')) {
    // …
}
PHP, 'php', null, 'Roles and permissions are spatie/laravel-permission, checked through Laravel\'s policies.'),
        ]);
    }

    /* ── Guides ───────────────────────────────────────────────────────── */

    protected function blocksForGuides(): array
    {
        return [
            B::band([
                B::heading('Guides', 'Learn by doing', 'Short, practical how-tos for editors and developers. Each one takes about ten minutes.', 'xl', 'left', 'h1'),
            ], 'brand-soft', 'lg', 'wide'),
            B::band([
                ['type' => 'page-list', 'data' => ['source' => 'selected', 'page_ids' => [$this->id('build-a-landing-page'), $this->id('let-claude-draft-a-page'), $this->id('add-your-own-block')], 'link_label' => 'Read the guide']],
            ], 'white', 'xl', 'wide'),
            B::band([
                ['type' => 'cta', 'data' => ['style' => 'light', 'title' => 'Looking for the reference?', 'text' => 'The docs explain every part of Aurora, in order.', 'button_label' => 'Open the docs', 'button_type' => 'page', 'button_page' => $this->id('docs')]],
            ], 'muted', 'lg', 'wide'),
        ];
    }

    protected function blocksForBuildALandingPage(): array
    {
        return $this->guide('build-a-landing-page', 'Build a landing page in the *visual editor*', 'demo/screen-editor.jpg', [
            B::rich('<p>This guide walks through a campaign page from an empty canvas to published, using only the visual editor. You need a role that can create pages (an Editor or an Author).</p>'),
            ['type' => 'steps', 'data' => ['layout' => 'vertical', 'items' => [
                ['title' => 'Create the page', 'body' => 'Under Pages, choose New page, give it a title and pick the Landing layout. The address is made from the title; change it if you like.'],
                ['title' => 'Open the visual editor', 'body' => 'Choose Design. The canvas shows the page with your website\'s real styles.'],
                ['title' => 'Add a hero', 'body' => 'Drag a Hero block from the palette onto the canvas. Click the headline and type. Wrap a word in *asterisks* to give it the brand highlight.'],
                ['title' => 'Build a section', 'body' => 'Drop a Section band below it, choose two columns, and drag an Image into one and a Heading and a Button group into the other.'],
                ['title' => 'Check every screen size', 'body' => 'Switch between desktop, tablet and phone in the toolbar. Columns stack on small screens.'],
                ['title' => 'Publish, or send for review', 'body' => 'Save, then publish or schedule it. Authors send it for review instead; editors are notified.'],
            ]]],
            ['type' => 'alert', 'data' => ['variant' => 'info', 'title' => 'Changed your mind?', 'body' => 'Every save is a revision. Open the page\'s history to compare versions or restore an earlier one.']],
        ]);
    }

    protected function blocksForLetClaudeDraftAPage(): array
    {
        return $this->guide('let-claude-draft-a-page', 'Let *Claude* draft a page for you', 'demo/screen-dashboard.jpg', [
            B::rich('<p>Connect Claude to Aurora over MCP and ask it for a first draft. It works with your permissions, and everything it does is a revision you can undo.</p><h2>1. Connect</h2><p>In Claude, add a custom connector with your site\'s MCP address. Claude opens Aurora\'s sign-in; sign in and approve.</p>'),
            B::code('https://your-site.example/mcp', 'text'),
            B::rich('<h2>2. Ask for a draft</h2><p>Be specific about the audience, the sections you want and the tone. Claude reads the block catalogue first, so it knows what it can build with.</p>'),
            ['type' => 'testimonial', 'data' => ['quote' => 'Draft a landing page for our spring webinar series: a hero, three cards for the sessions, a short FAQ and a call to action to sign up. Keep it as a draft.', 'name' => 'You', 'role' => 'in Claude', 'style' => 'card']],
            B::rich('<h2>3. Review it in the editor</h2><p>Open the page in the visual editor. Claude\'s changes show in the history with a sparkle and your name. Adjust anything, then publish when you are happy. If you are an Author, Claude can only send it for review, just like you.</p>'),
        ]);
    }

    protected function blocksForAddYourOwnBlock(): array
    {
        return $this->guide('add-your-own-block', 'Add your *own block*', 'demo/workspace.jpg', [
            B::rich('<p>The block used for code on these pages is a good example: a file name, a language, the code and a copy button. Here is how it was made.</p><h2>The class</h2><p>Its fields are a language picker, a file name and Filament\'s code editor. The code field isn\'t text to translate, so translators never see it; the caption is.</p>'),
            B::code(<<<'PHP'
class CodeSampleBlock extends Block
{
    public static function type(): string
    {
        return 'code-sample';
    }

    public static function schema(): array
    {
        return [
            Select::make('language')->options(['php' => 'PHP', 'blade' => 'Blade', 'bash' => 'Terminal']),
            TextInput::make('filename'),
            CodeEditor::make('code'),
            TextInput::make('caption'),
        ];
    }
}
PHP, 'php', 'nordlys/src/CodeSampleBlock.php'),
            B::rich('<h2>The view</h2><p>It always draws on a dark panel, whatever the band behind it, and copies the code exactly as written.</p>'),
            B::code(<<<'BLADE'
<figure class="rounded-2xl bg-zinc-950 text-zinc-100">
    <figcaption>{{ $data['filename'] ?? '' }}</figcaption>
    <pre><code>{{ $data['code'] ?? '' }}</code></pre>
</figure>
BLADE, 'blade', 'nordlys/resources/views/components/blocks/code-sample.blade.php'),
            B::rich('<h2>Where it lives</h2><p>It comes with Aurora\'s block library (<code>aurora/nordlys</code>), which registers it: it is in the palette, the AI catalogue and the component library as soon as the package is installed.</p>'),
            B::buttons([B::button('Read: Creating blocks', $this->id('creating-blocks'), 'outline', icon: 'book-open')]),
        ]);
    }

    protected function blocksForRunSeveralSites(): array
    {
        return $this->guide('run-several-sites', 'Run *several websites* from one admin', 'demo/screen-settings.jpg', [
            B::rich('<p>Aurora can serve more than one website, each on its own domain, from the same admin. Pages, menus, forms and settings belong to a site; people work on the sites they are given.</p><h2>Add a site</h2><p>Under Settings → Sites, add the new site with its domain and language. The site switcher appears at the top of the sidebar.</p>'),
            ['type' => 'alert', 'data' => ['variant' => 'warning', 'title' => 'Draft', 'body' => 'Still to write: giving people access to a site, and sharing media between sites.']],
        ]);
    }

    protected function blocksForUpgradingFromOtherCmses(): array
    {
        return $this->guide('upgrading-from-other-cmses', 'Moving to Aurora from *another CMS*', 'demo/coffee.jpg', [
            B::rich('<p>Notes for teams moving content from a form-based CMS. Work in progress.</p>'),
        ]);
    }

    /**
     * A guide: its title over an image, the steps, and the other guides.
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    protected function guide(string $current, string $title, string $image, array $content): array
    {
        return [
            B::band([
                B::heading($title, 'Guide', null, 'xl', 'left', 'h1'),
                B::spacer(),
                B::image($image, '', null, '16/9', true),
            ], 'white', 'lg', 'narrow'),
            B::band($content, 'white', 'md', 'narrow'),
            B::band([
                B::heading('More guides', null, null, 'md'),
                B::spacer('sm'),
                ['type' => 'page-list', 'data' => ['source' => 'selected', 'page_ids' => array_values(array_filter([
                    $this->id('build-a-landing-page'), $this->id('let-claude-draft-a-page'), $this->id('add-your-own-block'),
                ], fn (?string $id) => $id !== $this->ids[$current])), 'link_label' => 'Read the guide']],
            ], 'muted', 'lg', 'wide'),
        ];
    }

    /* ── Everything else ──────────────────────────────────────────────── */

    protected function blocksForReleaseNotes(): array
    {
        return [
            B::band([
                B::heading('Release notes', 'What\'s new', 'What changed in each version of Aurora.', 'xl', 'left', 'h1'),
            ], 'brand-soft', 'lg', 'narrow'),
            B::band([
                ['type' => 'tabs', 'data' => ['style' => 'underline', 'items' => [
                    ['label' => '1.0', 'title' => 'Aurora 1.0', 'body' => "Aurora is a package: install it into any Laravel site with Composer.\nTwo-factor sign-in with authenticator apps, passkeys, and switches to require them.\nWebsites register their own blocks and settings tabs.\nEmails in Aurora's look, and a code sample block for docs."],
                    ['label' => '0.9', 'title' => 'Aurora 0.9', 'body' => "Menus nest to any depth, with titles per language.\nThe media library's folders open from the breadcrumb.\nThe AI assistant can be switched off without losing its key."],
                    ['label' => '0.8', 'title' => 'Aurora 0.8', 'body' => "Several websites from one admin.\nNotifications in the admin and by email.\nThe command palette."],
                ]]],
            ], 'white', 'lg', 'narrow'),
        ];
    }

    protected function blocksForComponents(): array
    {
        return [
            B::band([
                B::heading('Component library', 'Every block, one page', 'Every block editors can drag onto a page. Open this page in the visual editor to see how it is put together, or ask the assistant to build something new from them.', 'xl', 'left', 'h1'),
            ], 'brand-soft', 'lg', 'wide'),
            B::band([
                B::heading('Layout', 'Grids that nest', 'Section bands hold columns, columns hold grids, grids hold cards. As deep as the layout needs.', 'md'),
                B::spacer('sm'),
                B::columns([
                    [B::card('Column one', 'A two-column section with a card in each column.', null, 'section', null, 'bordered')],
                    [B::columns([[B::card('Nested', 'A section inside a column.', null, null, null, 'bordered')], [B::card('Columns', 'Still responsive.', null, null, null, 'bordered')]], '1-1', 'md', 'stretch')],
                ], '1-2', 'lg', 'stretch'),
            ], 'white', 'lg', 'wide'),
            B::band([
                B::heading('Typography', null, null, 'md'),
                B::spacer('sm'),
                B::columns([
                    [
                        B::heading('Display heading with a *highlight*', 'Eyebrow', 'An introduction paragraph sits beneath the heading.', 'xl', 'left', 'h3'),
                        B::text('A lead paragraph is larger and darker. Use it for the first paragraph after a heading.', 'lead'),
                        B::text('A regular paragraph. Editors type directly into it on the canvas, and line breaks are kept.'),
                    ],
                    [B::rich('<h3>Rich text</h3><p>Rich text supports <strong>bold</strong>, <em>italic</em>, <a href="#">links</a> and more.</p><ul><li>Bullet lists</li><li>Numbered lists</li></ul><blockquote>And quotes, for good measure.</blockquote>')],
                ], '1-1', 'xl', 'start'),
            ], 'white', 'lg', 'wide'),
            B::band([
                B::heading('Code', 'For docs and guides', 'Shown with its file name and a copy button, never run.', 'md'),
                B::spacer('sm'),
                B::columns([
                    [B::code('php artisan aurora:install --demo', 'bash')],
                    [B::code("Blocks::register([\n    HeroBlock::class,\n]);", 'php', 'app/Providers/AppServiceProvider.php')],
                ], '1-1', 'lg', 'start'),
            ], 'muted', 'lg', 'wide'),
            B::band([
                B::heading('Buttons', null, null, 'md'),
                B::spacer('sm'),
                B::buttons([
                    B::button('Primary', $this->id('home')),
                    B::button('Secondary', $this->id('home'), 'secondary'),
                    B::button('Outline', $this->id('home'), 'outline'),
                    B::button('Ghost', $this->id('home'), 'ghost'),
                    B::button('Text link', $this->id('home'), 'link'),
                    B::button('With icon', $this->id('home'), 'primary', icon: 'rocket-launch'),
                ]),
            ], 'white', 'md', 'wide'),
            B::band([
                B::heading('Callouts', null, null, 'md'),
                B::spacer('sm'),
                B::grid([
                    ['type' => 'alert', 'data' => ['variant' => 'info', 'title' => 'Info', 'body' => 'Neutral information.']],
                    ['type' => 'alert', 'data' => ['variant' => 'success', 'title' => 'Success', 'body' => 'Something went well.']],
                    ['type' => 'alert', 'data' => ['variant' => 'warning', 'title' => 'Warning', 'body' => 'Mind the gap.']],
                    ['type' => 'alert', 'data' => ['variant' => 'danger', 'title' => 'Danger', 'body' => 'This cannot be undone.']],
                ], 2),
            ], 'muted', 'lg', 'wide'),
            B::band([
                B::heading('Features & statistics', null, null, 'md'),
                B::spacer('sm'),
                B::grid([
                    B::feature('bolt', 'Soft', 'Tinted icon tile.'),
                    B::feature('rocket-launch', 'Solid', 'Filled icon tile.', 'solid'),
                    B::feature('cube-transparent', 'Outline', 'Outlined icon tile.', 'outline'),
                    B::feature('squares-2x2', 'Card', 'The whole feature in a card.', 'card', 'accent'),
                ], 4),
                B::spacer('lg'),
                B::grid([B::stat('25', 'Blocks to build with', '+'), B::stat('2', 'Languages in the admin'), B::stat('1', 'Line to register a block')], 3),
            ], 'dark', 'lg', 'wide'),
            B::band([
                B::heading('Interactive', null, null, 'md'),
                B::spacer('sm'),
                B::columns([
                    [['type' => 'accordion', 'data' => ['first_open' => true, 'faq_schema' => false, 'items' => [
                        ['question' => 'Accordion item one', 'answer' => 'Click a question to expand it. On the canvas, click the text to edit it.'],
                        ['question' => 'Accordion item two', 'answer' => 'Answers keep their line breaks.'],
                    ]]]],
                    [['type' => 'tabs', 'data' => ['style' => 'underline', 'items' => [
                        ['label' => 'First tab', 'title' => 'Tabs', 'body' => 'Switch between panels without leaving the page.'],
                        ['label' => 'Second tab', 'title' => 'Underline style', 'body' => 'Tabs come in pill and underline styles.'],
                    ]]]],
                ], '1-1', 'xl', 'start'),
                B::spacer('lg'),
                ['type' => 'steps', 'data' => ['layout' => 'horizontal', 'items' => [
                    ['title' => 'Steps', 'body' => 'Numbered steps for a process.'],
                    ['title' => 'Horizontal', 'body' => 'Side by side on wide screens.'],
                    ['title' => 'Or vertical', 'body' => 'As in the guides.'],
                ]]],
            ], 'white', 'lg', 'wide'),
            B::band([
                B::heading('Media', null, null, 'md'),
                B::spacer('sm'),
                ['type' => 'gallery', 'data' => ['columns' => '4', 'layout' => 'grid', 'images' => ['demo/fjord.jpg', 'demo/mountains.jpg', 'demo/forest.jpg', 'demo/lake.jpg', 'demo/camp.jpg', 'demo/city.jpg', 'demo/desk.jpg', 'demo/coffee.jpg']]],
            ], 'muted', 'lg', 'wide'),
            B::band([
                B::heading('Pricing', null, null, 'md'),
                B::spacer('sm'),
                B::grid([
                    $this->plan('Starter', '0', 'For trying things out.', "One site\nThe visual editor\nCommunity support", false),
                    $this->plan('Team', '49', 'For a content team.', "Unlimited people\nThe AI assistant and MCP\nSeveral languages", true),
                    $this->plan('Agency', '199', 'For many client sites.', "Unlimited sites\nSingle sign-on\nPriority support", false),
                ], 3),
            ], 'white', 'lg', 'wide'),
            B::band([
                B::heading('Social proof', null, null, 'md'),
                B::spacer('sm'),
                B::testimonial('The large style turns one quote into a statement for the whole section.', 'Hanna Vik', 'Content lead', 'demo/person1.jpg', 'large'),
            ], 'muted', 'lg', 'wide'),
            B::band([
                ['type' => 'cta', 'data' => ['style' => 'brand', 'title' => 'Call to action', 'text' => 'Four styles: aurora, brand, dark and light.', 'button_label' => 'Back to the homepage', 'button_type' => 'page', 'button_page' => $this->id('home')]],
            ], 'white', 'md', 'wide'),
        ];
    }

    /**
     * An example pricing plan for the component library.
     */
    protected function plan(string $name, string $price, string $description, string $features, bool $featured): array
    {
        return ['type' => 'pricing', 'data' => [
            'name' => $name, 'price' => $price, 'currency' => '€', 'period' => '/month', 'description' => $description,
            'features' => array_map(fn (string $text): array => ['text' => $text], explode("\n", $features)), 'featured' => $featured, 'badge' => $featured ? 'Most popular' : '',
            'button_label' => 'Choose '.$name, 'button_type' => 'page', 'button_page' => $this->id('contact'),
        ]];
    }

    protected function blocksForContact(): array
    {
        return [
            B::band([
                B::columns([
                    [
                        B::heading('Talk to us', 'Contact', 'Questions about Aurora, a demo for your team, or help moving from another CMS: tell us what you need and we will get back to you within a working day.', 'xl', 'left', 'h1'),
                        ['type' => 'list', 'data' => ['marker' => 'check', 'items' => "A walkthrough of the editor for your team\nAdvice on moving your content across\nHelp writing your first blocks"]],
                    ],
                    [['type' => 'form', 'data' => ['form_id' => $this->contactForm?->id, 'style' => 'card']]],
                ], '1-1', 'xl', 'start'),
            ], 'white', 'xl', 'wide'),
        ];
    }

    protected function blocksForLegal(): array
    {
        return [
            B::band([
                B::heading('Legal', null, 'The terms and policies that apply to this website.', 'xl', 'left', 'h1'),
                B::spacer(),
                B::grid([
                    B::card('Privacy policy', 'How we handle your data.', null, null, $this->id('privacy'), 'bordered', 'Read'),
                    B::card('Terms of service', 'The terms that apply to this website.', null, null, $this->id('terms'), 'bordered', 'Read'),
                ], 2),
            ], 'white', 'lg', 'narrow'),
        ];
    }

    protected function legalPage(string $slug): array
    {
        $title = $slug === 'privacy' ? 'Privacy policy' : 'Terms of service';

        return [
            B::band([
                B::heading($title, 'Legal', __('Last updated :date.', ['date' => now()->format('F j, Y')], 'en'), 'lg', 'left', 'h1'),
                B::spacer('sm'),
                B::rich('<p>This is demo content. On a real site this page would hold the full legal text, kept by your legal team in the same editor as everything else.</p><h2>1. Who we are</h2><p>The Aurora team, the makers of Aurora CMS.</p><h2>2. What we collect</h2><p>Only what we need to answer you, and never more.</p><h2>3. Your rights</h2><p>You can ask us for a copy of your data, or to delete it, at any time.</p>'),
            ], 'white', 'lg', 'narrow'),
        ];
    }
}
