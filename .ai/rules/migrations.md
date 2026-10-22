---
paths:
  - 'database/migrations/**'
---

# Migrations

## UUID keys and string enums
Primary keys are UUIDs; reference other tables with `foreignUuid()->constrained()`. Store enum values as strings and cast them to a PHP enum on the model.

## Edit migrations in place while the demo is not live
The CMS has no production data yet: change the original migration instead of adding a new one, and rebuild with `migrate:fresh --seed` (or `aurora:install`). No backwards-compatibility shims.
