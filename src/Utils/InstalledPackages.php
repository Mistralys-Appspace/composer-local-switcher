<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use JsonException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException as SwitcherException;

/**
 * Reads `<vendor-dir>/composer/installed.json` (Composer 2's
 * installed-package manifest) through {@see FileSystem}, so the
 * planning layer can tell whether a given package is *actually*
 * installed from a local path repository — as opposed to merely
 * being configured as one in the DEV config, which says nothing
 * about whether `composer install`/`update` has run since.
 *
 * Never throws: a missing project, a missing `installed.json`, or a
 * malformed one are all reported through {@see self::isInstalledFromPath()}
 * returning `null` ("cannot be determined") rather than propagating
 * an exception — this class is a read-only inspection tool, not a
 * strict validator like {@see \Mistralys\ComposerSwitcher\State\LocalRepository::parseList()}.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class InstalledPackages extends BaseFile
{
    private const DEFAULT_VENDOR_DIR = 'vendor';

    /**
     * @param string $projectRoot The project root directory (the one containing `composer.json`).
     * @param array<string,mixed> $configData The decoded `composer.json` data, read only for `config.vendor-dir`.
     */
    public function __construct(string $projectRoot, array $configData)
    {
        parent::__construct(self::resolvePath($projectRoot, $configData));
    }

    /**
     * @param array<string,mixed> $configData
     */
    private static function resolvePath(string $projectRoot, array $configData) : string
    {
        $vendorDir = self::DEFAULT_VENDOR_DIR;

        $config = $configData['config'] ?? null;

        if(is_array($config) && is_string($config['vendor-dir'] ?? null) && $config['vendor-dir'] !== '') {
            $vendorDir = $config['vendor-dir'];
        }

        return rtrim($projectRoot, '/') . '/' . trim($vendorDir, '/') . '/composer/installed.json';
    }

    /**
     * Whether the given package is currently installed from a local
     * path repository (`dist.type === 'path'` in `installed.json`).
     *
     * @return bool|null `true`/`false` when known, or `null` when
     *         `installed.json` is missing, unreadable, or not valid
     *         JSON — never thrown as an exception.
     */
    public function isInstalledFromPath(string $packageName) : ?bool
    {
        $data = $this->loadData();

        if($data === null) {
            return null;
        }

        $entry = self::findPackageEntry($data, $packageName);

        if($entry === null) {
            return false;
        }

        $distType = $entry['dist']['type'] ?? null;

        return is_string($distType) && $distType === 'path';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadData() : ?array
    {
        if(!$this->exists()) {
            return null;
        }

        try {
            $decoded = json_decode($this->getFileSystem()->read($this->getPath()), true, 512, JSON_THROW_ON_ERROR);
        } catch(SwitcherException|JsonException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private static function findPackageEntry(array $data, string $packageName) : ?array
    {
        $packages = $data['packages'] ?? null;

        if(!is_array($packages)) {
            return null;
        }

        foreach($packages as $package) {
            if(is_array($package) && ($package['name'] ?? null) === $packageName) {
                return $package;
            }
        }

        return null;
    }
}
