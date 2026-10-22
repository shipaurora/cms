---
paths:
  - 'src/Providers/**'
---

# Providers

## Register overrides after boot
Anything that must win over other packages' setup (Livewire component overrides, config, routes, translation fallbacks) goes in `$this->app->booted()`: Aurora's provider boots before the packages it builds on.
