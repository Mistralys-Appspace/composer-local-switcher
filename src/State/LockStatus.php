<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * The freshness status of a lock file relative to its config file:
 * no lock file at all, a lock whose content hash still matches its
 * config (Composer's own `Locker::isFresh()` would return `true`),
 * a lock whose content hash no longer matches, or a lock whose
 * freshness could not be determined (e.g. an unreadable or
 * malformed lock file).
 *
 * @package Composer Switcher
 * @subpackage State
 */
enum LockStatus: string
{
    case Missing = 'missing';
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unknown = 'unknown';

    /**
     * Whether this status means `composer update`/`install` must run
     * before the lock file can be trusted again. Only {@see self::Stale}
     * requires it — {@see self::Missing} and {@see self::Unknown} are
     * handled by dedicated recovery paths elsewhere in the switching
     * model, not by treating them as "needs an update".
     */
    public function requiresUpdate() : bool
    {
        return $this === self::Stale;
    }
}
