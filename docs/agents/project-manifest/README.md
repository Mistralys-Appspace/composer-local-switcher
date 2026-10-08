# Project Manifest — Composer Local Switcher

> **Package:** `mistralys/composer-local-switcher`
> **Version:** 3.0.0
> **License:** MIT
> **PHP:** >=8.4

PHP library that switches a project's `composer.json` between production and local development configurations, replacing packages with symlinked path repositories for local package development.

## v3 model summary

`composer.json` is the single source of truth at all times. `composer/composer-prod.json`/`.lock` are no
longer a second, committed baseline to hand-edit and reconcile — they are a transient snapshot created on
a PROD/INITIAL→DEV switch and deleted again once a DEV→PROD switch has applied them back. A three-way
revert (`Utils\DevConfigTransformer::revert()`) carries DEV-time edits (`composer require`/`remove`,
script/autoload changes, added repositories) back into production automatically, restoring only the
managed (local package) entries to their snapshot value. Every `composer switch-*` command finishes in a
single step: it previews the `composer.json` change set, asks for confirmation when that set is non-empty
(skippable with `-- --yes` for agents and CI), and then runs the Composer command it planned itself —
there is nothing left to run by hand, and the standalone `reconcile()`/`verify()` pair and their
`switch-reconcile`/`switch-verify-config` commands no longer exist.

## Sections

| Section | File | Description |
|---|---|---|
| Tech Stack & Patterns | [tech-stack.md](tech-stack.md) | Runtime, dependencies, build tools, architectural patterns. |
| File Tree | [file-tree.md](file-tree.md) | Annotated directory structure. |
| Public API Surface | [api-surface.md](api-surface.md) | Public constructors, properties, and method signatures. |
| Key Data Flows | [data-flows.md](data-flows.md) | Main interaction paths through the system. |
| Constraints & Conventions | [constraints.md](constraints.md) | Rules, conventions, and non-obvious gotchas. |
| Switching Decision Table | [switching-decision-table.md](switching-decision-table.md) | Current state × intended action → command → PHP call → file effects → message codes. |
