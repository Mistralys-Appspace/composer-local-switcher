<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Utils\InstalledPackages;

/**
 * Whether what is actually installed (`vendor/composer/installed.json`)
 * matches what the active mode expects: in DEV, every local package
 * should be installed from its path repository; in PROD, none should
 * be. This is "mode describes what is installed", not just what the
 * config says — the gap the research brief's "Mode describes the
 * config only, not what is installed" observation identifies.
 *
 * @package Composer Switcher
 * @subpackage State
 */
enum InstalledState: string
{
    /**
     * The installed state matches what the active mode expects.
     */
    case Matches = 'matches';

    /**
     * The installed state does not yet match the active mode — an
     * `install`/`update` is needed to bring it in sync.
     */
    case Pending = 'pending';

    /**
     * Whether a local package is installed from its path repository
     * could not be determined (`installed.json` missing or malformed).
     */
    case Unknown = 'unknown';

    /**
     * Computes the installed state for a set of local packages against
     * a given mode: in {@see ConfigSwitcher::MODE_DEV}, every package
     * must be path-installed; in {@see ConfigSwitcher::MODE_PROD}, none
     * may be. An empty package list always {@see self::Matches}, since
     * there is nothing for either mode to be out of sync about.
     *
     * @param string $mode {@see ConfigSwitcher::MODE_DEV} or {@see ConfigSwitcher::MODE_PROD}.
     * @param string[] $localPackageNames The package names declared in the DEV config's `local-repositories` list.
     */
    public static function fromInstalledPackages(string $mode, array $localPackageNames, InstalledPackages $installed) : self
    {
        $results = array();

        foreach($localPackageNames as $packageName) {
            $result = $installed->isInstalledFromPath($packageName);

            if($result === null) {
                return self::Unknown;
            }

            $results[] = $result;
        }

        if(empty($results)) {
            return self::Matches;
        }

        $allInstalledFromPath = !in_array(false, $results, true);
        $noneInstalledFromPath = !in_array(true, $results, true);

        if($mode === ConfigSwitcher::MODE_DEV && $allInstalledFromPath) {
            return self::Matches;
        }

        if($mode === ConfigSwitcher::MODE_PROD && $noneInstalledFromPath) {
            return self::Matches;
        }

        return self::Pending;
    }
}
