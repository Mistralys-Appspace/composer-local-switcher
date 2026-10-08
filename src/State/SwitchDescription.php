<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

use JsonException;

/**
 * Immutable snapshot of the switcher's current state, as
 * assembled by `ConfigSwitcher::describe()`.
 *
 * This class only holds the already-gathered data and exposes it
 * through typed getters and {@see self::toArray()}. The logic that
 * gathers the underlying data — reading the status file, deriving
 * {@see LockStatus}/{@see InstalledState}, computing
 * {@see self::getPendingProdChanges()} via `DevConfigTransformer::revert()`
 * and {@see \Mistralys\ComposerSwitcher\Utils\ConfigDiff}, probing the
 * filesystem for the individual file records — is the responsibility
 * of `describe()`, which is out of scope for this class.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class SwitchDescription
{
    /**
     * @param string|null $mode The currently active mode (`dev`/`prod`), or `null` if no switch has been made yet.
     * @param string|null $lastSwitchDate The date of the last recorded switch, or `null` if none.
     * @param array<int,array{label:string,path:string,exists:bool,modifiedDate:string|null}> $files Per-file records for the files the switcher manages.
     * @param string|null $activeFlag The mode (`dev`/`prod`) whose flag file currently exists, or `null` if none.
     * @param LockStatus $lockStatus The main lock's freshness against its own config.
     * @param InstalledState $installedState Whether what is actually installed matches what the active mode expects.
     * @param ConfigChangeSet|null $pendingProdChanges The DEV-time edits that would become permanent on a DEV→PROD switch (its `prodConfig` section only), or `null` outside DEV mode or when it could not be computed (never a thrown exception).
     * @param array<int,array{packageName:string,path:string,version:string,derivedVersion:string|null}> $localRepositories The local repositories declared in the DEV configuration.
     * @param string[] $warnings Non-fatal warnings surfaced while assembling the description.
     */
    public function __construct(
        private readonly ?string $mode,
        private readonly ?string $lastSwitchDate,
        private readonly array $files,
        private readonly ?string $activeFlag,
        private readonly LockStatus $lockStatus,
        private readonly InstalledState $installedState,
        private readonly ?ConfigChangeSet $pendingProdChanges,
        private readonly array $localRepositories,
        private readonly array $warnings
    )
    {
    }

    public function getMode() : ?string
    {
        return $this->mode;
    }

    public function getLastSwitchDate() : ?string
    {
        return $this->lastSwitchDate;
    }

    /**
     * @return array<int,array{label:string,path:string,exists:bool,modifiedDate:string|null}>
     */
    public function getFiles() : array
    {
        return $this->files;
    }

    /**
     * The mode (`dev`/`prod`) whose flag file currently exists,
     * or `null` if neither flag file is present.
     */
    public function getActiveFlag() : ?string
    {
        return $this->activeFlag;
    }

    public function hasActiveFlag() : bool
    {
        return $this->activeFlag !== null;
    }

    public function getLockStatus() : LockStatus
    {
        return $this->lockStatus;
    }

    public function getInstalledState() : InstalledState
    {
        return $this->installedState;
    }

    /**
     * The DEV-time edits that would become permanent production
     * changes on a DEV→PROD switch — only its `prodConfig` section is
     * ever filled, matching a real switch outcome's own
     * {@see ConfigChangeSet}. `null` outside DEV mode, or when it
     * could not be computed (e.g. a modified or malformed snapshot) —
     * degraded rather than thrown, with the reason added to
     * {@see self::getWarnings()}.
     */
    public function getPendingProdChanges() : ?ConfigChangeSet
    {
        return $this->pendingProdChanges;
    }

    public function hasPendingProdChanges() : bool
    {
        return $this->pendingProdChanges !== null && $this->pendingProdChanges->hasPermanentChanges();
    }

    /**
     * @return array<int,array{packageName:string,path:string,version:string,derivedVersion:string|null}>
     */
    public function getLocalRepositories() : array
    {
        return $this->localRepositories;
    }

    /**
     * @return string[]
     */
    public function getWarnings() : array
    {
        return $this->warnings;
    }

    public function hasWarnings() : bool
    {
        return count($this->warnings) > 0;
    }

    /**
     * @return array{
     *     mode: string|null,
     *     lastSwitchDate: string|null,
     *     files: array<int,array{label:string,path:string,exists:bool,modifiedDate:string|null}>,
     *     activeFlag: string|null,
     *     lockStatus: string,
     *     installedState: string,
     *     pendingProdChanges: array{composerJson:array<int,array<string,mixed>>,prodConfig:array<int,array<string,mixed>>}|null,
     *     localRepositories: array<int,array{packageName:string,path:string,version:string,derivedVersion:string|null}>,
     *     warnings: string[]
     * }
     */
    public function toArray() : array
    {
        return [
            'mode' => $this->mode,
            'lastSwitchDate' => $this->lastSwitchDate,
            'files' => $this->files,
            'activeFlag' => $this->activeFlag,
            'lockStatus' => $this->lockStatus->value,
            'installedState' => $this->installedState->value,
            'pendingProdChanges' => $this->pendingProdChanges?->toArray(),
            'localRepositories' => $this->localRepositories,
            'warnings' => $this->warnings
        ];
    }

    /**
     * Encodes {@see self::toArray()} as pretty-printed JSON with
     * unescaped slashes, so file paths stay readable.
     *
     * @return string
     * @throws JsonException When the assembled data cannot be encoded — not expected in practice, since every value is already a scalar, array, or `null`.
     */
    public function toJSON() : string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
