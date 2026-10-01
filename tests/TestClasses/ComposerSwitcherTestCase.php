<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use PHPUnit\Framework\TestCase;

abstract class ComposerSwitcherTestCase extends TestCase
{
    protected string $assetsFolder;

    protected string $testSource;

    protected string $testTarget;

    private static int $testCounter = 0;

    private bool $keepWorkFiles = false;

    private WorkCopy $workCopy;

    protected function setUp(): void
    {
        parent::setUp();

        self::$testCounter++;

        $this->keepWorkFiles = false;
        $this->assetsFolder = __DIR__ . '/../assets';

        $this->testSource = $this->assetsFolder . '/' . $this->getFixtureSourceDir();

        $this->workCopy = WorkCopy::allocate($this->assetsFolder . '/work-projects');
        $this->workCopy->createFromFixture($this->testSource);

        $this->testTarget = $this->workCopy->getPath();
    }

    /**
     * The name of the fixture directory (relative to {@see $assetsFolder})
     * that is copied into the work directory in {@see setUp()}.
     *
     * Override this in a subclass to point {@see setUp()} at a different
     * fixture source while reusing the same copy and teardown logic.
     *
     * @return string
     */
    protected function getFixtureSourceDir() : string
    {
        return 'test-project';
    }

    protected function setKeepWorkFiles(bool $keep=true) : void
    {
        $this->keepWorkFiles = $keep;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if(!$this->keepWorkFiles && !$this->status()->isFailure() && !$this->status()->isError()) {
            $this->workCopy->remove();
        } else {
            echo PHP_EOL;
            echo sprintf("Test target retained for inspection: %s", basename($this->testTarget));
            echo PHP_EOL;
        }
    }

    protected function createSwitcher() : ConfigSwitcher
    {
        return (new ConfigSwitcher(
            new ConfigFile($this->testTarget . '/composer.json'),
            new ConfigFile($this->testTarget . '/composer/composer-prod.json'),
            new ConfigFile($this->testTarget . '/composer/local-repositories.json')
        ))
            ->setWriteToConsole(true);
    }
}
