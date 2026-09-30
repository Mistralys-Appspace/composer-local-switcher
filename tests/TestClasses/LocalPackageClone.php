<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

/**
 * Owns acquisition of the local clone of the package switched by the
 * Tier 2 integration fixture (`mistralys/simple_html_dom`).
 *
 * Availability is decided once per call to {@see self::ensureAvailable()},
 * so every Tier 2 suite that depends on this clone reports a uniform,
 * cause-naming skip instead of independently discovering the same problem.
 *
 * Acquisition is atomic: `git clone` always lands in a uniquely-named
 * `.partial-*` sibling directory first, which is only {@see rename()}d onto
 * the cache path once its `composer.json` has been verified present. A
 * reader of the cache path therefore never observes a partially-cloned
 * directory — {@see self::REASON_INVALID_DIRECTORY} can only mean a
 * directory that was invalid for some other reason (e.g. hand-edited),
 * never an interrupted clone. Both the cache directory and the repository
 * URL are constructor-injectable so Tier 1 tests can exercise every code
 * path without ever touching the real, shared clone cache that Tier 2
 * suites reuse across concurrent sessions.
 *
 * This class must never run `composer install` or `composer update` inside
 * the clone: the absence of a `vendor/` folder there is part of the
 * documented no-vendor-clone workflow, and the clone's own dev dependencies
 * would collide with this project's test tooling if installed.
 */
class LocalPackageClone
{
    private const REPOSITORY_URL = 'https://github.com/Mistralys/simple_html_dom.git';

    /**
     * Prefix used for the temporary sibling directory a clone is created
     * in before being {@see rename()}d onto the cache path.
     */
    private const PARTIAL_PREFIX = '.partial-';

    /**
     * The age, in seconds, past which a `.partial-*` sibling is considered
     * abandoned (e.g. left behind by a process killed mid-clone) and is
     * purged by {@see self::ensureAvailable()} rather than left to
     * accumulate indefinitely.
     */
    public const PARTIAL_STALE_AFTER_SECONDS = 600;

    public const REASON_NONE = '';
    public const REASON_GIT_UNAVAILABLE = 'git_unavailable';
    public const REASON_CLONE_FAILED = 'clone_failed';
    public const REASON_INVALID_DIRECTORY = 'invalid_directory';

    /**
     * @var string
     */
    private string $unavailableReason = self::REASON_NONE;

    private readonly GitRunner $gitRunner;

    /**
     * @param string|null $cacheDirectory Absolute path the clone is cached
     *        at. NULL (the default) falls back to the shared, repository-wide
     *        cache directory under `tests/assets/local-clones/`.
     * @param string $repositoryUrl Repository to clone. Defaults to the
     *        real `mistralys/simple_html_dom` repository; overridable so
     *        Tier 2 tests can point at a throwaway local repository instead.
     */
    public function __construct(
        private readonly ?string $cacheDirectory = null,
        private readonly string $repositoryUrl = self::REPOSITORY_URL
    )
    {
        $this->gitRunner = new GitRunner();
    }

    /**
     * The absolute path to the cache directory the clone lives in,
     * whether or not it currently exists: the injected path, or the
     * shared default when none was injected.
     *
     * @return string
     */
    public function getCacheDirectory() : string
    {
        if($this->cacheDirectory !== null) {
            return $this->cacheDirectory;
        }

        return __DIR__ . '/../assets/local-clones/simple_html_dom';
    }

    /**
     * Ensures the local clone is available, cloning it when the cache
     * directory is absent. Never invokes `composer install` or
     * `composer update` against the resulting directory.
     *
     * Acquisition is atomic: the clone always lands in a `.partial-*`
     * sibling first and is only promoted to the cache path (via
     * {@see rename()}) once verified complete, so a reader can never
     * observe a half-cloned cache directory. Stale `.partial-*` siblings
     * older than {@see self::PARTIAL_STALE_AFTER_SECONDS} are purged on
     * every call.
     *
     * @return string|null The absolute clone path, or NULL when unavailable.
     *         Call {@see self::getUnavailableReason()} to find out why.
     */
    public function ensureAvailable() : ?string
    {
        $this->unavailableReason = self::REASON_NONE;

        $cacheDir = $this->getCacheDirectory();

        $this->purgeStalePartialSiblings($cacheDir);

        if(is_dir($cacheDir)) {
            return $this->finalizeCache($cacheDir);
        }

        if(!$this->gitRunner->isAvailable()) {
            $this->unavailableReason = self::REASON_GIT_UNAVAILABLE;
            return null;
        }

        $partialDir = $this->allocatePartialPath($cacheDir);

        if(!$this->cloneInto($partialDir)) {
            $this->cleanupPartial($partialDir);
            $this->unavailableReason = self::REASON_CLONE_FAILED;
            return null;
        }

        if(!is_file($partialDir . '/composer.json')) {
            $this->cleanupPartial($partialDir);
            $this->unavailableReason = self::REASON_CLONE_FAILED;
            return null;
        }

        if(!@rename($partialDir, $cacheDir)) {
            // Rename failed: another process may have completed the same
            // clone first (concurrent winner). Discard our own attempt and
            // adopt the winner's cache if it is valid; otherwise this is a
            // genuine failure to acquire the cache.
            $this->cleanupPartial($partialDir);

            if(!is_file($cacheDir . '/composer.json')) {
                $this->unavailableReason = self::REASON_CLONE_FAILED;
                return null;
            }
        }

        return $this->finalizeCache($cacheDir);
    }

    /**
     * Reports which of the three causes (one of the `REASON_*` constants)
     * applied to the most recent {@see self::ensureAvailable()} call that
     * returned NULL. {@see self::REASON_NONE} when the most recent call
     * succeeded, or none was made yet.
     *
     * @return string
     */
    public function getUnavailableReason() : string
    {
        return $this->unavailableReason;
    }

    /**
     * Validates that `$cacheDir` (already known to exist) contains a
     * `composer.json`, resolving it to a real path when possible.
     */
    private function finalizeCache(string $cacheDir) : ?string
    {
        if(!is_file($cacheDir . '/composer.json')) {
            $this->unavailableReason = self::REASON_INVALID_DIRECTORY;
            return null;
        }

        $realPath = realpath($cacheDir);

        return $realPath !== false ? $realPath : $cacheDir;
    }

    private function cloneInto(string $targetDir) : bool
    {
        $parentDir = dirname($targetDir);

        if(!is_dir($parentDir) && !mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
            return false;
        }

        $result = $this->gitRunner->run('clone', '--depth', '1', $this->repositoryUrl, $targetDir);

        return $result->isSuccess() && is_dir($targetDir);
    }

    /**
     * Allocates a unique `.partial-*` sibling path for `$cacheDir`, without
     * creating it: `git clone`'s own target-directory creation does that.
     */
    private function allocatePartialPath(string $cacheDir) : string
    {
        return dirname($cacheDir) . '/' . self::PARTIAL_PREFIX . basename($cacheDir) . '-' . uniqid('', true);
    }

    private function cleanupPartial(string $partialDir) : void
    {
        FixtureFileSystem::removeDirectory($partialDir);
    }

    /**
     * Removes `.partial-*` siblings of `$cacheDir` whose modification time
     * is older than {@see self::PARTIAL_STALE_AFTER_SECONDS}, leaving
     * fresher siblings (e.g. a concurrently-running clone) untouched.
     */
    private function purgeStalePartialSiblings(string $cacheDir) : void
    {
        $parentDir = dirname($cacheDir);

        if(!is_dir($parentDir)) {
            return;
        }

        $pattern = $parentDir . '/' . self::PARTIAL_PREFIX . basename($cacheDir) . '-*';
        $threshold = time() - self::PARTIAL_STALE_AFTER_SECONDS;

        foreach(glob($pattern) ?: array() as $partialPath)
        {
            if(!is_dir($partialPath)) {
                continue;
            }

            $modifiedAt = filemtime($partialPath);

            if($modifiedAt !== false && $modifiedAt >= $threshold) {
                continue;
            }

            $this->cleanupPartial($partialPath);
        }
    }
}
