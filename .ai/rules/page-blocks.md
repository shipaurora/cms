---
paths:
  - 'aurora-docs/app/Blocks/**'
---

# Page Blocks

## Page blocks: class, Blade component, registration
Create blocks with `php artisan aurora:make-block`. Each extends `Aurora\Blocks\Block` and renders through a matching anonymous component at `resources/views/components/blocks/{type}.blade.php`. The site's blocks in `aurora.block_path` (app/Blocks, namespace `App\Blocks`) are discovered on their own; `App\Providers\BlocksServiceProvider` lists them in palette order.
