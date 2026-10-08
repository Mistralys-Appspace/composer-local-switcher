<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use FilesystemIterator;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Verifies the dry-run preview built on the {@see \Mistralys\ComposerSwitcher\Utils\FileSystem}
 * overlay: {@see ConfigSwitcher::previewSwitch()} runs the real
 * `switchTo()` code path with the dry-run flag set, so it never
 * touches disk and always reports the same operations a real switch
 * from the same starting state would perform. It also carries the
 * static choke-point guard scan that enforces the "no PHP filesystem
 * function outside `FileSystem`" rule across the whole `src/` tree.
 */
final class TestDryRun extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * A preview must leave every file on disk byte-identical and every
     * modification time unchanged, for both target modes, from both
     * the INITIAL and PROD starting states.
     */
    public function test_previewLeavesDiskUntouched() : void
    {
        // From the INITIAL state (this test's own fixture work copy).
        $initialSwitcher = $this->createSwitcher();
        $this->assertPreviewLeavesDiskUntouched($initialSwitcher, $this->testTarget, ConfigSwitcher::MODE_DEV);
        $this->assertPreviewLeavesDiskUntouched($initialSwitcher, $this->testTarget, ConfigSwitcher::MODE_PROD);

        // From the PROD state, on an independent work copy so the
        // INITIAL-state assertions above are unaffected.
        $prodWorkCopy = $this->allocateWorkCopy();
        $prodSwitcher = $this->createSwitcherAt($prodWorkCopy->getPath());
        $prodSwitcher->switchToProduction();

        $this->assertPreviewLeavesDiskUntouched($prodSwitcher, $prodWorkCopy->getPath(), ConfigSwitcher::MODE_DEV);
        $this->assertPreviewLeavesDiskUntouched($prodSwitcher, $prodWorkCopy->getPath(), ConfigSwitcher::MODE_PROD);
    }

    /**
     * A preview's operation list (type/source/target, in order) must
     * match the operation list a real switch from the same starting
     * state performs, on an independent, identically-seeded work copy.
     */
    public function test_previewOperationsMatchRealSwitch() : void
    {
        $previewWorkCopy = $this->allocateWorkCopy();

        try {
            $previewSwitcher = $this->createSwitcherAt($previewWorkCopy->getPath());
            $previewOutcome = $previewSwitcher->previewSwitch(ConfigSwitcher::MODE_DEV);

            $realSwitcher = $this->createSwitcher();
            $realOutcome = $realSwitcher->switchToDevelopment();

            // Paths are relativized to each work copy's own root before
            // comparing, since the preview and the real switch run
            // against two independent, differently-named work copies —
            // only the operations' shape (type/source/target relative
            // to their root, in order) is expected to match, not the
            // absolute paths themselves.
            $this->assertSame(
                $this->summarizeOperations($previewOutcome->getOperations(), $previewWorkCopy->getPath()),
                $this->summarizeOperations($realOutcome->getOperations(), $this->testTarget)
            );
        } finally {
            $previewWorkCopy->remove();
        }
    }

    /**
     * Previewing a switch from the INITIAL state — where the prod
     * config does not yet exist — must complete without throwing,
     * proving the dry-run overlay serves the pending write performed
     * by {@see ConfigSwitcher} while initializing the production files
     * to `switch_adjustConfigForDev()`, which reads the prod config
     * back before it has ever touched disk.
     */
    public function test_previewOfInitialSwitchSucceeds() : void
    {
        $switcher = $this->createSwitcher();

        $this->assertFalse($switcher->getProdFile()->exists());

        $outcome = $switcher->previewSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($outcome->hasOperations());
        $this->assertFalse($switcher->getProdFile()->exists());
    }

    /**
     * A real `switchToDevelopment()` must return a {@see \Mistralys\ComposerSwitcher\State\SwitchOutcome}
     * whose operations are all flagged applied, and each named target
     * must actually exist on disk afterward.
     */
    public function test_realSwitchOperationsAreApplied() : void
    {
        $switcher = $this->createSwitcher();

        $outcome = $switcher->switchToDevelopment();

        $this->assertTrue($outcome->hasOperations());

        foreach($outcome->getOperations() as $operation)
        {
            $this->assertTrue(
                $operation->isApplied(),
                'Expected operation to be applied: ' . $operation->getTargetPath()
            );

            if($operation->getType() !== FileOperation::TYPE_DELETE) {
                $this->assertFileExists($operation->getTargetPath());
            }
        }
    }

    /**
     * Forcing a failure mid-preview (by deleting the dev config the
     * preview needs to read) must leave the facade's dry-run flag
     * restored to `false` afterward — via `switchTo()`'s `finally`
     * block — and a subsequent real switch to PROD (which does not
     * need the dev config) must still write to disk normally.
     */
    public function test_dryRunFlagRestoredAfterException() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getDevFile()->getPath());

        try {
            $switcher->previewSwitch(ConfigSwitcher::MODE_DEV);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_DEV_FILE_MISSING, $e->getCode());
        }

        $this->assertFalse($switcher->getFileSystem()->isDryRun());

        // A subsequent real switch (to a mode that does not need the
        // dev config) must still be able to write to disk normally.
        $switcher->switchToProduction();

        $this->assertTrue($switcher->getStatus()->isPROD());
        $this->assertFalse($switcher->getFileSystem()->isDryRun());
    }

    /**
     * A PROD-mode preview, with `composer.json` drifted from its
     * original content, must leave every file on disk byte-identical.
     * Under the v3 switching model (this plan's WP-008), a plain
     * PROD→PROD switch no longer reconciles anything (that machinery
     * existed only because two editable copies of the config were
     * committed) — a drifted `composer.json` produces no `copy`
     * operation either way; the only operations a PROD→PROD switch
     * still ever produces are the unconditional flag-file rewrite
     * (delete + recreate), which {@see ConfigSwitcher::writeFlagFiles()}
     * performs on every switch regardless of row.
     */
    public function test_previewProdInDriftedProdStateLeavesDiskUntouched() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();
        $this->driftMainConfig($switcher);

        $before = $this->snapshotDirectory($this->testTarget);

        $outcome = $switcher->previewSwitch(ConfigSwitcher::MODE_PROD);

        $after = $this->snapshotDirectory($this->testTarget);

        $this->assertSame($before, $after, 'Preview of a drifted PROD state must leave disk untouched.');
        $this->assertTrue($outcome->isDryRun());
        $this->assertFalse($switcher->getFileSystem()->isDryRun());

        $operationTypes = array_map(
            static fn(FileOperation $operation) : string => $operation->getType(),
            $outcome->getOperations()
        );
        $this->assertNotContains(FileOperation::TYPE_COPY, $operationTypes, 'No reconcile copy operation must be planned.');

        $messageCodes = array_map(
            static fn($message) : int => $message->getCode(),
            $outcome->getMessages()
        );
        $this->assertSame(
            1,
            count(array_filter($messageCodes, static fn(int $code) : bool => $code === ConfigSwitcher::MESSAGE_DRY_RUN_ACTIVE)),
            'Expected exactly one MESSAGE_DRY_RUN_ACTIVE.'
        );
    }

    /**
     * The same drifted-PROD preview's operation list (type/source/target
     * shape) must match what a real `switchToProduction()` from an
     * identically-seeded, independent work copy actually performs on
     * disk.
     */
    public function test_previewProdMatchesRealSwitchInDriftedProdState() : void
    {
        $previewWorkCopy = $this->allocateWorkCopy();

        try {
            $previewSwitcher = $this->createSwitcherAt($previewWorkCopy->getPath());
            $previewSwitcher->switchToProduction();
            $this->driftMainConfig($previewSwitcher);
            $previewOutcome = $previewSwitcher->previewSwitch(ConfigSwitcher::MODE_PROD);

            $realSwitcher = $this->createSwitcher();
            $realSwitcher->switchToProduction();
            $this->driftMainConfig($realSwitcher);
            $realOutcome = $realSwitcher->switchToProduction();

            $this->assertSame(
                $this->summarizeOperations($previewOutcome->getOperations(), $previewWorkCopy->getPath()),
                $this->summarizeOperations($realOutcome->getOperations(), $this->testTarget)
            );

            $this->assertFalse($previewSwitcher->getFileSystem()->isDryRun());
        } finally {
            $previewWorkCopy->remove();
        }
    }

    /**
     * No PHP filesystem function (`file_get_contents`, `file_put_contents`,
     * `copy(`, `unlink(`, `file_exists(`, `filemtime(`) may appear
     * anywhere in `src/` outside `src/Utils/FileSystem.php` — the
     * single choke-point every file mutation passes through — except
     * within `ConfigSwitcher::installGitHooks()`, which is a deliberate,
     * narrowly-scoped exception (copying the bundled git hook resource
     * is unrelated to the switch/dry-run machinery this rule protects).
     */
    public function test_noFilesystemCallsOutsideFacade() : void
    {
        $forbidden = array('file_get_contents', 'file_put_contents', 'copy(', 'unlink(', 'file_exists(', 'filemtime(');

        $srcRoot = realpath(__DIR__ . '/../../src');
        $this->assertNotFalse($srcRoot);

        $facadePath = realpath($srcRoot . '/Utils/FileSystem.php');
        $this->assertNotFalse($facadePath);

        $violations = array();

        foreach($this->collectPhpFiles($srcRoot) as $file)
        {
            if(realpath($file) === $facadePath) {
                continue;
            }

            $content = file_get_contents($file);
            $this->assertNotFalse($content, 'Failed to read source file: ' . $file);

            if(basename($file) === 'ConfigSwitcher.php') {
                $content = $this->stripInstallGitHooksBody($content);
            }

            foreach($forbidden as $needle)
            {
                // A negative lookbehind excludes `->copy(`/`::copy(` (a
                // method call on the FileSystem facade itself, which is
                // exactly what every other file is expected to use) and
                // a longer identifier ending the same way (e.g. a
                // hypothetical `xcopy(`) — only a bare, global function
                // call is a violation of the choke-point rule.
                $pattern = '/(?<![A-Za-z0-9_>:])' . preg_quote(rtrim($needle, '('), '/') . '\(/';

                if(preg_match($pattern, $content) === 1) {
                    $violations[] = $file . ' contains forbidden call [' . $needle . ']';
                }
            }
        }

        $this->assertSame(array(), $violations, implode(PHP_EOL, $violations));
    }

    // endregion

    // region: Support methods

    /**
     * @var WorkCopy[]
     */
    private array $extraWorkCopies = array();

    protected function tearDown() : void
    {
        foreach($this->extraWorkCopies as $workCopy) {
            $workCopy->remove();
        }

        $this->extraWorkCopies = array();

        parent::tearDown();
    }

    /**
     * Allocates and tracks an independent work copy of this test's
     * fixture, removed in {@see self::tearDown()}.
     */
    private function allocateWorkCopy() : WorkCopy
    {
        $workCopy = WorkCopy::allocate($this->assetsFolder . '/work-projects');
        $workCopy->createFromFixture($this->testSource);

        $this->extraWorkCopies[] = $workCopy;

        return $workCopy;
    }

    private function createSwitcherAt(string $target) : ConfigSwitcher
    {
        return (new ConfigSwitcher(
            new ConfigFile($target . '/composer.json'),
            new ConfigFile($target . '/composer/composer-prod.json'),
            new ConfigFile($target . '/composer/local-repositories.json')
        ))
            ->setWriteToConsole(true);
    }

    /**
     * Modifies `composer.json`'s content, simulating a user edit while
     * in PROD mode — under v3 this has no reconciliation implications
     * (that machinery is retired); it simply exercises a plain
     * PROD→PROD switch against a drifted `composer.json`.
     */
    private function driftMainConfig(ConfigSwitcher $switcher) : void
    {
        $config = $switcher->getMainFile()->getData();
        $config['require']['php'] = '>=8.0';
        $switcher->getMainFile()->putData($config);
    }

    private function assertPreviewLeavesDiskUntouched(ConfigSwitcher $switcher, string $root, string $mode) : void
    {
        $before = $this->snapshotDirectory($root);

        $switcher->previewSwitch($mode);

        $after = $this->snapshotDirectory($root);

        $this->assertSame($before, $after, 'Preview of mode [' . $mode . '] must leave disk untouched.');
    }

    /**
     * @param string $root
     * @return array<string,array{content:string,mtime:int|false}>
     */
    private function snapshotDirectory(string $root) : array
    {
        $snapshot = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach($iterator as $fileInfo)
        {
            if(!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();

            $snapshot[$path] = array(
                'content' => file_get_contents($path),
                'mtime' => filemtime($path)
            );
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param FileOperation[] $operations
     * @param string $root The work copy root to strip from each path, so operations from two independent work copies can be compared by shape alone.
     * @return array<int,array{type:string,source:string|null,target:string}>
     */
    private function summarizeOperations(array $operations, string $root) : array
    {
        return array_map(
            fn(FileOperation $operation) : array => array(
                'type' => $operation->getType(),
                'source' => $operation->getSourcePath() !== null ? $this->relativizePath($operation->getSourcePath(), $root) : null,
                'target' => $this->relativizePath($operation->getTargetPath(), $root)
            ),
            $operations
        );
    }

    private function relativizePath(string $path, string $root) : string
    {
        return ltrim(substr($path, strlen($root)), '/');
    }

    /**
     * Removes the body of `ConfigSwitcher::installGitHooks()` from the
     * given file content, so the guard scan's forbidden-call search
     * ignores that one, deliberately-exempted method.
     */
    private function stripInstallGitHooksBody(string $content) : string
    {
        $start = strpos($content, 'function installGitHooks(');
        $this->assertNotFalse($start, 'installGitHooks() method not found in ConfigSwitcher.php.');

        $bodyStart = strpos($content, '{', $start);
        $this->assertNotFalse($bodyStart);

        $depth = 0;
        $end = $bodyStart;

        for($i = $bodyStart, $len = strlen($content); $i < $len; $i++)
        {
            if($content[$i] === '{') {
                $depth++;
            } else if($content[$i] === '}') {
                $depth--;

                if($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($content, 0, $start) . substr($content, $end + 1);
    }

    // endregion
}
