---
paths:
  - '**'
---

# Aurora package

## The CMS is a package, the website is the app
This repository is the CMS, shipaurora/cms: everything it is (models, Filament, MCP, support classes, migrations, admin views, translations, admin JS) under the `Aurora\` namespace, installed into other Laravel sites. Its admin themes are packages of their own, in their own repositories, that register with `Aurora\Support\Themes::register()`: shipaurora/borealis (Borealis, the default) and shipaurora/minimal. Websites built on it (the starter app shipaurora/aurora, the docs site) own their page templates, blocks, settings tabs and content, seeded by their own `DatabaseSeeder` (the installer never seeds a website's content). Never make the package depend on `App\` classes; give the website a hook instead (`Aurora\Blocks\Blocks::register()`, `Aurora\Support\SettingsTabs::register()`, `Aurora\Support\Demo::register()` for demo login's accounts, a config key in config/aurora.php). The tests' website is workbench/ (`Workbench\App\`): a copy of the docs site's blocks and settings tabs, registered by `WorkbenchServiceProvider`.

## Package resources
Views are `aurora::…` (resources/views); overrides of other packages' views go in resources/views/vendor/{namespace}. Labels go in lang/{locale}.json. Use `Aurora\Aurora::path()` for files in this package and `Aurora\Aurora::packagePath()` for another installed package's, never `base_path('vendor/…')`.
