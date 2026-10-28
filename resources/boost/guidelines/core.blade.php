{{-- Aurora guidelines for AI code assistants --}}

## Aurora CMS

- `shipaurora/cms` is the content management system this site runs on: a Filament admin panel at /admin, pages built from blocks in a visual editor, a media library, forms, menus, translations and AI tools (an in-panel assistant and an MCP server).
- The website is the app's own code: its page layouts (Blade views in `resources/views/vendor/aurora/layouts`, published on install, and layouts of its own in `config('aurora.layout_path')`, `app/Layouts` by default, made with `php artisan make:aurora-layout`), its blocks (in `config('aurora.block_path')`, `app/Blocks` by default, made with `php artisan aurora:make-block`, with views in `resources/views/components/blocks`) and its settings. Aurora's code lives in the package; never edit it in `vendor/`, use the hooks it offers.
- Always activate the `aurora-development` skill when creating or changing blocks, page layouts and their fields (`Layouts::schema()`), the website's settings tabs, the page view, or anything that uses `Aurora\` classes.
- Activate the `aurora-porting` skill when moving an existing website (static HTML, WordPress or another CMS, a Laravel app) onto Aurora: it decides what becomes a layout, a block, a setting or a model of the site's own, and how the content comes across.
- Every block gets a screenshot made from its example data, for the visual editor's block list: `php artisan aurora:block-previews` lists where each goes.
