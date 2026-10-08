<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;

/**
 * Immutable value object for a single entry of the DEV configuration
 * file's `local-repositories` list: the package name, the local
 * checkout path, and an optional version constraint override.
 *
 * This is the single parser behind what used to be two duplicated,
 * hand-rolled readers of the same list — a strict one inside
 * `ConfigSwitcher::switch_adjustConfigForDev()` that throws on any
 * malformed entry, and a lenient one inside
 * `ConfigSwitcher::describe_readLocalRepositories()` that skips a
 * malformed entry and reports a warning instead. {@see self::parseList()}
 * and {@see self::parseListLenient()} share the exact same per-entry
 * validation (`isValidEntry()`), so the two call sites can never
 * silently drift apart on what counts as a valid entry again.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class LocalRepository
{
    public function __construct(
        private readonly string $packageName,
        private readonly string $path,
        private readonly ?string $versionOverride = null
    )
    {
    }

    public function getPackageName() : string
    {
        return $this->packageName;
    }

    public function getPath() : string
    {
        return $this->path;
    }

    /**
     * The raw version override, or `null` when the entry did not
     * specify one (or specified a non-string value).
     */
    public function getVersionOverride() : ?string
    {
        return $this->versionOverride;
    }

    public function hasVersionOverride() : bool
    {
        return $this->versionOverride !== null;
    }

    /**
     * The effective version constraint: the override when present,
     * or Composer's own wildcard `*` otherwise.
     */
    public function getVersion() : string
    {
        return $this->versionOverride ?? '*';
    }

    /**
     * @return array{packageName:string,path:string,versionOverride:string|null}
     */
    public function toArray() : array
    {
        return array(
            'packageName' => $this->packageName,
            'path' => $this->path,
            'versionOverride' => $this->versionOverride
        );
    }

    /**
     * @param array{packageName:string,path:string,versionOverride?:string|null} $data
     */
    public static function fromArray(array $data) : self
    {
        $versionOverride = $data['versionOverride'] ?? null;

        return new self(
            $data['packageName'],
            $data['path'],
            is_string($versionOverride) ? $versionOverride : null
        );
    }

    /**
     * Strictly parses the `local-repositories` list out of a decoded
     * DEV configuration file's data. Throws `ERROR_INVALID_JSON_STRUCTURE`
     * — with the same message texts and context keys the previous,
     * duplicated logic in `switch_adjustConfigForDev()` used — when the
     * list key itself is missing/invalid, or when any entry is malformed.
     *
     * @param array<string,mixed> $data The decoded DEV configuration file data.
     * @param string $filePath The DEV configuration file's path, for exception context.
     * @return self[]
     * @throws ComposerSwitcherException
     */
    public static function parseList(array $data, string $filePath) : array
    {
        $list = $data[ConfigSwitcher::KEY_LOCAL_REPOSITORIES] ?? null;

        if(!is_array($list)) {
            throw (new ComposerSwitcherException(
                sprintf(
                    'ERROR: The DEV composer config does not contain the [%s] key, or it is not an array.',
                    ConfigSwitcher::KEY_LOCAL_REPOSITORIES
                ),
                ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $filePath,
                    ComposerSwitcherException::KEY_EXPECTED => ConfigSwitcher::KEY_LOCAL_REPOSITORIES
                ));
        }

        $repositories = array();

        foreach($list as $entry)
        {
            if(!self::isValidEntry($entry)) {
                throw (new ComposerSwitcherException(
                    'ERROR: Invalid local repository entry in DEV composer config.',
                    ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
                ))
                    ->setContext(array(
                        ComposerSwitcherException::KEY_FILE_PATH => $filePath,
                        ComposerSwitcherException::KEY_PACKAGE_NAME => self::extractPackageNameOrNull($entry)
                    ));
            }

            $repositories[] = self::fromValidEntry($entry);
        }

        return $repositories;
    }

    /**
     * Leniently parses the `local-repositories` list out of a decoded
     * DEV configuration file's data. Never throws: a missing/invalid
     * list key produces a single list-level warning (and an empty
     * result), and each malformed entry is skipped with its own
     * warning while the rest of the list is still parsed — the exact
     * behaviour the previous, duplicated logic in
     * `describe_readLocalRepositories()` implemented.
     *
     * @param array<string,mixed> $data The decoded DEV configuration file data.
     * @param string $filePath Unused by the current warning texts, kept for signature symmetry with {@see self::parseList()} and future context-carrying warnings.
     * @return array{0:self[],1:string[]}
     */
    public static function parseListLenient(array $data, string $filePath) : array
    {
        $repositories = array();
        $warnings = array();

        $list = $data[ConfigSwitcher::KEY_LOCAL_REPOSITORIES] ?? null;

        if(!is_array($list)) {
            $warnings[] = sprintf(
                'Dev configuration file does not contain a valid [%s] list.',
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES
            );

            return array($repositories, $warnings);
        }

        foreach($list as $entry)
        {
            if(!self::isValidEntry($entry)) {
                $warnings[] = sprintf(
                    'Skipped a malformed entry in the dev configuration [%s] list.',
                    ConfigSwitcher::KEY_LOCAL_REPOSITORIES
                );

                continue;
            }

            $repositories[] = self::fromValidEntry($entry);
        }

        return array($repositories, $warnings);
    }

    /**
     * The single per-entry validity check shared by {@see self::parseList()}
     * and {@see self::parseListLenient()}: a valid entry is an array
     * carrying string `package-name` and `path` keys. The optional
     * `version` key is not validated here — a non-string or absent
     * `version` simply means no override ({@see self::fromValidEntry()}).
     */
    private static function isValidEntry(mixed $entry) : bool
    {
        return is_array($entry)
            && isset($entry['package-name'], $entry['path'])
            && is_string($entry['package-name'])
            && is_string($entry['path']);
    }

    /**
     * @param array<string,mixed> $entry A pre-validated entry ({@see self::isValidEntry()}).
     */
    private static function fromValidEntry(array $entry) : self
    {
        $version = $entry['version'] ?? null;

        return new self(
            $entry['package-name'],
            $entry['path'],
            is_string($version) ? $version : null
        );
    }

    private static function extractPackageNameOrNull(mixed $entry) : ?string
    {
        if(is_array($entry) && is_string($entry['package-name'] ?? null)) {
            return $entry['package-name'];
        }

        return null;
    }
}
