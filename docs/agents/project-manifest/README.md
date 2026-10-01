# Project Manifest — Composer Local Switcher

> **Package:** `mistralys/composer-local-switcher`
> **Version:** 2.0.0
> **License:** MIT
> **PHP:** >=8.4

PHP library that switches a project's `composer.json` between production and local development configurations, replacing packages with symlinked path repositories for local package development.

## Sections

| Section | File | Description |
|---|---|---|
| Tech Stack & Patterns | [tech-stack.md](tech-stack.md) | Runtime, dependencies, build tools, architectural patterns. |
| File Tree | [file-tree.md](file-tree.md) | Annotated directory structure. |
| Public API Surface | [api-surface.md](api-surface.md) | Public constructors, properties, and method signatures. |
| Key Data Flows | [data-flows.md](data-flows.md) | Main interaction paths through the system. |
| Constraints & Conventions | [constraints.md](constraints.md) | Rules, conventions, and non-obvious gotchas. |
| Switching Decision Table | [switching-decision-table.md](switching-decision-table.md) | Current state × intended action → command → PHP call → file effects → message codes. |
