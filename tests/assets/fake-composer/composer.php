#!/usr/bin/env php
<?php
/**
 * A fake `composer` binary used by Tier 1 tests to exercise
 * {@see \Mistralys\ComposerSwitcher\Utils\ComposerProcess},
 * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} and
 * {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerRunner} against
 * a real child process, without needing the real Composer binary or
 * network/package-resolution side effects.
 *
 * Invoked exactly as a real `composer` binary would be, via
 * `ComposerProcess::run()`'s `[PHP_BINARY, $binaryPath, ...$arguments]`
 * array form — so `$argv[0]` is this script's own path, and everything
 * after it is the planned command's arguments (e.g. `update`,
 * `vendor/pkg`, `--with-dependencies`).
 *
 * Controlled entirely through environment variables, since those are
 * exactly what the test process can set before invoking
 * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — the
 * child process inherits the parent's `getenv()` plus
 * `COMPOSER_SWITCHER_NESTED=1` (see `ComposerProcess::buildEnvironment()`):
 *
 * - `FAKE_COMPOSER_RECORD_FILE`: when set, this run's argv (excluding
 *   this script's own path) and whether `COMPOSER_SWITCHER_NESTED` was
 *   set are written to this path as JSON, so the test can assert on
 *   exactly what was invoked.
 * - `FAKE_COMPOSER_EXIT_CODE`: the exit code this run should return
 *   (defaults to `0`), so tests can simulate a failing Composer
 *   command and assert on `ComposerProcess`'s/`SwitchCommandRunner`'s
 *   failure mapping.
 */

declare(strict_types=1);

$recordFile = getenv('FAKE_COMPOSER_RECORD_FILE');

if(is_string($recordFile) && $recordFile !== '')
{
    $record = array(
        'argv' => array_slice($argv, 1),
        'nested' => getenv('COMPOSER_SWITCHER_NESTED') !== false,
    );

    file_put_contents($recordFile, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

$exitCode = getenv('FAKE_COMPOSER_EXIT_CODE');

exit(is_string($exitCode) && $exitCode !== '' ? (int)$exitCode : 0);
