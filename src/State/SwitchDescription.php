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
 * gathers the underlying data — reading the status file, running
 * {@see VerificationResult}, probing the filesystem for the
 * individual file records — is the responsibility of `describe()`,
 * which is out of scope for this class.
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
     * @param VerificationResult $verification The result of comparing `composer.json` and `composer-prod.json`.
     * @param array<int,array{packageName:string,path:string,version:string}> $localRepositories The local repositories declared in the DEV configuration.
     * @param string[] $warnings Non-fatal warnings surfaced while assembling the description.
     */
    public function __construct(
        private readonly ?string $mode,
        private readonly ?string $lastSwitchDate,
        private readonly array $files,
        private readonly ?string $activeFlag,
        private readonly VerificationResult $verification,
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

    public function getVerification() : VerificationResult
    {
        return $this->verification;
    }

    /**
     * @return array<int,array{packageName:string,path:string,version:string}>
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
     *     verification: array{inSync:bool,differences:string[],devMode:bool},
     *     localRepositories: array<int,array{packageName:string,path:string,version:string}>,
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
            'verification' => $this->verification->toArray(),
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
