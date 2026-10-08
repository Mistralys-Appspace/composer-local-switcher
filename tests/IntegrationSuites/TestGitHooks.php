<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\GitRunner;
use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ProcessResult;
use PHPUnit\Framework\SkippedWithMessageException;
use ReflectionMethod;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Tier 2 suite proving `composer switch-install-hooks` and both
 * pre-commit guards shipped in `resources/git-hooks/pre-commit` against a
 * real, ephemeral `git init`-ed work copy with a real staging area.
 *
 * Running the shipped hook against a real work copy is safe only because
 * the work copy is ephemeral and thrown away on teardown — installing
 * hooks against a developer checkout would overwrite that developer's own
 * `.git/hooks/pre-commit`, which is why no other suite in this project
 * does so.
 *
 * Every test here shells out to a real Composer binary, a real `git`
 * binary, and/or the cached local package clone, so it belongs in the
 * `Integration` testsuite (`composer test-integration`), not the
 * default, offline `Test suites` testsuite (`composer test`).
 */
final class TestGitHooks extends IntegrationTestCase
{
    private ?GitRunner $gitRunner = null;

    protected function setUp() : void
    {
        parent::setUp();

        if(!$this->getGitRunner()->isAvailable()) {
            $this->markTestSkipped('Skipped: git is not available on this system, so the git hooks tests cannot run.');
        }

        $this->getGitRunner()->run('init');
    }

    // region: _Tests

    /**
     * After `git init`, `composer switch-install-hooks` exits zero,
     * prints the documented success message, and leaves an executable
     * `.git/hooks/pre-commit` behind — AC-10.
     */
    public function test_installHooksCopiesExecutableHook() : void
    {
        $result = $this->runComposer('switch-install-hooks');

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('Git hooks installed successfully.', $result->getOutput());

        $hookPath = $this->testTarget . '/.git/hooks/pre-commit';

        $this->assertFileExists($hookPath);
        $this->assertTrue(is_executable($hookPath), 'Expected the installed hook to be executable.');
    }

    /**
     * With `composer.json.DEV` present and `composer.json` staged, the
     * installed hook's Guard 1 blocks the commit (non-zero exit), names
     * `composer.json` among the blocked files in its output, and
     * instructs the single v3 remediation command `composer switch-prod`
     * (not the retired two-command `switch-prod` + `install` sequence) —
     * AC-10, AC-18.
     */
    public function test_guard1BlocksCommitInDevMode() : void
    {
        $this->installHooks();

        $this->assertNotFalse(file_put_contents($this->testTarget . '/composer.json.DEV', 'DEV'));

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 1 to block the commit while composer.json.DEV is present.');
        $this->assertStringContainsString('composer.json', $result->getOutput());
        $this->assertStringContainsString('composer switch-prod', $result->getOutput());
        $this->assertStringNotContainsString('composer install', $result->getOutput());
    }

    /**
     * With a staged `composer.json` containing a `"type": "path"`
     * repository entry and no DEV marker, the installed hook's Guard 2
     * blocks the commit regardless of DEV mode state — AC-10.
     */
    public function test_guard2BlocksPathRepository() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['repositories'] = array(
            array('type' => 'path', 'url' => '../local-clone'),
        );
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 2 to block the commit while composer.json contains a "type": "path" entry.');
    }

    /**
     * In PROD state — no DEV marker, no `"type": "path"` entry — a
     * staged `composer.json` passes the installed hook cleanly — AC-10.
     */
    public function test_hookPassesInProdState() : void
    {
        $this->installHooks();

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertSame(0, $result->getExitCode(), 'Expected the hook to pass in PROD state.');
    }

    /**
     * A pre-existing custom `.git/hooks/pre-commit` script is replaced
     * byte-for-byte by `resources/git-hooks/pre-commit` when `composer
     * switch-install-hooks` runs — {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::installGitHooks()}
     * does an unconditional `copy()`, so any hook a consumer already has
     * installed is silently overwritten rather than merged with or backed
     * up — AC-10.
     */
    public function test_installHooksOverwritesExistingCustomHook() : void
    {
        $hookPath = $this->testTarget . '/.git/hooks/pre-commit';

        if(!is_dir(dirname($hookPath))) {
            $this->assertTrue(mkdir(dirname($hookPath), 0755, true), 'Failed to create .git/hooks directory.');
        }

        $this->assertNotFalse(
            file_put_contents($hookPath, "#!/usr/bin/env bash\necho 'a pre-existing custom hook'\nexit 0\n"),
            'Failed to write the pre-existing custom hook.'
        );
        $this->assertTrue(chmod($hookPath, 0755), 'Failed to make the pre-existing custom hook executable.');

        $this->installHooks();

        $shippedHookPath = realpath(__DIR__ . '/../../resources/git-hooks/pre-commit');
        $this->assertNotFalse($shippedHookPath, 'Could not resolve the shipped pre-commit hook path.');

        $this->assertSame(
            $this->readFile($shippedHookPath),
            $this->readFile($hookPath),
            'Expected the installed hook to byte-for-byte replace the pre-existing custom hook.'
        );
        $this->assertTrue(is_executable($hookPath), 'Expected the installed hook to remain executable.');
    }

    /**
     * Guard 2 scopes its match to the `repositories` key: a
     * `"type": "path"` pair anywhere else in the file — e.g. under
     * `extra`, where it carries no special meaning to Composer — must
     * not block the commit, even though a blunt, file-wide grep would
     * match it — AC-10.
     */
    public function test_guard2IgnoresTypePathOutsideRepositories() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['extra'] = array('type' => 'path');
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertSame(
            0,
            $result->getExitCode(),
            'Expected Guard 2 to pass since the "type": "path" pair sits under `extra`, not `repositories`.'
        );
    }

    /**
     * Guard 2's scoped check recognizes a path repository in the
     * keyed-object form of `repositories` (`{"local": {"type": "path",
     * ...}}`), not just the list form already covered by
     * {@see self::test_guard2BlocksPathRepository()} — AC-10.
     */
    public function test_guard2BlocksPathRepositoryInKeyedObjectForm() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['repositories'] = array(
            'local' => array('type' => 'path', 'url' => '../local-clone'),
        );
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 2 to block a keyed-object-form path repository.');
    }

    /**
     * A non-array entry in `repositories` (e.g. `"packagist.org": false`,
     * a documented way to disable Packagist) must be skipped by Guard 2's
     * scoped check without a PHP error — the commit still passes when no
     * other entry is a path repository, and still blocks when one is — AC-10.
     */
    public function test_guard2SkipsNonArrayRepositoryEntryWhenNoPathRepositoryPresent() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['repositories'] = array('packagist.org' => false);
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertSame(0, $result->getExitCode(), 'Expected Guard 2 to pass: a non-array repositories entry is not a path repository.');
    }

    /**
     * The same non-array entry alongside an actual path repository must
     * still block — skipping the non-array entry must not short-circuit
     * the scan before the path repository entry is reached — AC-10.
     */
    public function test_guard2SkipsNonArrayRepositoryEntryButStillBlocksWhenPathRepositoryAlsoPresent() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['repositories'] = array(
            'packagist.org' => false,
            'local' => array('type' => 'path', 'url' => '../local-clone'),
        );
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 2 to still block when a path repository is present alongside a non-array entry.');
    }

    /**
     * When the staged `composer.json` is not valid JSON, Guard 2's
     * scoped check cannot evaluate it and falls back to the blunt,
     * file-wide grep — which blocks here since the invalid content
     * still contains the literal `"type": "path"` text. This proves
     * the fallback never fails open on undecodable input — AC-10.
     */
    public function test_guard2FallsBackToGrepOnInvalidJson() : void
    {
        $this->installHooks();

        $this->assertNotFalse(file_put_contents(
            $this->testTarget . '/composer.json',
            '{not valid json, but still mentions "type": "path" in here'
        ));

        $this->stageComposerJson();

        $result = $this->runHookScript();

        $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 2 to fall back to grep and block on undecodable JSON.');
    }

    /**
     * When `php` cannot be resolved via `PATH`, Guard 2's scoped check
     * cannot run at all and falls back to the blunt, file-wide grep —
     * which still blocks a real path repository. `bash`/`git`/`grep`
     * are restricted onto the throwaway `PATH` so the hook script and
     * its fallback can still run; `php` is deliberately excluded — AC-10.
     */
    public function test_guard2FallsBackWhenPhpUnavailable() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['repositories'] = array(
            array('type' => 'path', 'url' => '../local-clone'),
        );
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $restrictedPathDir = $this->createRestrictedPathDirectory('bash', 'git', 'grep');
        $originalPath = getenv('PATH');

        try {
            putenv('PATH=' . $restrictedPathDir);

            $result = $this->runHookScript();

            $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 2 to fall back to grep and block when php is unavailable.');
        } finally {
            if($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $originalPath);
            }

            FixtureFileSystem::removeDirectory($restrictedPathDir);
        }
    }

    /**
     * When `php` itself crashes while evaluating the staged content
     * (surfacing here as a fake `php` binary that always exits `255`,
     * the real exit code PHP uses for an uncaught fatal/parse error),
     * Guard 2 falls back to the blunt, file-wide grep rather than
     * treating the crash as a pass — AC-10.
     */
    public function test_guard2FallsBackWhenPhpCheckCrashes() : void
    {
        $this->installHooks();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $data['repositories'] = array(
            array('type' => 'path', 'url' => '../local-clone'),
        );
        $this->writeJsonFile($this->testTarget . '/composer.json', $data);

        $this->stageComposerJson();

        $restrictedPathDir = $this->createRestrictedPathDirectory('bash', 'git', 'grep');
        $fakePhpPath = $restrictedPathDir . '/php';

        $this->assertNotFalse(file_put_contents($fakePhpPath, "#!/usr/bin/env bash\nexit 255\n"));
        $this->assertTrue(chmod($fakePhpPath, 0755));

        $originalPath = getenv('PATH');

        try {
            putenv('PATH=' . $restrictedPathDir);

            $result = $this->runHookScript();

            $this->assertNotSame(0, $result->getExitCode(), 'Expected Guard 2 to fall back to grep and block when php crashes.');
        } finally {
            if($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $originalPath);
            }

            FixtureFileSystem::removeDirectory($restrictedPathDir);
        }
    }

    /**
     * When git is unavailable, {@see self::setUp()} skips every test in
     * this suite with a message naming git as the cause, rather than
     * letting `git init` fail the test outright.
     *
     * `PATH` is replaced with a directory holding only a `php` symlink,
     * and `COMPOSER_BINARY` is pinned to the real, absolute Composer
     * path — Composer's own entry script is a `#!/usr/bin/env php`
     * shell script, so `php` still needs to be resolvable via `PATH` for
     * {@see IntegrationTestCase::setUp()}'s own Composer-availability
     * check to keep passing. Without both of these, the Composer
     * invocation itself would fail first, and this test would observe
     * that unrelated skip message instead of the git-specific one it
     * exists to prove.
     */
    public function test_skipsWhenGitUnavailable() : void
    {
        $instance = new self('test_installHooksCopiesExecutableHook');

        $originalComposerBinary = getenv('COMPOSER_BINARY');
        $originalPath = getenv('PATH');

        $fakePathDir = $this->createRestrictedPathDirectory('php');

        putenv('COMPOSER_BINARY=' . $this->resolveComposerBinaryPath());
        putenv('PATH=' . $fakePathDir);

        $setUp = new ReflectionMethod(self::class, 'setUp');

        try {
            $setUp->invoke($instance);
            $this->fail('Expected setUp() to skip the test via markTestSkipped().');
        } catch (SkippedWithMessageException $e) {
            $this->assertStringContainsString('git', $e->getMessage());
        } finally {
            if($originalComposerBinary === false) {
                putenv('COMPOSER_BINARY');
            } else {
                putenv('COMPOSER_BINARY=' . $originalComposerBinary);
            }

            if($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $originalPath);
            }

            $this->removeWorkCopyOf($instance);

            FixtureFileSystem::removeDirectory($fakePathDir);
        }
    }

    // endregion

    // region: Support methods

    /**
     * Removes `$instance`'s own work copy directly, since it was set up
     * manually outside PHPUnit's lifecycle (via reflection-invoked
     * `setUp()`) and so never runs through its own `tearDown()`.
     */
    private function removeWorkCopyOf(IntegrationTestCase $instance) : void
    {
        FixtureFileSystem::removeDirectory($instance->testTarget);
    }

    /**
     * Resolves an absolute path to the real Composer binary while `PATH`
     * still has its normal value, so {@see self::test_skipsWhenGitUnavailable()}
     * can pin `COMPOSER_BINARY` to it before replacing `PATH`.
     */
    private function resolveComposerBinaryPath() : string
    {
        $existing = getenv('COMPOSER_BINARY');

        if(is_string($existing) && $existing !== '') {
            return $existing;
        }

        $resolved = (new ExecutableFinder())->find('composer');

        if($resolved === null) {
            $this->fail('Could not resolve an absolute path for the composer binary.');
        }

        return $resolved;
    }

    /**
     * Creates a throwaway directory containing only symlinks to the
     * named real binaries (resolved via the current, unrestricted
     * `PATH`), so it can serve as a restricted `PATH` for a test that
     * needs some binaries resolvable and others deliberately not —
     * e.g. `php` alone (to satisfy Composer's own `#!/usr/bin/env php`
     * shebang) without resolving `git`, or `bash`/`git`/`grep` (to let
     * the installed hook itself run and fall back to its grep check)
     * without resolving `php`.
     *
     * Skips (rather than fails) the calling test with a named reason
     * when a requested binary cannot be resolved on this host, since a
     * missing `bash`/`git`/`grep` here is an environment constraint,
     * not a defect in the code under test.
     */
    private function createRestrictedPathDirectory(string ...$binaries) : string
    {
        $finder = new ExecutableFinder();
        $dir = sys_get_temp_dir() . '/composer-switcher-restricted-path-' . uniqid('', true);

        if(!mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail(sprintf('Failed to create throwaway PATH directory: %s', $dir));
        }

        foreach($binaries as $binary) {
            $resolved = $finder->find($binary);

            if($resolved === null) {
                FixtureFileSystem::removeDirectory($dir);
                $this->markTestSkipped(sprintf(
                    'Skipped: could not resolve an absolute path for the %s binary on this host.',
                    $binary
                ));
            }

            if(!symlink($resolved, $dir . '/' . $binary)) {
                $this->fail(sprintf('Failed to symlink the %s binary into the throwaway PATH directory.', $binary));
            }
        }

        return $dir;
    }

    /**
     * The single choke-point through which every `git` invocation in this
     * suite passes, bound to {@see self::$testTarget} — the same lazy
     * pattern as {@see IntegrationTestCase::getComposerRunner()}.
     */
    private function getGitRunner() : GitRunner
    {
        if($this->gitRunner === null) {
            $this->gitRunner = new GitRunner($this->testTarget);
        }

        return $this->gitRunner;
    }

    private function installHooks() : void
    {
        $this->runComposerChecked('switch-install-hooks');
    }

    private function stageComposerJson() : void
    {
        $result = $this->getGitRunner()->run('add', 'composer.json');

        if(!$result->isSuccess()) {
            $this->fail(sprintf(
                "stageComposerJson() failed: 'git add composer.json' exited with code %d.\nOutput:\n%s",
                $result->getExitCode(),
                $result->getOutput() . $result->getErrorOutput()
            ));
        }
    }

    /**
     * Runs the hook installed at `.git/hooks/pre-commit` directly,
     * exactly as git itself would invoke it before a commit.
     *
     * The returned {@see ProcessResult}'s `getOutput()` combines stdout and
     * stderr (mirroring how a real pre-commit invocation surfaces its
     * combined output to the committer), while `getErrorOutput()` still
     * holds stderr alone.
     */
    private function runHookScript() : ProcessResult
    {
        $hookPath = $this->testTarget . '/.git/hooks/pre-commit';

        $this->assertFileExists($hookPath, 'Expected the hook to already be installed before running it.');

        $process = new Process(array($hookPath), $this->testTarget);
        $process->run();

        return new ProcessResult(
            $process->getExitCode() ?? -1,
            $process->getOutput() . $process->getErrorOutput(),
            $process->getErrorOutput()
        );
    }

    // endregion
}
