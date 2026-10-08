<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\DevTransformResult;
use Mistralys\ComposerSwitcher\State\LocalRepository;
use Mistralys\ComposerSwitcher\State\RevertResult;

/**
 * Pure transform between a PROD-style `composer.json` and its DEV
 * counterpart.
 *
 * {@see self::apply()} ports the DEV config-building logic formerly
 * inline in `ConfigSwitcher::switch_adjustConfigForDev()` (the
 * replace/prune/append repository logic and the underscore/hyphen
 * alias, both via the also-ported {@see self::urlMatchesPackageName()}),
 * with one behavioral change: the path repository's version alias is
 * now derived from the PROD lock's locked version for that package
 * (falling back to Composer's `*` when neither an explicit override
 * nor a locked version is available) instead of defaulting to a bare
 * `*` whenever no `version` override was configured.
 *
 * {@see self::revert()} is `apply()`'s three-way inverse: given the
 * current DEV `composer.json`, the original PROD snapshot it was built
 * from, and the local repositories that were applied at snapshot time,
 * it reconstructs what the DEV config would look like with every
 * DEV-time edit to a *non-managed* key, package or repository carried
 * back, and every *managed* (local package) entry restored to its
 * snapshot value — this is what makes `composer require`/`remove`,
 * script/autoload edits, and manually added repositories survive a
 * refresh or a switch back to PROD, instead of being silently
 * discarded by a DEV config that `apply()` always rebuilds from
 * scratch. Invariant: `revert(apply(P, repos, lock), P, repos, lock)`
 * is value-equal to `P`.
 *
 * Arrays in, arrays out: the only I/O is the injected {@see LockFile},
 * used to read the PROD-locked version for the alias — this class
 * itself never touches the filesystem.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class DevConfigTransformer
{
    /**
     * Builds the DEV `composer.json` data from a PROD-style config and
     * the DEV configuration's local repositories list.
     *
     * For each package: the alias version is the explicit
     * `versionOverride`, or else the PROD lock's locked version for
     * that package, or else `null` when neither is available. The
     * root require/require-dev constraint is kept from PROD when the
     * package is already root-required there and an alias was
     * derived; otherwise it becomes `*`. Placement (`require` vs
     * `require-dev`) always follows the PROD baseline. The path
     * repository entry's `options.versions` carries the alias (with
     * an underscore→hyphen duplicate for the package name, since a
     * repository URL may use either), and is omitted entirely when no
     * alias could be derived.
     *
     * @param array<string,mixed> $prodConfig The PROD-style `composer.json` data (the snapshot, or `composer-prod.json`'s data).
     * @param LocalRepository[] $repos
     */
    public static function apply(array $prodConfig, array $repos, LockFile $prodLock) : DevTransformResult
    {
        $config = $prodConfig;
        $versionDerivations = array();

        foreach($repos as $repo) {
            $packageName = $repo->getPackageName();

            [$alias, $source] = self::deriveAlias($repo, $prodLock);

            $versionDerivations[] = array(
                'packageName' => $packageName,
                'version' => $alias,
                'source' => $source
            );

            $requireKey = isset($config['require-dev'][$packageName]) ? 'require-dev' : 'require';
            $rootRequired = isset($config['require'][$packageName]) || isset($config['require-dev'][$packageName]);

            if(!($rootRequired && $alias !== null)) {
                $config[$requireKey][$packageName] = '*';
            }

            $repositories = $config[ConfigSwitcher::KEY_REPOSITORIES] ?? null;

            if(!is_array($repositories)) {
                $repositories = array();
            }

            $config[ConfigSwitcher::KEY_REPOSITORIES] = self::mergeRepositoryEntry(
                $repositories,
                $packageName,
                self::buildRepositoryEntry($repo->getPath(), $packageName, $alias)
            );
        }

        return new DevTransformResult($config, $versionDerivations);
    }

    /**
     * The three-way revert: base is `apply($snapshotConfig, …)`,
     * current is `$devConfig`, target is `$snapshotConfig`.
     *
     * - A top-level key (other than `require`/`require-dev`/`repositories`)
     *   where `current == base` takes the snapshot value; a key the
     *   transform never touches (`base == snapshot`) takes the current
     *   value, carrying a user edit back.
     * - `require`/`require-dev` are merged per package: a managed
     *   (local) package always takes the snapshot value — or is
     *   dropped entirely when the snapshot has none — and a managed
     *   package the user edited anyway is recorded as overridden;
     *   every other package takes the current value, so additions,
     *   removals and constraint changes are carried back.
     * - `repositories` becomes the snapshot list, plus current entries
     *   absent from base, minus base entries the user removed
     *   (excluding managed path entries, which `apply()` regenerates
     *   on its own and are therefore invisible to this diff).
     *
     * @param array<string,mixed> $devConfig The current, live DEV `composer.json` data.
     * @param array<string,mixed> $snapshotConfig The original PROD snapshot (`composer-prod.json`'s data).
     * @param LocalRepository[] $appliedRepos The local repositories that were applied when the snapshot was taken.
     */
    public static function revert(array $devConfig, array $snapshotConfig, array $appliedRepos, LockFile $prodLock) : RevertResult
    {
        $base = self::apply($snapshotConfig, $appliedRepos, $prodLock)->getConfig();

        $managedPackageNames = array_map(
            static fn(LocalRepository $repo) : string => $repo->getPackageName(),
            $appliedRepos
        );

        $managedPaths = array_map(
            static fn(LocalRepository $repo) : string => $repo->getPath(),
            $appliedRepos
        );

        $effectiveConfig = array();
        $overridden = array();

        foreach(self::unionKeys($devConfig, $base, $snapshotConfig) as $key) {
            if(in_array($key, array('require', 'require-dev', ConfigSwitcher::KEY_REPOSITORIES), true)) {
                continue;
            }

            $currentValue = $devConfig[$key] ?? null;
            $baseValue = $base[$key] ?? null;

            if(self::valuesEqual($currentValue, $baseValue)) {
                if(array_key_exists($key, $snapshotConfig)) {
                    $effectiveConfig[$key] = $snapshotConfig[$key];
                }

                continue;
            }

            if(array_key_exists($key, $devConfig)) {
                $effectiveConfig[$key] = $devConfig[$key];
            }
        }

        foreach(array('require', 'require-dev') as $section) {
            [$sectionResult, $sectionOverrides] = self::revertSection(
                $section,
                self::sectionOf($devConfig, $section),
                self::sectionOf($base, $section),
                self::sectionOf($snapshotConfig, $section),
                $managedPackageNames
            );

            if(count($sectionResult) > 0 || array_key_exists($section, $snapshotConfig)) {
                $effectiveConfig[$section] = $sectionResult;
            }

            $overridden = array_merge($overridden, $sectionOverrides);
        }

        $repositoriesResult = self::revertRepositories(
            self::sectionOf($devConfig, ConfigSwitcher::KEY_REPOSITORIES),
            self::sectionOf($base, ConfigSwitcher::KEY_REPOSITORIES),
            self::sectionOf($snapshotConfig, ConfigSwitcher::KEY_REPOSITORIES),
            $managedPaths
        );

        if(count($repositoriesResult) > 0 || array_key_exists(ConfigSwitcher::KEY_REPOSITORIES, $snapshotConfig)) {
            $effectiveConfig[ConfigSwitcher::KEY_REPOSITORIES] = $repositoriesResult;
        }

        return new RevertResult($effectiveConfig, $overridden);
    }

    /**
     * Builds the origin classifier {@see ConfigDiff::between()}
     * consumes to attach a {@see ConfigChangeOrigin} to every
     * {@see \Mistralys\ComposerSwitcher\State\ConfigChange} it
     * produces: a change at a managed entry (a local package's
     * require/require-dev constraint, or its path repository entry)
     * is {@see ConfigChangeOrigin::LocalSwitch}, unless `$revertResult`
     * reports that specific package+section as overridden, in which
     * case it is {@see ConfigChangeOrigin::Discarded} — a DEV edit to
     * a managed entry being reset. Every other change is
     * {@see ConfigChangeOrigin::CarriedBack} — a DEV edit to a
     * non-managed entry becoming, or already being, permanent.
     *
     * @param LocalRepository[] $repos The local repositories this transformer manages.
     * @param RevertResult|null $revertResult The result of the `revert()` call this classifier describes, if any (omit when classifying a plain `apply()` diff with no revert involved).
     * @return callable(string[],mixed,mixed):ConfigChangeOrigin
     */
    public static function makeOriginClassifier(array $repos, ?RevertResult $revertResult = null) : callable
    {
        $managedPackageNames = array_map(
            static fn(LocalRepository $repo) : string => $repo->getPackageName(),
            $repos
        );

        $managedPaths = array_map(
            static fn(LocalRepository $repo) : string => $repo->getPath(),
            $repos
        );

        $overriddenKeys = array();

        if($revertResult !== null) {
            foreach($revertResult->getOverriddenManagedEntries() as $entry) {
                $overriddenKeys[$entry['section'] . '|' . $entry['packageName']] = true;
            }
        }

        return static function(array $path, mixed $before, mixed $after) use ($managedPackageNames, $managedPaths, $overriddenKeys) : ConfigChangeOrigin {
            if(
                count($path) >= 2
                && in_array($path[0], array('require', 'require-dev'), true)
                && in_array($path[1], $managedPackageNames, true)
            ) {
                $key = $path[0] . '|' . $path[1];

                return isset($overriddenKeys[$key]) ? ConfigChangeOrigin::Discarded : ConfigChangeOrigin::LocalSwitch;
            }

            if(($path[0] ?? null) === ConfigSwitcher::KEY_REPOSITORIES) {
                $entry = $after ?? $before;

                if(is_array($entry) && self::isManagedRepositoryEntry($entry, $managedPaths)) {
                    return ConfigChangeOrigin::LocalSwitch;
                }
            }

            return ConfigChangeOrigin::CarriedBack;
        };
    }

    /**
     * @return array{0:string|null,1:string}
     */
    private static function deriveAlias(LocalRepository $repo, LockFile $prodLock) : array
    {
        $override = $repo->getVersionOverride();

        if($override !== null) {
            return array($override, DevTransformResult::SOURCE_OVERRIDE);
        }

        $locked = $prodLock->getLockedVersion($repo->getPackageName());

        if($locked !== null) {
            return array($locked, DevTransformResult::SOURCE_LOCKED);
        }

        return array(null, DevTransformResult::SOURCE_NONE);
    }

    /**
     * @return array{type:string,url:string,options:array<string,mixed>}
     */
    private static function buildRepositoryEntry(string $path, string $packageName, ?string $alias) : array
    {
        $entry = array(
            'type' => 'path',
            'url' => $path,
            'options' => array(
                'symlink' => true
            )
        );

        if($alias !== null) {
            $entry['options']['versions'] = self::buildVersionsMap($packageName, $alias);
        }

        return $entry;
    }

    /**
     * @return array<string,string>
     */
    private static function buildVersionsMap(string $packageName, string $alias) : array
    {
        $versions = array($packageName => $alias);

        if(strpos($packageName, '_') !== false) {
            // Some package names use underscores instead of hyphens.
            // The repository URL may use either, so we need to add
            // both versions to ensure that Composer can find it.
            $versions[str_replace('_', '-', $packageName)] = $alias;
        }

        return $versions;
    }

    /**
     * Replaces the first repository entry whose URL matches the
     * package name with `$repoEntry`, prunes any further matches
     * (stale VCS duplicates), or appends `$repoEntry` when none match.
     *
     * @param array<int,mixed> $repositories
     * @param array{type:string,url:string,options:array<string,mixed>} $repoEntry
     * @return array<int,mixed>
     */
    private static function mergeRepositoryEntry(array $repositories, string $packageName, array $repoEntry) : array
    {
        $found = false;

        foreach($repositories as $i => $repository) {
            if(!is_array($repository) || !isset($repository['url']) || !is_string($repository['url'])) {
                continue;
            }

            if(
                !self::urlMatchesPackageName($repository['url'], $packageName)
                &&
                // GitHub repository URLs use hyphens instead of underscores.
                // The package name may use either.
                !self::urlMatchesPackageName($repository['url'], str_replace('_', '-', $packageName))
            ) {
                continue;
            }

            if(!$found) {
                $found = true;
                $repositories[$i] = $repoEntry;
            } else {
                unset($repositories[$i]);
            }
        }

        if($found) {
            return array_values($repositories);
        }

        $repositories[] = $repoEntry;

        return $repositories;
    }

    /**
     * Checks whether a repository URL contains the package name
     * followed by a valid boundary character (`.`, `/`, or end-of-string).
     * Prevents substring collisions like `application-utils` matching
     * `application-utils-core`.
     */
    private static function urlMatchesPackageName(string $url, string $name) : bool
    {
        $pos = stripos($url, $name);

        if($pos === false) {
            return false;
        }

        $afterPos = $pos + strlen($name);

        if($afterPos >= strlen($url)) {
            return true;
        }

        $nextChar = $url[$afterPos];

        return $nextChar === '.' || $nextChar === '/';
    }

    /**
     * Merges one `require`/`require-dev` section per package: a
     * managed package always takes the snapshot value (or is dropped
     * when the snapshot has none), recording an override when the
     * user edited it anyway; every other package takes the current
     * value.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $base
     * @param array<string,mixed> $snapshot
     * @param string[] $managedPackageNames
     * @return array{0:array<string,mixed>,1:array<int,array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed}>}
     */
    private static function revertSection(string $section, array $current, array $base, array $snapshot, array $managedPackageNames) : array
    {
        $result = array();
        $overridden = array();

        foreach(self::unionKeys($current, $base, $snapshot) as $packageName) {
            $isManagedHere = in_array($packageName, $managedPackageNames, true) && array_key_exists($packageName, $base);

            if($isManagedHere) {
                if(array_key_exists($packageName, $snapshot)) {
                    $result[$packageName] = $snapshot[$packageName];
                }

                $currentValue = $current[$packageName] ?? null;
                $baseValue = $base[$packageName] ?? null;

                if(!self::valuesEqual($currentValue, $baseValue)) {
                    $overridden[] = array(
                        'section' => $section,
                        'packageName' => $packageName,
                        'snapshotValue' => $snapshot[$packageName] ?? null,
                        'currentValue' => $current[$packageName] ?? null
                    );
                }

                continue;
            }

            if(array_key_exists($packageName, $current)) {
                $result[$packageName] = $current[$packageName];
            }
        }

        return array($result, $overridden);
    }

    /**
     * @param array<int|string,mixed> $current
     * @param array<int|string,mixed> $base
     * @param array<int|string,mixed> $snapshot
     * @param string[] $managedPaths The `path` repository URLs this transformer manages (one per applied local repository).
     * @return array<int,mixed>
     */
    private static function revertRepositories(array $current, array $base, array $snapshot, array $managedPaths) : array
    {
        $current = array_values($current);
        $base = array_values($base);
        $snapshot = array_values($snapshot);

        $nonManagedCurrent = self::filterNonManagedRepositories($current, $managedPaths);
        $nonManagedBase = self::filterNonManagedRepositories($base, $managedPaths);

        $added = array();

        foreach($nonManagedCurrent as $entry) {
            if(!self::containsRepositoryEntry($nonManagedBase, $entry)) {
                $added[] = $entry;
            }
        }

        $removed = array();

        foreach($nonManagedBase as $entry) {
            if(!self::containsRepositoryEntry($nonManagedCurrent, $entry)) {
                $removed[] = $entry;
            }
        }

        $result = $snapshot;

        foreach($removed as $entry) {
            $result = self::removeRepositoryEntry($result, $entry);
        }

        foreach($added as $entry) {
            if(!self::containsRepositoryEntry($result, $entry)) {
                $result[] = $entry;
            }
        }

        return array_values($result);
    }

    /**
     * @param array<int,mixed> $repositories
     * @param string[] $managedPaths
     * @return array<int,mixed>
     */
    private static function filterNonManagedRepositories(array $repositories, array $managedPaths) : array
    {
        return array_values(array_filter(
            $repositories,
            static fn(mixed $entry) : bool => !self::isManagedRepositoryEntry($entry, $managedPaths)
        ));
    }

    /**
     * Whether `$entry` is one of the path repository entries this
     * transformer itself manages for a local package — identified by
     * an exact match against the local repository's own `path`, not
     * {@see self::urlMatchesPackageName()}'s package-name-in-URL
     * heuristic (which exists to find *pre-existing* VCS entries to
     * replace, and does not generally hold for the `path` URL
     * `apply()` itself writes — a relative or absolute filesystem path
     * rarely contains the package's `vendor/name` string).
     *
     * @param string[] $managedPaths
     */
    private static function isManagedRepositoryEntry(mixed $entry, array $managedPaths) : bool
    {
        return is_array($entry)
            && ($entry['type'] ?? null) === 'path'
            && is_string($entry['url'] ?? null)
            && in_array($entry['url'], $managedPaths, true);
    }

    /**
     * @param array<int,mixed> $repositories
     */
    private static function containsRepositoryEntry(array $repositories, mixed $entry) : bool
    {
        foreach($repositories as $candidate) {
            if(self::valuesEqual($candidate, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the first value-equal match of `$entry` from `$repositories`.
     *
     * @param array<int,mixed> $repositories
     * @return array<int,mixed>
     */
    private static function removeRepositoryEntry(array $repositories, mixed $entry) : array
    {
        foreach($repositories as $i => $candidate) {
            if(self::valuesEqual($candidate, $entry)) {
                unset($repositories[$i]);
                break;
            }
        }

        return array_values($repositories);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int|string,mixed>
     */
    private static function sectionOf(array $config, string $key) : array
    {
        $section = $config[$key] ?? null;

        return is_array($section) ? $section : array();
    }

    /**
     * @param array<int|string,mixed> ...$arrays
     * @return string[]
     */
    private static function unionKeys(array ...$arrays) : array
    {
        $keys = array();

        foreach($arrays as $array) {
            foreach(array_keys($array) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    private static function valuesEqual(mixed $a, mixed $b) : bool
    {
        return $a === $b;
    }
}
