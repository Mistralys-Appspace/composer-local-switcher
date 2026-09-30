<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Symlink-safe directory removal and copy primitives shared by the test
 * harness's work-copy lifecycle classes.
 *
 * Both methods were originally private helpers on
 * {@see ComposerSwitcherTestCase}; they are hoisted here so
 * {@see WorkCopy} and any future harness class can reuse the same,
 * already-proven symlink-safe traversal instead of re-implementing it.
 */
final class FixtureFileSystem
{
    /**
     * Recursively removes a directory, including symlinked entries.
     *
     * Symlinks are unlinked directly rather than followed, so a symlink
     * pointing outside the directory being removed never causes its
     * target to be deleted.
     *
     * A no-op when `$dir` does not exist.
     *
     * @param string $dir
     * @return void
     */
    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item)
        {
            /* @var $item SplFileInfo */

            if ($item->isLink()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Recursively copies a directory tree from `$src` to `$dst`.
     *
     * Unlike the legacy `ComposerSwitcherTestCase::copyDirectory()` this
     * replaces, an already-existing `$dst` is never silently merged into:
     * it is treated as a collision and rejected.
     *
     * @param string $src
     * @param string $dst
     * @return void
     *
     * @throws RuntimeException If `$dst` already exists.
     */
    public static function copyDirectory(string $src, string $dst): void
    {
        if (is_dir($dst) || is_file($dst) || is_link($dst)) {
            throw new RuntimeException(sprintf(
                'Cannot copy directory: destination already exists at [%s].',
                $dst
            ));
        }

        mkdir($dst, 0777, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /* @var $items RecursiveDirectoryIterator */

        foreach ($items as $item)
        {
            $targetPath = $dst . DIRECTORY_SEPARATOR . $items->getSubPathName();

            if ($item->isDir()) {
                mkdir($targetPath, 0777, true);
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }
}
