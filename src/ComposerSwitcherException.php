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
    public const ERROR_INVALID_RECONCILE_DIRECTION = 182111;

    /**
     * Context key naming the offending value passed as a reconciliation direction.
     */
    public const KEY_DIRECTION = 'direction';

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
