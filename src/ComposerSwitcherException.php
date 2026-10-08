<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher;

use Exception;

class ComposerSwitcherException extends Exception
{
    public const ERROR_DEV_FILE_MISSING = 182101;
    public const ERROR_CANNOT_DECODE_JSON = 182102;
    public const ERROR_INVALID_JSON_STRUCTURE = 182103;
    public const ERROR_CANNOT_ENCODE_JSON = 182104;
    public const ERROR_CANNOT_WRITE_FILE = 182105;
    public const ERROR_CANNOT_DELETE_FILE = 182106;
    public const ERROR_CANNOT_COPY_FILE = 182107;
    public const ERROR_CANNOT_READ_FILE = 182108;
    public const ERROR_CANNOT_GET_MODIFIED_DATE = 182109;
    public const ERROR_INVALID_SWITCH_MODE = 182110;

    // 182111 (ERROR_INVALID_RECONCILE_DIRECTION) retired with the
    // reconciliation family (this plan's WP-010) — not reused.

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — the
     * executed {@see \Mistralys\ComposerSwitcher\Utils\ComposerProcess::run()}
     * call returned a non-zero exit code. Context carries
     * {@see self::KEY_COMMAND} and {@see self::KEY_EXIT_CODE}.
     */
    public const ERROR_COMPOSER_COMMAND_FAILED = 182112;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\ComposerProcess} — neither
     * the `COMPOSER_BINARY` environment variable nor an executable
     * `composer` on `PATH` could be resolved.
     */
    public const ERROR_COMPOSER_BINARY_NOT_FOUND = 182113;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — the
     * planned switch is blocked by a precondition (see the printed
     * messages for the reason); no file was written.
     */
    public const ERROR_SWITCH_BLOCKED = 182114;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — a
     * switch that would change `composer.json` was run non-interactively
     * without `--yes`; the changes were printed, but nothing was written.
     */
    public const ERROR_CONFIRMATION_REQUIRED = 182115;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — the
     * second, post-confirmation preview no longer
     * {@see \Mistralys\ComposerSwitcher\State\SwitchOutcome::hasSameEffectsAs()}
     * the first one shown to the user; nothing was written.
     */
    public const ERROR_INPUTS_CHANGED = 182116;

    /**
     * Context key naming the shell-rendered Composer command that
     * failed, paired with {@see self::KEY_EXIT_CODE}.
     */
    public const KEY_COMMAND = 'command';

    /**
     * Context key naming the failed command's exit code, paired with
     * {@see self::KEY_COMMAND}.
     */
    public const KEY_EXIT_CODE = 'exitCode';

    /**
     * Context key naming the path of the file a throw site was
     * operating on (reading, writing, deleting, or checking).
     */
    public const KEY_FILE_PATH = 'filePath';

    /**
     * Context key naming the destination path of a copy operation,
     * paired with {@see self::KEY_FILE_PATH} for the source path.
     */
    public const KEY_TARGET_PATH = 'targetPath';

    /**
     * Context key naming the offending mode string passed to
     * `switchTo()`/`getFlagFile()`.
     */
    public const KEY_MODE = 'mode';

    /**
     * Context key naming the set of values that were expected/allowed,
     * paired with {@see self::KEY_ACTUAL} for the offending value.
     */
    public const KEY_EXPECTED = 'expected';

    /**
     * Context key naming the offending actual value, paired with
     * {@see self::KEY_EXPECTED}.
     */
    public const KEY_ACTUAL = 'actual';

    /**
     * Context key naming the local repository package name a throw
     * site was processing.
     */
    public const KEY_PACKAGE_NAME = 'packageName';

    /**
     * Context key holding the captured native PHP error message (from
     * the \ErrorException {@see \Mistralys\ComposerSwitcher\Utils\FileSystem}
     * captures around a failed native filesystem call) — the same
     * message is also chained as this exception's `getPrevious()`.
     */
    public const KEY_NATIVE_ERROR = 'nativeError';

    /**
     * @var array<string,mixed>
     */
    private array $context = array();

    /**
     * Attaches structured context data to this exception, in addition
     * to its free-form message. Does not affect the inherited
     * `Exception` constructor signature (message, code, previous).
     *
     * @param array<string,mixed> $context
     * @return $this
     */
    public function setContext(array $context) : self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function getContext() : array
    {
        return $this->context;
    }

    public function getContextValue(string $key) : mixed
    {
        return $this->context[$key] ?? null;
    }
}
