<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use DateTime;
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
 * @package Composer Switcher
 * @subpackage Utils
 */
final class FileSystem
{
    private bool $dryRun = false;

    /**
     * @var array<string,string|null>
     */
    private array $overlay = array();

    /**
     * @var FileOperation[]
     */
    private array $operations = array();

    /**
     * @param bool $dryRun When enabling dry-run mode, the overlay starts empty.
     *              When disabling it, any pending overlay state is discarded.
     * @return $this
     */
    public function setDryRun(bool $dryRun) : self
    {
        $this->dryRun = $dryRun;
        $this->overlay = array();

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
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $path
                ));
        }

        $content = file_get_contents($path);

        if($content !== false) {
            return $content;
        }

        throw (new ComposerSwitcherException(
            'Failed to read file: ' . $path,
            ComposerSwitcherException::ERROR_CANNOT_READ_FILE
        ))
            ->setContext(array(
                ComposerSwitcherException::KEY_FILE_PATH => $path
            ));
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

        $timestamp = filemtime($path);

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

        if(file_put_contents($path, $content) === false) {
            throw (new ComposerSwitcherException(
                'Failed to write data to file: ' . $path,
                ComposerSwitcherException::ERROR_CANNOT_WRITE_FILE
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $path
                ));
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

        if(file_put_contents($target, $content) === false) {
            throw (new ComposerSwitcherException(
                'Failed to copy file from ' . $source . ' to ' . $target,
                ComposerSwitcherException::ERROR_CANNOT_COPY_FILE
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $source,
                    ComposerSwitcherException::KEY_TARGET_PATH => $target
                ));
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

        if(!unlink($path)) {
            throw (new ComposerSwitcherException(
                'Failed to delete file: ' . $path,
                ComposerSwitcherException::ERROR_CANNOT_DELETE_FILE
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $path
                ));
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
        $this->operations = array();
    }

    private function recordOperation(string $type, string $targetPath, ?string $sourcePath, string $reason, bool $applied) : void
    {
        $this->operations[] = new FileOperation($type, $targetPath, $sourcePath, $reason, $applied);
    }
}
