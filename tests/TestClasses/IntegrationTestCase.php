<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;

/**
 * Tier 2 base test case: extends {@see ComposerSwitcherTestCase} to copy the
 * `integration-project` fixture instead of `test-project`, then resolves the
 * local package clone and substitutes both placeholders the fixture ships
 * with (`__LOCAL_CLONE_PATH__` and `__LIBRARY_SRC_PATH__`) in the work copy.
 *
 * Every Tier 2 suite extends this class rather than reimplementing the
 * copy/skip/substitution sequence, so a single `setUp()` is the one place
 * that assembles the WP-003 fixture-source seam, the WP-005 fixture, the
 * WP-006 clone resolution, and the WP-007 runner.
 *
 * The substitution is applied to both `composer.json` and
 * `composer/composer-prod.json` because {@see ConfigSwitcher} rebuilds the
 * active `composer.json` from the prod baseline on every DEV switch — a
 * substitution applied only to the former would be silently discarded by
 * the first `switch-dev` call.
 */
abstract class IntegrationTestCase extends ComposerSwitcherTestCase
{
    private const PLACEHOLDER_LOCAL_CLONE_PATH = '__LOCAL_CLONE_PATH__';
    private const PLACEHOLDER_LIBRARY_SRC_PATH = '__LIBRARY_SRC_PATH__';

    private ?ComposerRunner $composerRunner = null;

    protected function getFixtureSourceDir() : string
    {
        return 'integration-project';
    }

    protected function setUp() : void
    {
        parent::setUp();

        $clone = new LocalPackageClone();
        $clonePath = $clone->ensureAvailable();

        if($clonePath === null) {
            $this->markTestSkipped($this->composeCloneSkipMessage($clone->getUnavailableReason(), $clone->getCacheDirectory()));
        }

        if(!$this->getComposerRunner()->isAvailable()) {
            $this->markTestSkipped('Skipped: the Composer binary is not available in this environment.');
        }

        $this->substitutePlaceholder(
            $this->testTarget . '/composer/local-repositories.json',
            self::PLACEHOLDER_LOCAL_CLONE_PATH,
            $clonePath
        );

        $librarySourcePath = $this->getLibrarySourcePath();

        $this->substitutePlaceholder($this->testTarget . '/composer.json', self::PLACEHOLDER_LIBRARY_SRC_PATH, $librarySourcePath);
        $this->substitutePlaceholder($this->testTarget . '/composer/composer-prod.json', self::PLACEHOLDER_LIBRARY_SRC_PATH, $librarySourcePath);
    }

    /**
     * Runs `composer update` once in the work copy, producing a real
     * `composer.lock` and `vendor/` tree in a PROD-shaped INITIAL state
     * (the fixture's `composer.json` still mirrors `composer-prod.json`
     * at this point, since no switch has happened yet).
     *
     * @return void
     */
    protected function bootstrapProd() : void
    {
        $this->runComposerChecked('update');
    }

    /**
     * Runs the sequence every DEV-mode assertion needs before it can run:
     * bootstraps a PROD lock file (a switch is a no-op without one), then
     * runs a checked `composer switch-dev`.
     *
     * @return void
     */
    protected function switchToDev() : void
    {
        $this->bootstrapProd();
        $this->runComposerChecked('switch-dev');
    }

    /**
     * Runs a checked `composer update` in the work copy — the counterpart
     * to {@see self::bootstrapProd()} for whichever mode (DEV or PROD) is
     * currently active, producing a real lock file and `vendor/` tree for
     * that mode.
     *
     * @return void
     */
    protected function updateDependencies() : void
    {
        $this->runComposerChecked('update');
    }

    /**
     * Runs a Composer command in the work copy, via a {@see ComposerRunner}
     * bound to {@see self::$testTarget}.
     *
     * @param string ...$arguments
     * @return ProcessResult
     */
    protected function runComposer(string ...$arguments) : ProcessResult
    {
        return $this->getComposerRunner()->run(...$arguments);
    }

    /**
     * Runs a Composer command and fails the test immediately with the
     * command, its exit code, and both output streams if it did not
     * succeed, instead of leaving a generic assertion failure for a later
     * step to explain.
     *
     * @param string ...$arguments
     * @return ProcessResult
     */
    protected function runComposerChecked(string ...$arguments) : ProcessResult
    {
        $result = $this->runComposer(...$arguments);

        if(!$result->isSuccess()) {
            $this->fail(sprintf(
                "'composer %s' failed unexpectedly, exit code %d.\nOutput:\n%s\nError output:\n%s",
                implode(' ', $arguments),
                $result->getExitCode(),
                $result->getOutput(),
                $result->getErrorOutput()
            ));
        }

        return $result;
    }

    /**
     * Reads a file's full contents, failing the test immediately if it
     * could not be read.
     *
     * @param string $path
     * @return string
     */
    protected function readFile(string $path) : string
    {
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, sprintf('Failed to read file: %s', $path));

        return (string)$contents;
    }

    /**
     * Reads and JSON-decodes a file, failing the test immediately if it
     * could not be read or did not decode to an array.
     *
     * @param string $path
     * @return array<string,mixed>
     */
    protected function decodeJsonFile(string $path) : array
    {
        $data = json_decode($this->readFile($path), true);

        $this->assertIsArray($data, sprintf('Expected %s to decode to a valid JSON object/array.', $path));

        return $data;
    }

    /**
     * Pretty-prints `$data` as JSON (unescaped slashes, trailing newline)
     * and writes it to `$path`, failing the test immediately if the write
     * did not succeed.
     *
     * @param string $path
     * @param array<string,mixed> $data
     * @return void
     */
    protected function writeJsonFile(string $path, array $data) : void
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertNotFalse(file_put_contents($path, $encoded . PHP_EOL), sprintf('Failed to write %s.', $path));
    }

    /**
     * Writes or removes the optional `version` key on every entry of the
     * work copy's `composer/local-repositories.json`, without disturbing
     * the rest of the file.
     *
     * @param string|null $version NULL removes the `version` key entirely.
     * @return void
     */
    protected function setLocalRepositoryVersion(?string $version) : void
    {
        $configFile = new ConfigFile($this->testTarget . '/composer/local-repositories.json');
        $data = $configFile->getData();

        $repositories = $data[ConfigSwitcher::KEY_LOCAL_REPOSITORIES] ?? array();

        foreach($repositories as &$repository)
        {
            if($version === null) {
                unset($repository['version']);
            } else {
                $repository['version'] = $version;
            }
        }

        unset($repository);

        $data[ConfigSwitcher::KEY_LOCAL_REPOSITORIES] = $repositories;

        $configFile->putData($data);
    }

    private function getComposerRunner() : ComposerRunner
    {
        if($this->composerRunner === null) {
            $this->composerRunner = new ComposerRunner($this->testTarget);
        }

        return $this->composerRunner;
    }

    private function getLibrarySourcePath() : string
    {
        $path = realpath(__DIR__ . '/../../src');

        if($path === false) {
            $this->fail('Could not resolve the library source path (src/ directory not found).');
        }

        return $path;
    }

    private function substitutePlaceholder(string $filePath, string $placeholder, string $replacement) : void
    {
        $contents = file_get_contents($filePath);

        if($contents === false) {
            $this->fail(sprintf('Failed to read fixture file for placeholder substitution: %s', $filePath));
        }

        $contents = str_replace($placeholder, $replacement, $contents);

        if(file_put_contents($filePath, $contents) === false) {
            $this->fail(sprintf('Failed to write substituted fixture file: %s', $filePath));
        }
    }

    private function composeCloneSkipMessage(string $reason, string $cacheDirectory) : string
    {
        return match($reason)
        {
            LocalPackageClone::REASON_GIT_UNAVAILABLE =>
                'Skipped: git is not available on this system, so the local package clone cannot be created.',

            LocalPackageClone::REASON_CLONE_FAILED =>
                'Skipped: cloning the local package repository failed (network or repository access issue).',

            LocalPackageClone::REASON_INVALID_DIRECTORY =>
                sprintf(
                    'Skipped: the local package clone cache directory [%s] is invalid (missing composer.json); delete it to re-clone.',
                    $cacheDirectory
                ),

            default => 'Skipped: the local package clone is unavailable for an unknown reason.',
        };
    }
}
