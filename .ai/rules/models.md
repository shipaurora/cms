---
paths:
  - 'src/Models/**'
---

# Models

## Model conventions
Declare mass assignment with the `#[Fillable]` attribute, use `HasUuids`, write query scopes as `#[Scope]` methods and lifecycle hooks as closures in `booted()`. Translatable models override `getLocale()` to follow `ContentLocale`. Site-owned content uses the `BelongsToSite` trait.

## UUID keys and string enums
Models use `HasUuids`; enum columns are strings cast to a PHP enum.
