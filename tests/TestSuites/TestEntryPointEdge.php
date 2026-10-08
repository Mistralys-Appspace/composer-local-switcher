<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\ComposerCommand;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ScriptedEventContext;
use Mistralys\ComposerSwitcher\Utils\ComposerProcess;
use Mistralys\ComposerSwitcher\Utils\OutcomeRenderer;
use Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner;

/**
 * Verifies the entry-point edge end to end against a fake `composer`
 * binary ({@see self::fakeComposerPath()}): real command execution,
 * the `--no-install`/`--with-dependencies`/`--no-interaction` flag
 * handling, the nested-run guard, failure mapping, and — via two
 * source scans in the style of `TestDryRun::test_noFilesystemCallsOutsideFacade` —
 * that `ConfigSwitcher`'s static entry points hold no control flow
 * beyond building an `EventContext`/`SwitchCommandRunner`, and that
 * `method_exists` duck-typing occurs nowhere in `src/` except inside
 * `EventContext`.
 */
final class TestEntryPointEdge extends ComposerSwitcherTestCase
{
    private ?string $recordFile = null;

    private ?string $previousNestedEnv = null;

    protected function setUp() : void
    {
        parent::setUp();

        $this->recordFile = $this->assetsFolder . '/work-projects/fake-composer-record-' . uniqid('', true) . '.json';
        $this->previousNestedEnv = getenv('COMPOSER_SWITCHER_NESTED') ?: null;

        putenv('FAKE_COMPOSER_RECORD_FILE=' . $this->recordFile);
        putenv('FAKE_COMPOSER_EXIT_CODE=0');
    }

    protected function tearDown() : void
    {
        if($this->recordFile !== null && file_exists($this->recordFile)) {
            unlink($this->recordFile);
        }

        putenv('FAKE_COMPOSER_RECORD_FILE');
        putenv('FAKE_COMPOSER_EXIT_CODE');

        if($this->previousNestedEnv !== null) {
            putenv('COMPOSER_SWITCHER_NESTED=' . $this->previousNestedEnv);
        } else {
            putenv('COMPOSER_SWITCHER_NESTED');
        }

        parent::tearDown();
    }

    // region: _Tests

    public function test_switchDevExecutesPlannedCommandOnce() : void
    {
        $runner = $this->createRunner(array('--yes'));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $record = $this->readRecord();

        $this->assertSame('update', $record['argv'][0]);
        $this->assertContains('mistralys/application_framework', $record['argv']);
        $this->assertContains('mistralys/application-utils-core', $record['argv']);
        $this->assertContains('mistralys/application-utils', $record['argv']);
    }

    public function test_noInstallPrintsCommandWithoutExecuting() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array('--yes', '--no-install'));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertFileDoesNotExist($this->recordFile);
        $this->assertTrue($context->hasWrittenLineContaining('to finish'));
    }

    public function test_withDependenciesIsForwarded() : void
    {
        $runner = $this->createRunner(array('--yes', '--with-dependencies'));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $record = $this->readRecord();

        $this->assertContains('--with-dependencies', $record['argv']);
    }

    public function test_failedCommandThrowsComposerCommandFailedWithContext() : void
    {
        putenv('FAKE_COMPOSER_EXIT_CODE=5');

        $runner = $this->createRunner(array('--yes'));

        try {
            $runner->runSwitch(ConfigSwitcher::MODE_DEV);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED, $e->getCode());
            $this->assertSame(5, $e->getContextValue(ComposerSwitcherException::KEY_EXIT_CODE));
            $this->assertIsString($e->getContextValue(ComposerSwitcherException::KEY_COMMAND));
        }
    }

    public function test_blockedSwitchThrowsSwitchBlocked() : void
    {
        // Corrupt the main lock's content hash so the main lock is
        // Stale, blocking a PROD/INITIAL->DEV switch per the decision
        // table.
        $lockPath = $this->testTarget . '/composer.lock';
        $lockData = json_decode((string)file_get_contents($lockPath), true);
        $lockData['content-hash'] = 'not-a-real-hash';
        file_put_contents($lockPath, json_encode($lockData));

        $runner = $this->createRunner(array('--yes'));

        $this->expectException(ComposerSwitcherException::class);
        $this->expectExceptionCode(ComposerSwitcherException::ERROR_SWITCH_BLOCKED);

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);
    }

    public function test_nestedEnvSkipsSwitch() : void
    {
        putenv('COMPOSER_SWITCHER_NESTED=1');

        [$runner, $context] = $this->createRunnerWithContext(array('--yes'));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertFileDoesNotExist($this->recordFile);
        $this->assertTrue($context->hasWrittenLineContaining('[182224]'));
        $this->assertFalse($this->createSwitcher()->getStatus()->exists());
    }

    public function test_childProcessReceivesNestedEnv() : void
    {
        $runner = $this->createRunner(array('--yes'));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $record = $this->readRecord();

        $this->assertTrue($record['nested']);
    }

    public function test_noInteractionForwardedWhenParentNonInteractive() : void
    {
        $runner = $this->createRunner(array('--yes'), interactive: false);

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $record = $this->readRecord();

        $this->assertContains('--no-interaction', $record['argv']);
    }

    public function test_noInteractionNotForwardedWhenParentInteractive() : void
    {
        $runner = $this->createRunner(array(), interactive: true, queuedAnswers: array(true));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $record = $this->readRecord();

        $this->assertNotContains('--no-interaction', $record['argv']);
    }

    public function test_missingBinaryThrowsBinaryNotFound() : void
    {
        $previousBinary = getenv('COMPOSER_BINARY') ?: null;
        $previousPath = getenv('PATH') ?: null;

        putenv('COMPOSER_BINARY=/nonexistent/composer-binary');
        putenv('PATH=/nonexistent/empty-path');

        try {
            $process = new ComposerProcess();

            $this->expectException(ComposerSwitcherException::class);
            $this->expectExceptionCode(ComposerSwitcherException::ERROR_COMPOSER_BINARY_NOT_FOUND);

            $process->run(
                new ComposerCommand(array('install'), 'test'),
                $this->testTarget
            );
        } finally {
            if($previousBinary !== null) {
                putenv('COMPOSER_BINARY=' . $previousBinary);
            } else {
                putenv('COMPOSER_BINARY');
            }

            if($previousPath !== null) {
                putenv('PATH=' . $previousPath);
            } else {
                putenv('PATH');
            }
        }
    }

    public function test_previewEntryPointsNeverExecute() : void
    {
        $runner = $this->createRunner(array());

        $runner->runPreview(ConfigSwitcher::MODE_DEV);

        $this->assertFileDoesNotExist($this->recordFile);
        $this->assertFalse($this->createSwitcher()->getStatus()->exists());
    }

    /**
     * Source scan (in the style of `TestDryRun::test_noFilesystemCallsOutsideFacade`):
     * every `composerSwitch*`/`composerSwitchPreview*` static entry
     * point's body contains no control-flow keyword — each is a true
     * one-line delegation to `self::buildRunner($event)` (or, for the
     * two describe statics, directly to `EventContext`/`OutcomeRenderer`),
     * never branching, looping or catching itself.
     */
    public function test_staticEntryPointsHoldNoControlFlow() : void
    {
        $path = __DIR__ . '/../../src/ConfigSwitcher.php';
        $content = (string)file_get_contents($path);

        $entryPoints = array(
            'composerSwitchDev',
            'composerSwitchProd',
            'composerSwitchUpdate',
            'composerSwitchDescribe',
            'composerSwitchDescribeJson',
            'composerSwitchPreviewDev',
            'composerSwitchPreviewProd',
        );

        $controlFlowKeywords = array('if(', 'if (', 'foreach(', 'foreach (', 'while(', 'while (', 'switch(', 'switch (', 'try{', 'try {', 'catch(', 'catch (');

        foreach($entryPoints as $methodName)
        {
            $body = $this->extractMethodBody($content, $methodName);

            foreach($controlFlowKeywords as $keyword)
            {
                $this->assertStringNotContainsString(
                    $keyword,
                    $body,
                    sprintf('ConfigSwitcher::%s() must hold no control flow; found [%s].', $methodName, trim($keyword))
                );
            }
        }
    }

    /**
     * Source scan: `method_exists(` appears nowhere in `src/` except
     * inside `src/Utils/EventContext.php` — the single duck-typing
     * site this plan confines all Composer event/IO probing to.
     */
    public function test_methodExistsProbingOnlyInEventContext() : void
    {
        $srcRoot = realpath(__DIR__ . '/../../src');
        $this->assertNotFalse($srcRoot);

        $eventContextPath = realpath($srcRoot . '/Utils/EventContext.php');
        $this->assertNotFalse($eventContextPath);

        $violations = array();

        foreach($this->collectPhpFiles($srcRoot) as $file)
        {
            if(realpath($file) === $eventContextPath) {
                continue;
            }

            $content = (string)file_get_contents($file);

            if(str_contains($content, 'method_exists(')) {
                $violations[] = $file;
            }
        }

        $this->assertSame(array(), $violations, implode(PHP_EOL, $violations));
    }

    // endregion

    // region: Support methods

    /**
     * @param string[] $arguments
     * @param bool[] $queuedAnswers
     */
    private function createRunner(array $arguments, bool $interactive = true, array $queuedAnswers = array()) : SwitchCommandRunner
    {
        [$runner] = $this->createRunnerWithContext($arguments, $interactive, $queuedAnswers);

        return $runner;
    }

    /**
     * @param string[] $arguments
     * @param bool[] $queuedAnswers
     * @return array{0:SwitchCommandRunner,1:ScriptedEventContext}
     */
    private function createRunnerWithContext(array $arguments, bool $interactive = true, array $queuedAnswers = array()) : array
    {
        $context = new ScriptedEventContext($arguments, $interactive, $queuedAnswers);
        $switcher = $this->createSwitcher();
        $process = new ComposerProcess($this->fakeComposerPath());
        $renderer = new OutcomeRenderer($context);

        return array(new SwitchCommandRunner($context, $switcher, $process, $renderer), $context);
    }

    private function fakeComposerPath() : string
    {
        return __DIR__ . '/../assets/fake-composer/composer.php';
    }

    /**
     * @return array{argv:string[],nested:bool}
     */
    private function readRecord() : array
    {
        $this->assertFileExists($this->recordFile, 'The fake Composer binary did not write a record file.');

        /** @var array{argv:string[],nested:bool} $record */
        $record = json_decode((string)file_get_contents($this->recordFile), true);

        return $record;
    }

    private function extractMethodBody(string $content, string $methodName) : string
    {
        $start = strpos($content, 'function ' . $methodName . '(');
        $this->assertNotFalse($start, sprintf('Method %s() not found.', $methodName));

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

        return substr($content, $bodyStart, $end - $bodyStart + 1);
    }

    // endregion
}
