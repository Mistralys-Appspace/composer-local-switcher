# File Tree

```
composer-local-switcher/
├── composer.json                — Package definition & autoload config
├── phpunit.xml                  — PHPUnit configuration
├── changelog.md
├── README.md
├── LICENSE
├── src/
│   ├── ConfigSwitcher.php       — Main orchestrator: switches between DEV/PROD configs
│   ├── ComposerSwitcherException.php — Exception class with error code constants
│   └── Utils/
│       ├── BaseFile.php         — Abstract base: path, exists, delete, copy, modified date
│       ├── ConfigFile.php       — JSON config file: read/write with getData()/putData()
│       ├── ConsoleWriter.php    — Console output helper with header/line/separator methods
│       ├── FlagFile.php         — Creates mode indicator files (composer.json.DEV / .PROD)
│       ├── LockFile.php         — composer.lock file abstraction (derived from ConfigFile path)
│       └── StatusFile.php       — Persists switching state (mode, date, file paths) as JSON
├── tests/
│   ├── TestClasses/
│   │   └── ComposerSwitcherTestCase.php — Base test case: sets up isolated work directories
│   ├── TestSuites/
│   │   └── TestSwitching.php    — Tests for all switching scenarios
│   └── assets/
│       ├── test-project/        — Fixture project with composer.json, lock, and dev-config
│       └── work-projects/       — Ephemeral per-test working copies (created/cleaned by tests)
├── docs/                        — Documentation (this manifest)
└── vendor/                      — Composer dependencies (gitignored)
```
