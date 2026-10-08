<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use JsonException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\State\LockStatus;

/**
 * A Composer lock file: a path holder (`.json` → `.lock`) that also
 * reads, through {@see FileSystem}, the three pieces of information
 * the lock/planning layer needs — its own recorded content hash, its
 * freshness relative to its owning {@see ConfigFile}, and an
 * individual package's locked version.
 *
 * None of {@see self::getContentHash()}, {@see self::getLockStatus()}
 * or {@see self::getLockedVersion()} ever throws or caches: each
 * re-reads and re-decodes the lock (and, for {@see self::getLockStatus()},
 * the config) fresh on every call, exactly like {@see \Mistralys\ComposerSwitcher\Utils\StatusFile::loadState()} —
 * a missing file, non-JSON content (the Tier 1 fixture lock is the
 * literal text `PROD`/`DEV`, not JSON), or a lock missing its
 * `content-hash` key all degrade to `null`/{@see LockStatus::Unknown}
 * rather than propagating an exception.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
class LockFile extends BaseFile
{
    private readonly ConfigFile $configFile;

    public function __construct(ConfigFile $configFile)
    {
        $this->configFile = $configFile;

        parent::__construct(str_replace('.json', '.lock', $configFile->getPath()));
    }

    public function getContent() : string
    {
        return $this->getFileSystem()->read($this->getPath());
    }

    public function getConfigFile(): ConfigFile
    {
        return $this->configFile;
    }

    /**
     * The lock's own recorded `content-hash`, or `null` when the lock
     * is missing, unreadable, not valid JSON, or has no `content-hash`
     * key (e.g. the Tier 1 fixture lock, which is the literal text
     * `PROD`/`DEV`).
     */
    public function getContentHash() : ?string
    {
        $data = $this->loadData();

        if($data === null) {
            return null;
        }

        $hash = $data['content-hash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    /**
     * Derives this lock's freshness against its own {@see ConfigFile}
     * — {@see ComposerContentHash::fromConfigData()} on the config's
     * current data, compared against {@see self::getContentHash()}.
     *
     * @return LockStatus {@see LockStatus::Missing} when the lock file
     *         does not exist; {@see LockStatus::Unknown} when either
     *         side cannot be determined (an unreadable/malformed lock
     *         or config, or a lock with no `content-hash` key);
     *         {@see LockStatus::Fresh}/{@see LockStatus::Stale}
     *         otherwise.
     */
    public function getLockStatus() : LockStatus
    {
        if(!$this->exists()) {
            return LockStatus::Missing;
        }

        $lockedHash = $this->getContentHash();

        if($lockedHash === null) {
            return LockStatus::Unknown;
        }

        try {
            $configData = $this->configFile->getData();
        } catch(ComposerSwitcherException) {
            return LockStatus::Unknown;
        }

        $expectedHash = ComposerContentHash::fromConfigData($configData);

        return $lockedHash === $expectedHash ? LockStatus::Fresh : LockStatus::Stale;
    }

    /**
     * The locked version of the given package, searched across both
     * `packages` and `packages-dev`, or `null` when the package is not
     * locked (or the lock is missing/unreadable/malformed).
     */
    public function getLockedVersion(string $packageName) : ?string
    {
        $data = $this->loadData();

        if($data === null) {
            return null;
        }

        foreach(array('packages', 'packages-dev') as $key) {
            $list = $data[$key] ?? null;

            if(!is_array($list)) {
                continue;
            }

            foreach($list as $package) {
                if(is_array($package) && ($package['name'] ?? null) === $packageName && is_string($package['version'] ?? null)) {
                    return $package['version'];
                }
            }
        }

        return null;
    }

    /**
     * Reads and JSON-decodes the lock file fresh on every call — never
     * cached, matching {@see \Mistralys\ComposerSwitcher\Utils\StatusFile}'s
     * no-caching convention.
     *
     * @return array<string,mixed>|null `null` when the lock does not
     *         exist, cannot be read, or is not valid JSON decoding to
     *         an array.
     */
    private function loadData() : ?array
    {
        if(!$this->exists()) {
            return null;
        }

        try {
            $decoded = json_decode($this->getFileSystem()->read($this->getPath()), true, 512, JSON_THROW_ON_ERROR);
        } catch(ComposerSwitcherException|JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
