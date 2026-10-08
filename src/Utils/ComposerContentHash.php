<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

/**
 * Computes the same "content hash" Composer itself stores under the
 * `content-hash` key of a lock file, so the library can detect
 * whether a lock file is stale against a given `composer.json`
 * payload without depending on Composer's own classes (the library
 * has zero runtime dependencies).
 *
 * This is an exact mirror of Composer 2.9.5's
 * `Composer\Package\Locker::getContentHash()`: it extracts a fixed
 * set of top-level keys (plus `config.platform`) considered
 * "relevant" to dependency resolution, `ksort()`s them at the top
 * level only (nested key order is preserved verbatim, matching
 * Composer's own behavior), and hashes the result with
 * `md5(json_encode($relevant, 0))` — Composer's own
 * `JsonFile::encode($x, 0)` is equivalent to a plain
 * `json_encode($x, 0)` call for this purpose.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class ComposerContentHash
{
    /**
     * The top-level `composer.json` keys considered relevant to
     * dependency resolution by Composer's `Locker::getContentHash()`.
     *
     * @var string[]
     */
    private const RELEVANT_KEYS = array(
        'name',
        'version',
        'require',
        'require-dev',
        'conflict',
        'replace',
        'provide',
        'minimum-stability',
        'prefer-stable',
        'repositories',
        'extra'
    );

    /**
     * Computes the content hash for the given, decoded `composer.json`
     * data — identical to the hash Composer itself would compute and
     * store under `content-hash` in the corresponding lock file.
     *
     * @param array<string,mixed> $configData The decoded `composer.json` data.
     * @return string The 32-character hexadecimal MD5 hash.
     */
    public static function fromConfigData(array $configData) : string
    {
        $relevant = array();

        foreach (self::RELEVANT_KEYS as $key) {
            if (array_key_exists($key, $configData)) {
                $relevant[$key] = $configData[$key];
            }
        }

        $config = $configData['config'] ?? null;
        if (is_array($config) && array_key_exists('platform', $config)) {
            $relevant['config'] = array('platform' => $config['platform']);
        }

        ksort($relevant);

        return md5((string)json_encode($relevant, 0));
    }
}
