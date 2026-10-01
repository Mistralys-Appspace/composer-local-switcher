<?php

declare(strict_types=1);

use Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy;

require __DIR__ . '/../vendor/autoload.php';

WorkCopy::purgeStale(__DIR__ . '/assets/work-projects');
