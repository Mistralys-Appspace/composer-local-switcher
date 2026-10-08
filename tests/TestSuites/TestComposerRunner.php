<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerRunner;
use PHPUnit\Framework\TestCase;

final class TestComposerRunner extends TestCase
{
    // region: _Tests

    // NOTE: Every test that invokes a real Composer binary (successful or
    // failing command, `isAvailable()` against a real binary, large-output
    // draining) lives in the Tier 2 counterpart of this class,
    // Mistralys\ComposerSwitcher\IntegrationSuites\TestComposerRunner
    // (tests/IntegrationSuites/TestComposerRunner.php). This Tier 1 file is
    // limited to the one case that never shells out to a real binary, so
    // `composer test` stays free of Composer-binary invocations.

    public function test_isAvailableReturnsFalseForUnresolvableBinary() : void
    {
        $this->withComposerBinaryEnv('/nonexistent/composer-binary-does-not-exist', function () {
            $runner = new ComposerRunner(getcwd());

            $this->assertFalse($runner->isAvailable());
        });
    }

    /**
     * `--no-interaction` must be inserted before the first `--`
     * separator, not appended after everything — a script argument
     * passed after `--` (e.g. `-- --yes`) is read by the switcher's own
     * `EventContext::getArguments()`, not by Composer itself, so
     * appending `--no-interaction` after it would hand the flag to the
     * script instead of Composer.
     */
    public function test_noInteractionInsertedBeforeSeparator() : void
    {
        $recordFile = sys_get_temp_dir() . '/composer-runner-record-' . uniqid('', true) . '.json';

        $this->withComposerBinaryEnv(__DIR__ . '/../assets/fake-composer/composer.php', function () use ($recordFile) {
            $this->withRecordFileEnv($recordFile, function () use ($recordFile) {
                $runner = new ComposerRunner(getcwd());
                $runner->run('switch-dev', '--', '--yes');

                $this->assertFileExists($recordFile);

                /** @var array{argv:string[]} $record */
                $record = json_decode((string)file_get_contents($recordFile), true);

                $this->assertSame(
                    array('switch-dev', '--no-interaction', '--', '--yes'),
                    $record['argv']
                );
            });
        });
    }

    /**
     * With no `--` separator at all, `--no-interaction` is simply
     * appended at the end, same as before this WP's fix.
     */
    public function test_noInteractionAppendedWhenNoSeparatorPresent() : void
    {
        $recordFile = sys_get_temp_dir() . '/composer-runner-record-' . uniqid('', true) . '.json';

        $this->withComposerBinaryEnv(__DIR__ . '/../assets/fake-composer/composer.php', function () use ($recordFile) {
            $this->withRecordFileEnv($recordFile, function () use ($recordFile) {
                $runner = new ComposerRunner(getcwd());
                $runner->run('update', 'some/package');

                $this->assertFileExists($recordFile);

                /** @var array{argv:string[]} $record */
                $record = json_decode((string)file_get_contents($recordFile), true);

                $this->assertSame(
                    array('update', 'some/package', '--no-interaction'),
                    $record['argv']
                );
            });
        });
    }

    // endregion

    // region: Support methods

    /**
     * @param string $value
     * @param callable():void $callback
     */
    private function withComposerBinaryEnv(string $value, callable $callback) : void
    {
        $original = getenv('COMPOSER_BINARY');

        putenv('COMPOSER_BINARY=' . $value);

        try {
            $callback();
        } finally {
            if($original === false) {
                putenv('COMPOSER_BINARY');
            } else {
                putenv('COMPOSER_BINARY=' . $original);
            }
        }
    }

    /**
     * Sets `FAKE_COMPOSER_RECORD_FILE` for the duration of `$callback`,
     * via both `putenv()` and `$_SERVER` — {@see \Symfony\Component\Process\Process}'s
     * default environment only forwards a `getenv()` entry to the
     * child process when its key also exists in `$_SERVER`
     * (`Process::getDefaultEnv()`), so a bare `putenv()` call made
     * after the request already started is otherwise silently dropped
     * before it ever reaches the fake Composer binary.
     *
     * @param callable():void $callback
     */
    private function withRecordFileEnv(string $recordFile, callable $callback) : void
    {
        putenv('FAKE_COMPOSER_RECORD_FILE=' . $recordFile);
        $_SERVER['FAKE_COMPOSER_RECORD_FILE'] = $recordFile;

        try {
            $callback();
        } finally {
            putenv('FAKE_COMPOSER_RECORD_FILE');
            unset($_SERVER['FAKE_COMPOSER_RECORD_FILE']);

            if(file_exists($recordFile)) {
                unlink($recordFile);
            }
        }
    }

    // endregion
}
