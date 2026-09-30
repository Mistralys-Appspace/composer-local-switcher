<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\LocalPackageClone;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use ReflectionMethod;

/**
 * Minimal `IntegrationTestCase` subclass used to exercise `setUp()`
 * directly via reflection, so a forced-unavailable scenario (e.g. the
 * Composer binary) can be triggered without going through the full
 * PHPUnit test lifecycle.
 *
 * Not a runnable test case itself: only ever instantiated and driven
 * manually from {@see TestIntegrationTestCase}.
 */
final class IntegrationTestCaseHarness extends IntegrationTestCase
{
    /**
     * Placeholder test method required by PHPUnit's TestCase constructor.
     * Never executed by the runner directly.
     */
    public function test_placeholder() : void
    {
        $this->expectNotToPerformAssertions();
    }

    public function getWorkCopyPath() : string
    {
        return $this->testTarget;
    }
}

/**
 * Covers the Tier 2 base test case {@see IntegrationTestCase}: the
 * integration fixture copy, both placeholder substitutions, the
 * cause-naming skip behaviour, and the bootstrap/runner/version-editing
 * hooks every Tier 2 suite built on top of it relies on.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 *
 * @see IntegrationTestCase
 */
final class TestIntegrationTestCase extends IntegrationTestCase
{
    // region: _Tests

    public function test_setUpCopiesIntegrationProjectFixtureAndSubstitutesBothPlaceholders() : void
    {
        $this->assertStringEndsWith('/integration-project', $this->testSource);

        $mainJson = file_get_contents($this->testTarget . '/composer.json');
        $prodJson = file_get_contents($this->testTarget . '/composer/composer-prod.json');
        $localRepositoriesJson = file_get_contents($this->testTarget . '/composer/local-repositories.json');

        $this->assertStringNotContainsString('__LIBRARY_SRC_PATH__', (string)$mainJson);
        $this->assertStringNotContainsString('__LIBRARY_SRC_PATH__', (string)$prodJson);
        $this->assertStringNotContainsString('__LOCAL_CLONE_PATH__', (string)$localRepositoriesJson);

        $expectedSourcePath = realpath(__DIR__ . '/../../src');

        $this->assertNotFalse($expectedSourcePath);
        $this->assertStringContainsString($expectedSourcePath, (string)$mainJson);
        $this->assertStringContainsString($expectedSourcePath, (string)$prodJson);
    }

    /**
     * The substitution must only ever touch the work copy: the committed
     * fixture under `tests/assets/integration-project/` still contains
     * both placeholders verbatim after the test ran.
     */
    public function test_setUpDoesNotModifyCommittedFixtureFiles() : void
    {
        $committedMainJson = file_get_contents($this->assetsFolder . '/integration-project/composer.json');
        $committedProdJson = file_get_contents($this->assetsFolder . '/integration-project/composer/composer-prod.json');
        $committedLocalRepositoriesJson = file_get_contents($this->assetsFolder . '/integration-project/composer/local-repositories.json');

        $this->assertStringContainsString('__LIBRARY_SRC_PATH__', (string)$committedMainJson);
        $this->assertStringContainsString('__LIBRARY_SRC_PATH__', (string)$committedProdJson);
        $this->assertStringContainsString('__LOCAL_CLONE_PATH__', (string)$committedLocalRepositoriesJson);
    }

    public function test_bootstrapProdProducesLockFileAndVendorDirectory() : void
    {
        $this->bootstrapProd();

        $this->assertFileExists($this->testTarget . '/composer.lock');
        $this->assertDirectoryExists($this->testTarget . '/vendor');
    }

    public function test_runComposerExecutesWithinWorkCopy() : void
    {
        $result = $this->runComposer('--version');

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('Composer version', $result->getOutput());
    }

    public function test_setLocalRepositoryVersionAddsVersionKeyWithoutDisturbingOtherKeys() : void
    {
        $this->setLocalRepositoryVersion('2.1.0');

        $repository = $this->getFirstLocalRepositoryEntry();

        $this->assertSame('2.1.0', $repository['version']);
        $this->assertSame('mistralys/simple_html_dom', $repository['package-name']);
        $this->assertArrayHasKey('path', $repository);
    }

    public function test_setLocalRepositoryVersionRemovesVersionKeyWithoutDisturbingOtherKeys() : void
    {
        $this->setLocalRepositoryVersion('2.1.0');
        $this->setLocalRepositoryVersion(null);

        $repository = $this->getFirstLocalRepositoryEntry();

        $this->assertArrayNotHasKey('version', $repository);
        $this->assertSame('mistralys/simple_html_dom', $repository['package-name']);
        $this->assertArrayHasKey('path', $repository);
    }

    /**
     * The one cause that can be forced deterministically without mutating
     * the shared, cross-session local package clone cache: an unresolvable
     * `COMPOSER_BINARY` override. Proves the end-to-end skip path fires a
     * real {@see SkippedWithMessageException} rather than failing the test.
     */
    public function test_setUpSkipsWithCauseNamingMessageWhenComposerBinaryIsUnavailable() : void
    {
        $harness = new IntegrationTestCaseHarness('test_placeholder');

        $originalBinary = getenv('COMPOSER_BINARY');
        putenv('COMPOSER_BINARY=/nonexistent/composer-binary-does-not-exist');

        $setUp = new ReflectionMethod(IntegrationTestCase::class, 'setUp');

        try {
            $setUp->invoke($harness);
            $this->fail('Expected setUp() to skip the test via markTestSkipped().');
        } catch (SkippedWithMessageException $e) {
            $this->assertStringContainsString('Composer binary', $e->getMessage());
        } finally {
            if($originalBinary === false) {
                putenv('COMPOSER_BINARY');
            } else {
                putenv('COMPOSER_BINARY=' . $originalBinary);
            }

            FixtureFileSystem::removeDirectory($harness->getWorkCopyPath());
        }
    }

    /**
     * The three {@see LocalPackageClone} unavailability reasons each
     * produce a distinct, cause-naming message. Exercised directly via
     * reflection rather than by forcing the shared clone cache into each
     * state, since that cache is reused across concurrent test sessions.
     */
    public function test_composeCloneSkipMessageNamesEachUnavailabilityCause() : void
    {
        $method = new ReflectionMethod(IntegrationTestCase::class, 'composeCloneSkipMessage');
        $samplePath = '/sample/cache/path';

        $gitMessage = $method->invoke($this, LocalPackageClone::REASON_GIT_UNAVAILABLE, $samplePath);
        $cloneMessage = $method->invoke($this, LocalPackageClone::REASON_CLONE_FAILED, $samplePath);
        $directoryMessage = $method->invoke($this, LocalPackageClone::REASON_INVALID_DIRECTORY, $samplePath);

        $this->assertStringContainsString('git', $gitMessage);
        $this->assertStringContainsString('clon', $cloneMessage);
        $this->assertStringContainsString('directory', $directoryMessage);
        $this->assertStringContainsString($samplePath, $directoryMessage);
        $this->assertStringContainsString('delete', $directoryMessage);

        // Every message must be distinct: a shared, generic message would
        // technically satisfy string-contains assertions above by accident.
        $this->assertNotSame($gitMessage, $cloneMessage);
        $this->assertNotSame($cloneMessage, $directoryMessage);
        $this->assertNotSame($gitMessage, $directoryMessage);
    }

    /**
     * A failing {@see IntegrationTestCase::runComposerChecked()} call fails
     * the test immediately (rather than returning a failed
     * {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ProcessResult}),
     * and the resulting failure message names both the offending command
     * and its exit code.
     */
    public function test_runComposerCheckedFailsWithBothStreams() : void
    {
        $expectedResult = $this->runComposer('no-such-command');

        try {
            $this->runComposerChecked('no-such-command');
            $this->fail('Expected runComposerChecked() to fail the test via a failed assertion.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('no-such-command', $e->getMessage());
            $this->assertStringContainsString((string)$expectedResult->getExitCode(), $e->getMessage());
        }
    }

    /**
     * {@see IntegrationTestCase::writeJsonFile()} pretty-prints without
     * escaping slashes and with a trailing newline, and the result decodes
     * back via {@see IntegrationTestCase::decodeJsonFile()} to the exact
     * data that was written.
     */
    public function test_writeJsonFileRoundTripsThroughDecodeJsonFile() : void
    {
        $path = $this->testTarget . '/write-json-file-roundtrip.json';
        $data = array(
            'foo' => 'bar',
            'nested' => array('a' => 1, 'path' => '/some/path'),
        );

        $this->writeJsonFile($path, $data);

        $this->assertSame($data, $this->decodeJsonFile($path));
        $this->assertStringEndsWith(PHP_EOL, $this->readFile($path));
        $this->assertStringNotContainsString('\\/', $this->readFile($path));
    }

    // endregion

    // region: Support methods

    /**
     * @return array<string,mixed>
     */
    private function getFirstLocalRepositoryEntry() : array
    {
        $data = $this->decodeJsonFile($this->testTarget . '/composer/local-repositories.json');

        return $data['local-repositories'][0];
    }

    // endregion
}
