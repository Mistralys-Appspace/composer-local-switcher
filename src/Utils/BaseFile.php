<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use DateTime;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;

abstract class BaseFile
{
    private readonly string $path;
    private FileSystem $fileSystem;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->fileSystem = new FileSystem();
    }

    /**
     * Replaces the {@see FileSystem} instance this file performs all
     * of its I/O through. Used to propagate a single shared facade
     * (e.g. from {@see \Mistralys\ComposerSwitcher\ConfigSwitcher})
     * to every file instance so that dry-run mode and recorded
     * operations apply consistently across all of them.
     *
     * @return static
     */
    public function setFileSystem(FileSystem $fileSystem) : static
    {
        $this->fileSystem = $fileSystem;
        return $this;
    }

    public function getFileSystem() : FileSystem
    {
        return $this->fileSystem;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getBaseName() : string
    {
        return basename($this->path);
    }

    public function exists() : bool
    {
        return $this->fileSystem->exists($this->path);
    }

    public function getModifiedDate() : ?DateTime
    {
        return $this->fileSystem->modifiedTime($this->path);
    }

    public function requireModifiedDate() : DateTime
    {
        $date = $this->getModifiedDate();

        if($date !== null) {
            return $date;
        }

        throw (new ComposerSwitcherException(
            sprintf(
                'Cannot get modified date, file %s does not exist.',
                $this->path
            ),
            ComposerSwitcherException::ERROR_CANNOT_GET_MODIFIED_DATE
        ))
            ->setContext(array(
                ComposerSwitcherException::KEY_FILE_PATH => $this->path
            ));
    }

    public function delete() : void
    {
        $this->fileSystem->delete($this->path, 'Deleting ' . $this->getBaseName() . '.');
    }

    public function getName() : string
    {
        return basename($this->path);
    }

    public function copyTo(BaseFile $target) : void
    {
        $this->fileSystem->copy(
            $this->getPath(),
            $target->getPath(),
            'Copying ' . $this->getBaseName() . ' to ' . $target->getBaseName() . '.'
        );
    }

    /**
     * Like {@see copyTo()}, but only if the source file exists.
     * Unlike a plain existence check on the target, this lets a
     * missing target be created from an existing source — the
     * target file existing beforehand is not required.
     *
     * @param BaseFile $target
     * @return void
     */
    public function tryCopyTo(BaseFile $target) : void
    {
        if($this->exists()) {
            $this->copyTo($target);
        }
    }
}
