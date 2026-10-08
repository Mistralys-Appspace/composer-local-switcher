<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable set of {@see ConfigChange} records for a single switch,
 * split into two sections answering two different questions:
 *
 * - `composerJson`: what changes in `composer.json` itself — present
 *   in every switch that writes the file. On a DEV switch this is
 *   mostly the DEV transform being applied/refreshed, which is
 *   routine.
 * - `prodConfig`: what changes permanently in production (snapshot →
 *   effective production config) — only meaningful on a DEV→PROD
 *   switch (or `describe()`'s pending-carry-back view in DEV). This
 *   is the part that needs a deliberate decision, which is why it has
 *   its own section rather than being folded into `composerJson`.
 *
 * {@see self::getProdChangedKeys()} and {@see self::getProdChangedPackages()}
 * are both derived from the `prodConfig` section — there is no
 * separate production-change type to keep in sync.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class ConfigChangeSet
{
    /**
     * @param ConfigChange[] $composerJson
     * @param ConfigChange[] $prodConfig
     */
    public function __construct(
        private readonly array $composerJson,
        private readonly array $prodConfig
    )
    {
    }

    /**
     * @return ConfigChange[]
     */
    public function getComposerJsonChanges() : array
    {
        return $this->composerJson;
    }

    /**
     * @return ConfigChange[]
     */
    public function getProdConfigChanges() : array
    {
        return $this->prodConfig;
    }

    /**
     * Whether both sections are empty.
     */
    public function isEmpty() : bool
    {
        return count($this->composerJson) === 0 && count($this->prodConfig) === 0;
    }

    /**
     * Whether this switch carries any permanent production change —
     * the condition a confirmation prompt should default to "no" for.
     */
    public function hasPermanentChanges() : bool
    {
        return count($this->prodConfig) > 0;
    }

    /**
     * The top-level `composer.json` keys that differ in the
     * `prodConfig` section.
     *
     * @return string[]
     */
    public function getProdChangedKeys() : array
    {
        $keys = array();

        foreach($this->prodConfig as $change) {
            $topKey = $change->getPath()[0] ?? null;

            if($topKey !== null) {
                $keys[$topKey] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * The root package names added, removed or re-constrained in
     * `require`/`require-dev`, in the `prodConfig` section.
     *
     * @return string[]
     */
    public function getProdChangedPackages() : array
    {
        $packages = array();

        foreach($this->prodConfig as $change) {
            $path = $change->getPath();

            if(count($path) >= 2 && in_array($path[0], array('require', 'require-dev'), true)) {
                $packages[$path[1]] = true;
            }
        }

        return array_keys($packages);
    }

    /**
     * @return array{composerJson:array<int,array<string,mixed>>,prodConfig:array<int,array<string,mixed>>}
     */
    public function toArray() : array
    {
        return array(
            'composerJson' => array_map(
                static fn(ConfigChange $change) : array => $change->toArray(),
                $this->composerJson
            ),
            'prodConfig' => array_map(
                static fn(ConfigChange $change) : array => $change->toArray(),
                $this->prodConfig
            )
        );
    }
}
