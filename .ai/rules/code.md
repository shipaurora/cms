---
paths:
  - 'packages/cms/src/**'
  - 'aurora-docs/app/**'
---

# Code

## Start Eloquent queries with ::query()
Begin every Eloquent query or create with `Model::query()` (`Page::query()->where(…)`, `Site::query()->create(…)`); never call `where`, `find` or `create` statically on a model.

## Authorize with Gate::authorize
Enforce authorization with `Gate::authorize()`, not `$this->authorize()` or `can:` middleware; use `$user->can()` only for yes/no checks.

## Resolve services with app() where they're used
Get container services with `app(Foo::class)` at the point of use (`app(BlockRegistries::class)->for('admin')`); don't add constructor-injected services.

## Plain-English why-docblocks
Give classes and non-obvious methods a short plain-English docblock saying what they are for and why. Add `@param`/`@return` only for generic or array-shape types.

## Site and language context helpers
Get the active site, content language and settings through `CurrentSite`, `ContentLocale` and `Setting::get()`. Use `CurrentSite::using()` and `RevisionContext::as()` for work done on behalf of another site or actor.
