---
paths:
  - 'packages/cms/src/Filament/Forms/Components/**'
---

# Form Components

## Custom form fields
Build a custom form field as a `Field` (or `ViewField`) subclass with its own Blade view under `aurora::filament.forms.components`. Expose the methods its view calls with `#[ExposedLivewireMethod]` (called via `callSchemaComponentMethod`), and register the field's dialogs with `registerActions()` in `setUp()`.
