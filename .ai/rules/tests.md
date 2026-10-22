---
paths:
  - 'tests/**'
---

# Tests

## Test conventions
PHPUnit classes with `test_` methods named as sentences. Create users with `$this->userWithRole('Editor')` and use Laravel fakes (Storage, Http, etc.) rather than Mockery. Start queries and creates with `Model::query()`, as in the app.

## Tests clean up what they write
Tests that write files (generated code, an .env, uploads built by hand) write them under `sys_get_temp_dir()` where they can, and remove everything they create, files and globally registered state alike, in `tearDown()`.

## Offline, and in parallel
Tests never reach the network: the base TestCase blocks every HTTP request no test faked, so fake them with `Http::fake()`. Run the full suite with `composer test` (ParaTest).
