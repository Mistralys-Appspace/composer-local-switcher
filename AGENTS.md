# AGENTS.md — Composer Local Switcher

> Operating manual for AI agents entering this codebase.
> Read the Project Manifest **before** reading source code.

---

## 1. Project Manifest — Start Here

**Location:** `docs/agents/project-manifest/`

| Document | Description |
|---|---|
| [README.md](docs/agents/project-manifest/README.md) | Project overview, version, and manifest index. |
| [tech-stack.md](docs/agents/project-manifest/tech-stack.md) | Runtime, dependencies, autoloading, build tools, architectural patterns. |
| [file-tree.md](docs/agents/project-manifest/file-tree.md) | Annotated directory structure. |
| [api-surface.md](docs/agents/project-manifest/api-surface.md) | Public classes, constants, constructors, and method signatures. |
| [data-flows.md](docs/agents/project-manifest/data-flows.md) | Switching workflows and file relationships. |
| [constraints.md](docs/agents/project-manifest/constraints.md) | Code style rules, error handling, testing, and workflow conventions. |

### Quick Start Workflow

1. Read `README.md` — understand the library's purpose and scope.
2. Read `tech-stack.md` — learn the runtime, dependencies, and patterns.
3. Read `constraints.md` — internalize coding rules and conventions.
4. Reference `api-surface.md` and `data-flows.md` as needed during implementation.
5. Consult `file-tree.md` when locating files.

---

## 2. Manifest Maintenance Rules

When you change code, update the corresponding manifest documents **in the same commit**.

| Change Made | Documents to Update |
|---|---|
| New class or file added | `file-tree.md`, `api-surface.md` |
| Public method added/removed/renamed | `api-surface.md` |
| Error code constant added | `api-surface.md`, `constraints.md` |
| Dependency added/removed | `tech-stack.md` |
| Directory restructured | `file-tree.md` |
| New data flow or switching behavior | `data-flows.md` |
| Coding convention changed | `constraints.md` |
| Test structure changed | `file-tree.md`, `constraints.md` |
| PHP version requirement changed | `tech-stack.md`, `constraints.md` |

---

## 3. Efficiency Rules — Search Smart

- **Finding files?** Check `file-tree.md` FIRST.
- **Understanding methods or constants?** Check `api-surface.md` FIRST.
- **Implementation patterns or autoloading?** Check `tech-stack.md` FIRST.
- **Switching workflows or file relationships?** Check `data-flows.md` FIRST.
- **Coding rules or error code scheme?** Check `constraints.md` FIRST.
- **Only then** read source files.

---

## 4. Failure Protocol & Decision Matrix

| Scenario | Action | Priority |
|---|---|---|
| Ambiguous requirement | Use most restrictive interpretation | MUST |
| Manifest/code conflict | Trust manifest, flag code for fix | MUST |
| Missing documentation | Flag gap, do not invent facts | MUST |
| Untested code path | Proceed with caution, add test recommendation | SHOULD |
| New error code needed | Follow `1821xx` numbering; exceptions use `182101`–`1821xx`, switcher uses `1822xx` | MUST |
| `composer.json` edit requested | Edit `composer-prod.json` instead — the switcher overwrites `composer.json` | MUST |
| Flag/lock/status file path logic unclear | Paths are derived by string replacement on `.json` — see `constraints.md` | SHOULD |

---

## 5. Project Stats

| Key | Value |
|---|---|
| **Language** | PHP >=8.4 (`declare(strict_types=1)`) |
| **Architecture** | Single orchestrator + utility classes, no framework |
| **Package Manager** | Composer |
| **Autoloading** | Classmap (`src/`, `tests/TestClasses/`) |
| **Test Framework** | PHPUnit >=9.6 |
| **Static Analysis** | PHPStan >=1.10 |
| **Test Command** | `composer test` |
| **Test Single File** | `composer test-file -- path/to/Test.php` |
| **Test by Suite** | `composer test-suite -- <name>` |
| **Test by Filter** | `composer test-filter -- <pattern>` |
| **Test by Group** | `composer test-group -- <group>` |
| **Analyse Command** | `composer analyze` |
| **Analyse (save)** | `composer analyze-save` (writes `phpstan-result.txt`) |
| **Analyse (clear cache)** | `composer analyze-clear` |
| **Namespace** | `Mistralys\ComposerSwitcher`, `Mistralys\ComposerSwitcher\Utils` |
| **License** | MIT |
