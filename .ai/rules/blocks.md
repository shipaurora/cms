---
paths:
  - 'workbench/resources/views/components/blocks/**'
---

# Blocks

## Page blocks: class, Blade component, registration
Each block component pairs with an `Aurora\Blocks\Block` subclass in workbench/app/Blocks, registered in palette order by `Workbench\App\Providers\WorkbenchServiceProvider` (a site makes its own with `php artisan aurora:make-block`). Register blocks only through `Aurora\Blocks\Blocks` (never with the page builder package directly).

## Block views use the global helpers
Render block fields with Aurora's global helpers (`inline_text()`, `plain_text()`, `rich_text()`, `shows_field()`, `link_href()`, `link_target()`, `is_editing()`, `block_slot()`, from src/helpers.php) rather than importing `Markup`, `Fields` or `Blocks` in `@php`, and images with `<x-aurora::media>`. A new helper wraps one static method and is named for what it reads, never a bare word a framework or package could also define, and gets a camelCase Blade directive of the same name in `AuroraServiceProvider::registerBladeDirectives()` (never one Blade already has, like `@slot`).

## Blocks are styled by the website's stylesheet alone
Block styles live in the website's CSS (a site's resources/css/app.css), which the visual editor's canvas also loads, scoped to it. Style the page itself (font, colour, background, fonts to load) in that stylesheet through `:root`, `html` and `body`, never with classes on `<body>` or `<link>`s in the layout's head, so the canvas gets it too.
