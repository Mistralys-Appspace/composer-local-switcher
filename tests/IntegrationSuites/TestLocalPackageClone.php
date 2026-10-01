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

    /**
     * When a concurrent process wins the race — its own cache directory
     * already exists (non-empty, with a valid `composer.json`) by the time
     * this clone's own `@rename()` runs — `ensureAvailable()` discards its
     * own attempt and adopts the winner's cache instead, returning it with
     * {@see LocalPackageClone::REASON_NONE} and leaving no `.partial-*`
     * sibling behind.
     *
     * The concurrent winner is materialised via an anonymous subclass
     * overriding the protected {@see LocalPackageClone::cloneInto()} test
     * seam: it performs the real clone exactly as the parent would, then
     * creates the winner's cache directory itself, so the subsequent
     * `@rename()` inside `ensureAvailable()` is the real, unstubbed call —
     * it genuinely loses the race against a real, non-empty directory
     * already at the cache path.
     */
    public function test_concurrentWinnerValidDirectoryIsAdopted() : void
    {
        $this->skipUnlessRenameOntoNonEmptyDirectoryFails();

        $sourceRepository = $this->createLocalGitRepository();
        $cacheDir = $this->workRoot . '/cache/simple_html_dom';

        $clone = $this->createCloneWithConcurrentWinner($cacheDir, $sourceRepository, withComposerJson: true);

        $result = $clone->ensureAvailable();

        $this->assertNotNull($result);
        $this->assertSame(LocalPackageClone::REASON_NONE, $clone->getUnavailableReason());
        $this->assertFileExists($result . '/composer.json');
        $this->assertNoPartialSiblingsExist($cacheDir);
    }

    /**
     * When a concurrent process's directory wins the rename race but is
     * itself invalid (non-empty, but missing `composer.json` — e.g. a
     * corrupted or hand-edited directory) — `ensureAvailable()` reports a
     * genuine {@see LocalPackageClone::REASON_CLONE_FAILED} rather than
     * adopting it, and still leaves no `.partial-*` sibling behind.
     */
    public function test_concurrentWinnerInvalidDirectoryFailsClone() : void
    {
        $this->skipUnlessRenameOntoNonEmptyDirectoryFails();

        $sourceRepository = $this->createLocalGitRepository();
        $cacheDir = $this->workRoot . '/cache/simple_html_dom';

        $clone = $this->createCloneWithConcurrentWinner($cacheDir, $sourceRepository, withComposerJson: false);

        $result = $clone->ensureAvailable();

        $this->assertNull($result);
        $this->assertSame(LocalPackageClone::REASON_CLONE_FAILED, $clone->getUnavailableReason());
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

    /**
     * Probes, against a pair of real, throwaway directories, that this
     * host's `rename()` actually fails when the destination is an existing,
     * non-empty directory — the behaviour the concurrent-winner branch
     * depends on. Skips the calling test with a named reason instead of
     * failing it when the probe does not reproduce that behaviour (e.g. an
     * unusual filesystem where it succeeds).
     */
    private function skipUnlessRenameOntoNonEmptyDirectoryFails() : void
    {
        $probeRoot = $this->workRoot . '/rename-probe-' . uniqid('', true);
        $source = $probeRoot . '/source';
        $destination = $probeRoot . '/destination';

        if(!mkdir($source, 0755, true) && !is_dir($source)) {
            $this->fail(sprintf('Failed to create throwaway probe source: %s', $source));
        }

        if(!mkdir($destination, 0755, true) && !is_dir($destination)) {
            $this->fail(sprintf('Failed to create throwaway probe destination: %s', $destination));
        }

        file_put_contents($destination . '/marker.txt', 'non-empty');

        $renameSucceeded = @rename($source, $destination);

        FixtureFileSystem::removeDirectory($probeRoot);

        if($renameSucceeded) {
            $this->markTestSkipped('Skipped: this host\'s rename() unexpectedly succeeds onto a non-empty directory, so the concurrent-winner branch cannot be reproduced with a real rename() here.');
        }
    }

    /**
     * Builds a {@see LocalPackageClone} whose protected `cloneInto()` test
     * seam is overridden (via an anonymous subclass) to perform the real
     * clone exactly as the parent would, then materialise a competing,
     * non-empty directory at the cache path before returning — simulating
     * a concurrent process that already won the race by the time
     * `ensureAvailable()`'s own `@rename()` runs.
     *
     * @param string $cacheDir
     * @param string $repositoryUrl
     * @param bool $withComposerJson Whether the materialised winner
     *        directory contains a valid `composer.json` (the "valid
     *        winner, adopt it" case) or not (the "invalid winner, genuine
     *        failure" case).
     * @return LocalPackageClone
     */
    private function createCloneWithConcurrentWinner(string $cacheDir, string $repositoryUrl, bool $withComposerJson) : LocalPackageClone
    {
        return new class($cacheDir, $repositoryUrl, $withComposerJson) extends LocalPackageClone {
            public function __construct(
                string $cacheDirectory,
                string $repositoryUrl,
                private readonly bool $withComposerJson
            )
            {
                parent::__construct($cacheDirectory, $repositoryUrl);
            }

            protected function cloneInto(string $targetDir) : bool
            {
                $result = parent::cloneInto($targetDir);

                if(!$result) {
                    return $result;
                }

                $winnerDir = $this->getCacheDirectory();

                if(!is_dir($winnerDir) && !mkdir($winnerDir, 0755, true)) {
                    return false;
                }

                file_put_contents($winnerDir . '/marker.txt', 'concurrent winner');

                if($this->withComposerJson) {
                    file_put_contents($winnerDir . '/composer.json', '{"name": "mistralys/simple_html_dom"}');
                }

                return $result;
            }
        };
    }

    // endregion
}
