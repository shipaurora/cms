---
paths:
  - 'packages/cms/src/Mcp/**'
---

# Tools

## MCP tools extend CmsTool
Every MCP tool extends `CmsTool` and implements `run(Request $request, User $user): Response`, with `#[Name]` and `#[Description]` attributes. Set `protected bool $needsSite = false` only for tools that don't touch site content.

## MCP descriptions are plain English
MCP tool, schema, prompt and resource descriptions are literal English strings for AI clients: don't wrap them in `__()`.
