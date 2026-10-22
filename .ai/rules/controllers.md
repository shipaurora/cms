---
paths:
  - 'src/Http/Controllers/**'
---

# Controllers

## Single-action controllers
New endpoints get a single-action `__invoke` controller with a short docblock saying why the endpoint exists. `PageController` (the public site renderer) is the only multi-action controller.

## Controllers are plain classes
Don't extend the base `Controller`; write controllers as plain classes (extend a vendor controller only to override it).
