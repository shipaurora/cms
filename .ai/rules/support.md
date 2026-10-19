---
paths:
  - 'packages/cms/src/Support/**'
---

# Support

## Static helper classes, no service layer
Domain logic lives in static helper classes in packages/cms/src/Support (namespace `Aurora\Support`), called as `ClassName::method()`. Don't add Actions, Services, repositories, DTO classes or events/listeners: pass arrays documented with array shapes.

## Public content events are the one exception
`Aurora\Events` holds the events Aurora announces to the app and to webhooks (PageSaved, PagePublished, FormSubmitted…), dispatched where the change happens. Add one there when apps need to react to a new kind of content change; keep Aurora's own logic as direct calls, not listener classes (ActivityLog's closures on Eloquent's model events are the only listening Aurora does).
