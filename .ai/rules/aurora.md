---
paths:
  - 'packages/cms/**'
  - 'packages/borealis/**'
  - 'packages/minimal/**'
  - 'aurora-docs/app/**'
---

# Aurora package

## The CMS is a package, the website is the app
Everything the CMS is (models, Filament, MCP, support classes, migrations, admin views, translations, admin JS) lives in packages/cms under the `Aurora\` namespace, as if it were installed in someone else's Laravel site. Its admin themes (styles, tokens, prebuilt theme) are packages of their own that register with `Aurora\Support\Themes::register()`: packages/borealis (`Aurora\Borealis`, Borealis, the default) and packages/minimal (`Aurora\Minimal`). The website is the docs site in aurora-docs/ (app/, resources/views, routes/web.php, the demo seeders in database/seeders/Demo): its page templates, its blocks (app/Blocks, with the header and footer settings tabs in `App\Providers\BlocksServiceProvider`) and its content, seeded by its own `DatabaseSeeder` (the CMS's installer never seeds a website's content). Never make the package depend on `App\` classes; give the website a hook instead (`Aurora\Blocks\Blocks::register()`, `Aurora\Support\SettingsTabs::register()`, `Aurora\Support\Demo::register()` for demo login's accounts, a config key in packages/cms/config/aurora.php).

## Package resources
Views are `aurora::…` (packages/cms/resources/views); overrides of other packages' views go in packages/cms/resources/views/vendor/{namespace}. Labels go in packages/cms/lang ({locale}.json), not the app's lang/. Use `Aurora\Aurora::path()` for files in the package, never `base_path('packages/cms/…')`.
