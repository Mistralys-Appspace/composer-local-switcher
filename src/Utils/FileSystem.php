<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use DateTime;
use ErrorException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\State\FileOperation;

/**
 * Single choke-point through which every file mutation performed
 * by the library passes.
 *
 * In real mode, each method performs the corresponding I/O and
 * records an applied {@see FileOperation}. In dry-run mode
 * ({@see self::setDryRun()}), `write()`, `copy()` and `delete()`
 * never call a mutating PHP filesystem function — they only update
 * an in-memory overlay (`path => pending content|null`, where `null`
 * marks a pending delete) which `exists()`, `read()` and
 * `modifiedTime()` consult before falling back to disk. This is what
 * lets a dry run observe its own pending changes — for example,
 * `copy()` resolves its source through `read()`, so copying a file
 * that was only just (pending-)written works without touching disk.
 *
 * No native PHP warning ever escapes this facade: every real-mode
 * native filesystem call runs through {@see self::runNative()}, which
 * captures any warning as an {@see ErrorException} instead of letting
 * it reach the ambient error handler — this is what keeps a filesystem
 * failure surfacing only as a typed {@see ComposerSwitcherException},
 * never as the bare `\ErrorException` that Composer's own
 * `ErrorHandler::handle()` would raise for an unsuppressed warning.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class FileSystem
{
    private bool $dryRun = false;

    /**
     * @var array<string,string|null>
     */
    private array $overlay = [];

    /**
     * @var FileOperation[]
     */
    private array $operations = [];

    /**
     * @param bool $dryRun When enabling dry-run mode, the overlay starts empty.
     *              When disabling it, any pending overlay state is discarded.
     * @return $this
     */
    public function setDryRun(bool $dryRun) : self
    {
        $this->dryRun = $dryRun;
        $this->overlay = [];

        return $this;
    }

    public function isDryRun() : bool
    {
        return $this->dryRun;
    }

    public function exists(string $path) : bool
    {
        if($this->dryRun && array_key_exists($path, $this->overlay)) {
            return $this->overlay[$path] !== null;
        }

        return file_exists($path);
    }

    /**
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_CANNOT_READ_FILE}
     */
    public function read(string $path) : string
    {
        if($this->dryRun && array_key_exists($path, $this->overlay)) {
            $pending = $this->overlay[$path];

            if($pending !== null) {
                return $pending;
            }

            throw (new ComposerSwitcherException(
                'Failed to read file: ' . $path . ' (pending delete in dry-run mode).',
                ComposerSwitcherException::ERROR_CANNOT_READ_FILE
            ))
                ->setContext([
                    ComposerSwitcherException::KEY_FILE_PATH => $path
                ]);
        }

        $content = $this->runNative(static fn() => file_get_contents($path), $nativeError);

        if($content !== false) {
            return $content;
        }

        throw (new ComposerSwitcherException(
            'Failed to read file: ' . $path,
            ComposerSwitcherException::ERROR_CANNOT_READ_FILE,
            $nativeError
        ))
            ->setContext([
                ComposerSwitcherException::KEY_FILE_PATH => $path,
                ComposerSwitcherException::KEY_NATIVE_ERROR => $nativeError?->getMessage() ?? ''
            ]);
    }

    public function modifiedTime(string $path) : ?DateTime
    {
        if($this->dryRun && array_key_exists($path, $this->overlay)) {
            if($this->overlay[$path] === null) {
                return null;
            }

            // Pending writes have no real mtime yet; the moment they
            // are observed is the closest available approximation.
            return new DateTime();
        }

        if(!file_exists($path)) {
            return null;
        }

        $timestamp = $this->runNative(static fn() => filemtime($path), $nativeError);

        if($timestamp === false) {
            return null;
        }

        return DateTime::createFromFormat('U', (string)$timestamp) ?: null;
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $reason Human-readable reason recorded on the resulting {@see FileOperation}.
     * @return void
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_CANNOT_WRITE_FILE}
     */
    public function write(string $path, string $content, string $reason = '') : void
    {
        if($this->dryRun) {
            $this->overlay[$path] = $content;
            $this->recordOperation(FileOperation::TYPE_WRITE, $path, null, $reason, false);
            return;
        }

        $result = $this->runNative(static fn() => file_put_contents($path, $content), $nativeError);

        if($result === false) {
            throw (new ComposerSwitcherException(
                'Failed to write data to file: ' . $path,
                ComposerSwitcherException::ERROR_CANNOT_WRITE_FILE,
                $nativeError
            ))
                ->setContext([
                    ComposerSwitcherException::KEY_FILE_PATH => $path,
                    ComposerSwitcherException::KEY_NATIVE_ERROR => $nativeError?->getMessage() ?? ''
                ]);
        }

        $this->recordOperation(FileOperation::TYPE_WRITE, $path, null, $reason, true);
    }

    /**
     * Copies the content of `$source` to `$target`. The source content
     * is resolved through {@see self::read()}, so copying a file with
     * a pending overlay write works even before it has been flushed to disk.
     *
     * @param string $source
     * @param string $target
     * @param string $reason Human-readable reason recorded on the resulting {@see FileOperation}.
     * @return void
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_CANNOT_READ_FILE} {@see ComposerSwitcherException::ERROR_CANNOT_COPY_FILE}
     */
    public function copy(string $source, string $target, string $reason = '') : void
    {
        $content = $this->read($source);

        if($this->dryRun) {
            $this->overlay[$target] = $content;
            $this->recordOperation(FileOperation::TYPE_COPY, $target, $source, $reason, false);
            return;
        }

        $result = $this->runNative(static fn() => file_put_contents($target, $content), $nativeError);

        if($result === false) {
            throw (new ComposerSwitcherException(
                'Failed to copy file from ' . $source . ' to ' . $target,
                ComposerSwitcherException::ERROR_CANNOT_COPY_FILE,
                $nativeError
            ))
                ->setContext([
                    ComposerSwitcherException::KEY_FILE_PATH => $source,
                    ComposerSwitcherException::KEY_TARGET_PATH => $target,
                    ComposerSwitcherException::KEY_NATIVE_ERROR => $nativeError?->getMessage() ?? ''
                ]);
        }

        $this->recordOperation(FileOperation::TYPE_COPY, $target, $source, $reason, true);
    }

    /**
     * Deletes `$path`. A no-op (no exception, no recorded operation)
     * when the path does not currently exist.
     *
     * @param string $path
     * @param string $reason Human-readable reason recorded on the resulting {@see FileOperation}.
     * @return void
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_CANNOT_DELETE_FILE}
     */
    public function delete(string $path, string $reason = '') : void
    {
        if(!$this->exists($path)) {
            return;
        }

        if($this->dryRun) {
            $this->overlay[$path] = null;
            $this->recordOperation(FileOperation::TYPE_DELETE, $path, null, $reason, false);
            return;
        }

        $result = $this->runNative(static fn() => unlink($path), $nativeError);

        if(!$result) {
            throw (new ComposerSwitcherException(
                'Failed to delete file: ' . $path,
                ComposerSwitcherException::ERROR_CANNOT_DELETE_FILE,
                $nativeError
            ))
                ->setContext([
                    ComposerSwitcherException::KEY_FILE_PATH => $path,
                    ComposerSwitcherException::KEY_NATIVE_ERROR => $nativeError?->getMessage() ?? ''
                ]);
        }

        $this->recordOperation(FileOperation::TYPE_DELETE, $path, null, $reason, true);
    }

    /**
     * @return FileOperation[]
     */
    public function getOperations() : array
    {
        return $this->operations;
    }

    public function clearOperations() : void
    {
        $this->operations = [];
    }

    private function recordOperation(string $type, string $targetPath, ?string $sourcePath, string $reason, bool $applied) : void
    {
        $this->operations[] = new FileOperation($type, $targetPath, $sourcePath, $reason, $applied);
    }

    /**
     * Runs a native PHP call with any warning/notice it raises captured
     * into `$capturedError` instead of being emitted to the ambient
     * error handler — this is what guarantees no native warning ever
     * escapes this facade, even under a Composer-style error handler
     * that throws on any unsuppressed warning.
     *
     * The previous error handler (whatever it was, including none) is
     * always restored via `restore_error_handler()`, regardless of
     * whether `$call` throws.
     *
     * @template T
     * @param callable():T $call
     * @param ErrorException|null $capturedError Set by reference to the
     *        captured native error, or left `null` when `$call` raised none.
     * @return T
     */
    private function runNative(callable $call, ?ErrorException &$capturedError = null) : mixed
    {
        $capturedError = null;

        set_error_handler(static function(int $severity, string $message, string $file = '', int $line = 0) use (&$capturedError) : bool {
            $capturedError = new ErrorException($message, 0, $severity, $file, $line);

            // Prevents PHP's default error handler (and thus the
            // native warning) from ever running.
            return true;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
