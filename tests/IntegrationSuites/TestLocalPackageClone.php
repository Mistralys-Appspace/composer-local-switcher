<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\GitRunner;
use Mistralys\ComposerSwitcher\Tests\TestClasses\LocalPackageClone;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 counterpart of `Mistralys\ComposerSwitcher\TestSuites\TestLocalPackageClone`,
 * proving {@see LocalPackageClone}'s acquisition algorithm (clone, verify,
 * atomic rename) against a real `git` binary — both the failure path
 * (repository unreachable) and the success path (a real clone landing at
 * the cache path).
 *
 * Every test here injects its own cache directory and repository URL, so
 * none of them ever touches the real, shared clone cache under
 * `tests/assets/local-clones/` that other Tier 2 suites reuse across
 * concurrent sessions. Every test here shells out to a real `git` binary,
 * so it belongs in the `Integration` testsuite (`composer test-integration`),
 * not the default, offline `Test suites` testsuite (`composer test`).
 */
final class TestLocalPackageClone extends TestCase
{
    private ?string $workRoot = null;

    protected function setUp() : void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-switcher-local-package-clone-' . uniqid('', true);

        if(!mkdir($this->workRoot, 0755, true) && !is_dir($this->workRoot)) {
            $this->fail(sprintf('Failed to create throwaway work root: %s', $this->workRoot));
        }
    }

    protected function tearDown() : void
    {
        if($this->workRoot !== null) {
            FixtureFileSystem::removeDirectory($this->workRoot);
            $this->workRoot = null;
        }

        parent::tearDown();
    }

    // region: _Tests

    public function test_failedCloneReportsCloneFailedAndLeavesNothingBehind() : void
    {
        $cacheDir = $this->workRoot . '/cache/simple_html_dom';
        $nonexistentRepository = $this->workRoot . '/does-not-exist-repo';

        $clone = new LocalPackageClone($cacheDir, $nonexistentRepository);

        $firstResult = $clone->ensureAvailable();
        $this->assertNull($firstResult);
        $this->assertSame(LocalPackageClone::REASON_CLONE_FAILED, $clone->getUnavailableReason());

        $secondResult = $clone->ensureAvailable();
        $this->assertNull($secondResult);
        $this->assertSame(LocalPackageClone::REASON_CLONE_FAILED, $clone->getUnavailableReason());

        $this->assertDirectoryDoesNotExist($cacheDir);
        $this->assertNoPartialSiblingsExist($cacheDir);
    }

    public function test_successfulCloneIsAtomic() : void
    {
        $sourceRepository = $this->createLocalGitRepository();
        $cacheDir = $this->workRoot . '/cache/simple_html_dom';

        $clone = new LocalPackageClone($cacheDir, $sourceRepository);

        $result = $clone->ensureAvailable();

        $this->assertNotNull($result);
        $this->assertSame(LocalPackageClone::REASON_NONE, $clone->getUnavailableReason());
        $this->assertFileExists($result . '/composer.json');
        $this->assertNoPartialSiblingsExist($cacheDir);
    }

    // endregion

    // region: Support methods

    private function assertNoPartialSiblingsExist(string $cacheDir) : void
    {
        $parentDir = dirname($cacheDir);

        if(!is_dir($parentDir)) {
            return;
        }

        $partials = glob($parentDir . '/.partial-' . basename($cacheDir) . '-*') ?: array();

        $this->assertSame(array(), $partials, 'Expected no .partial-* siblings to remain.');
    }

    /**
     * Creates a throwaway local git repository containing a `composer.json`,
     * usable as {@see LocalPackageClone}'s injected repository URL: `git
     * clone` accepts a plain filesystem path directly.
     */
    private function createLocalGitRepository() : string
    {
        $repositoryDir = $this->workRoot . '/source-repo';

        if(!mkdir($repositoryDir, 0755, true) && !is_dir($repositoryDir)) {
            $this->fail(sprintf('Failed to create throwaway source repository: %s', $repositoryDir));
        }

        file_put_contents($repositoryDir . '/composer.json', '{"name": "mistralys/simple_html_dom"}');

        $runner = new GitRunner($repositoryDir);

        $this->assertProcessSucceeded($runner->run('init'), 'git init');
        $this->assertProcessSucceeded($runner->run('add', 'composer.json'), 'git add');
        $this->assertProcessSucceeded(
            $runner->run(
                '-c', 'user.name=Test Runner',
                '-c', 'user.email=test-runner@example.com',
                'commit', '-m', 'Initial commit'
            ),
            'git commit'
        );

        return $repositoryDir;
    }

    private function assertProcessSucceeded(ProcessResult $result, string $label) : void
    {
        $this->assertTrue(
            $result->isSuccess(),
            sprintf("Expected '%s' to succeed.\nOutput:\n%s\nError output:\n%s", $label, $result->getOutput(), $result->getErrorOutput())
        );
    }

    // endregion
}
