<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use JsonException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;

class ConfigFile extends BaseFile
{
    private readonly LockFile $lockFile;

    public function __construct(string $path)
    {
        parent::__construct($path);

        $this->lockFile = new LockFile($this);
    }

    /**
     * @inheritDoc
     */
    public function setFileSystem(FileSystem $fileSystem) : static
    {
        parent::setFileSystem($fileSystem);

        // The lock file is created eagerly in the constructor, before
        // a shared facade can be propagated in, so it must be kept
        // in sync explicitly whenever this file's facade changes.
        $this->lockFile->setFileSystem($fileSystem);

        return $this;
    }

    public function getLockFile() : LockFile
    {
        return $this->lockFile;
    }

    /**
     * @return array<int|string, mixed>
     * @throws ComposerSwitcherException
     */
    public function getData() : array
    {
        $path = $this->getPath();

        $json = $this->getFileSystem()->read($path);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw (new ComposerSwitcherException(
                'Failed to decode JSON from file: ' . $path . '. Error: ' . $e->getMessage(),
                ComposerSwitcherException::ERROR_CANNOT_DECODE_JSON,
                $e
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $path
                ));
        }

        if(!is_array($data)) {
            throw (new ComposerSwitcherException(
                'Decoded JSON is not an array in file: ' . $path,
                ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $path,
                    ComposerSwitcherException::KEY_ACTUAL => gettype($data)
                ));
        }

        return $data;
    }

    /**
     * Saves the specified data to the file as JSON.
     *
     * @param array<int|string,mixed> $data
     * @return void
     * @throws ComposerSwitcherException
     */
    public function putData(array $data) : void
    {
        $path = $this->getPath();

        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw (new ComposerSwitcherException(
                'Failed to encode data to JSON for file: ' . $path . '. Error: ' . $e->getMessage(),
                ComposerSwitcherException::ERROR_CANNOT_ENCODE_JSON,
                $e
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $path
                ));
        }

        $this->getFileSystem()->write(
            $path,
            $json . PHP_EOL,
            'Writing configuration data to ' . $this->getBaseName() . '.'
        );
    }
}
