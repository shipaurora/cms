---
paths:
  - 'src/Filament/**'
---

# Filament

## Filament admin conventions
Save lives in the page header. User-facing text uses English-sentence keys via `__()`, added to both `lang/en.json` and `lang/nb.json` (then `php artisan aurora:import-translations`); no dotted keys or new PHP lang files. Icons are Phosphor only (`phosphor-*`), and every titled section has one. Translatable edit pages use the language switcher; global search results use `SearchThumbnail`.

## Share page behaviour through Concerns traits
Shared resource or page behaviour (labels, breadcrumbs, header save, locale) goes in a trait in `Aurora\Filament\Concerns`; check the existing traits before writing it again.

## Sentence-case, plain UI copy
Write labels in sentence case, and helper texts and descriptions as short everyday sentences that say what happens, without jargon.

## Page addresses on their site's domain
Show a page's address the way the Address field does: on its site's domain, without the scheme (`Str::after(CurrentSite::url($page->path()), '://')`), inside `CurrentSite::using()` for another site's pages.
