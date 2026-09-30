<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use DirectoryIterator;
use RuntimeException;

/**
 * Owns the lifecycle of a single ephemeral work copy directory: collision-free
 * name allocation, creation from a fixture, removal, and age-based purging of
 * abandoned copies left behind by earlier, aborted runs.
 *
 * Replaces the ad hoc `date('YmdHi') . '-' . $counter` naming previously
 * inlined in {@see ComposerSwitcherTestCase::setUp()}, which only had
 * minute resolution and could collide between two runs started in the
 * same minute. {@see self::allocate()} instead embeds the second-resolution
 * timestamp, the process ID, and a per-process counter, so two allocations
 * from the same process are always distinct even within the same second.
 */
final class WorkCopy
{
    /**
     * The age, in seconds, past which a work copy is considered abandoned
     * and eligible for {@see self::purgeStale()} to remove it.
     *
     * Chosen so a work copy retained overnight by a failed test (via
     * `ComposerSwitcherTestCase::setKeepWorkFiles()`) is still present for
     * next-morning inspection, rather than being purged within the hour.
     */
    public const STALE_AFTER_SECONDS = 86400;

    /**
     * @var int
     */
    private static int $counter = 0;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Allocates a collision-free path for a new work copy under `$workRoot`,
     * without creating the directory itself. Callers create the directory
     * either via {@see self::createFromFixture()} or by creating it
     * themselves at the returned path.
     *
     * @param string $workRoot
     * @return self
     *
     * @throws RuntimeException If the allocated path already exists.
     */
    public static function allocate(string $workRoot): self
    {
        self::$counter++;

        $path = $workRoot . '/' . date('YmdHis') . '-' . getmypid() . '-' . self::$counter;

        if (is_dir($path) || is_file($path) || is_link($path)) {
            throw new RuntimeException(sprintf(
                'Cannot allocate work copy: path already exists at [%s].',
                $path
            ));
        }

        return new self($path);
    }

    /**
     * Creates this work copy's directory by copying `$fixtureDir` into it.
     *
     * @param string $fixtureDir
     * @return void
     *
     * @throws RuntimeException If this work copy's path already exists.
     */
    public function createFromFixture(string $fixtureDir): void
    {
        if (is_dir($this->path) || is_file($this->path) || is_link($this->path)) {
            throw new RuntimeException(sprintf(
                'Cannot create work copy: path already exists at [%s].',
                $this->path
            ));
        }

        FixtureFileSystem::copyDirectory($fixtureDir, $this->path);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Removes this work copy's directory, if present.
     *
     * @return void
     */
    public function remove(): void
    {
        FixtureFileSystem::removeDirectory($this->path);
    }

    /**
     * Removes top-level entries of `$workRoot` whose modification time is
     * older than `$maxAgeSeconds`, leaving fresher entries untouched.
     *
     * Symlinked entries are unlinked directly rather than being followed
     * and recursively removed, so a stale symlink never causes its target
     * to be deleted.
     *
     * @param string $workRoot
     * @param int $maxAgeSeconds
     * @return int The number of top-level entries removed.
     */
    public static function purgeStale(string $workRoot, int $maxAgeSeconds = self::STALE_AFTER_SECONDS): int
    {
        if (!is_dir($workRoot)) {
            return 0;
        }

        $removed = 0;
        $threshold = time() - $maxAgeSeconds;

        foreach (new DirectoryIterator($workRoot) as $entry)
        {
            if ($entry->isDot()) {
                continue;
            }

            $pathname = $entry->getPathname();

            // For symlinks, the entry's own modification time is read via
            // lstat() rather than DirectoryIterator::getMTime(), which
            // follows the link and would error on a broken one.
            if ($entry->isLink()) {
                $linkStat = lstat($pathname);
                $modifiedAt = $linkStat !== false ? $linkStat['mtime'] : $threshold;
            } else {
                $modifiedAt = $entry->getMTime();
            }

            if ($modifiedAt >= $threshold) {
                continue;
            }

            if ($entry->isLink()) {
                unlink($pathname);
            } elseif ($entry->isDir()) {
                FixtureFileSystem::removeDirectory($pathname);
            } else {
                unlink($pathname);
            }

            $removed++;
        }

        return $removed;
    }
}
