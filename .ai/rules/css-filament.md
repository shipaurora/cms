---
paths:
  - 'packages/borealis/resources/css/**'
  - 'packages/minimal/resources/css/**'
  - 'packages/cms/resources/css/**'
  - 'packages/cms/resources/js/**'
---

# Css Filament

## Admin styling
The CMS's own admin components are styled once, in packages/cms/resources/css/components.css: layout and behaviour, the same in every theme. The theme packages (packages/borealis, packages/minimal) hold only their look: the CSS files their index.css imports, styling Filament's `fi-*` classes and restyling `cms-*` hooks with their own `--{theme}-*` tokens. A rule every theme would share goes in components.css, never into each theme. CMS views expose `cms-*` class hooks for themes and never set colours inline. Every admin build imports components.css before the theme's index.css, so a theme overrides a component by styling its class again. index.css never references the app, another package or this repository's vendor/ folder. No admin CSS carries the website's styles or styles blocks: the visual editor's canvas loads the website's own stylesheet, scoped to it (`Aurora\Support\CanvasStyles`).

## Rebuild the prebuilt assets after every change
Each theme package ships its admin theme prebuilt in resources/dist, and the CMS its scripts in packages/cms/resources/dist. After changing their CSS or JS (the CMS's components.css included, which every theme compiles in), run `npm run build:aurora` (it builds them all and republishes them with `filament:assets`) and commit the rebuilt dist files; `composer dev` rebuilds them automatically while it runs.
