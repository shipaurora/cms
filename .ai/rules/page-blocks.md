---
paths:
  - 'workbench/app/Blocks/**'
---

# Page Blocks

## Page blocks: class, Blade component, registration
Each extends `Aurora\Blocks\Block` and renders through a matching anonymous component at `workbench/resources/views/components/blocks/{type}.blade.php`. The workbench's blocks (namespace `Workbench\App\Blocks`) are a copy of the docs site's: change a block there first, then copy it here. `WorkbenchServiceProvider` lists them in palette order.
