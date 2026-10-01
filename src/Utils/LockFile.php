<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

class LockFile extends BaseFile
{
    private readonly ConfigFile $configFile;

    public function __construct(ConfigFile $configFile)
    {
        $this->configFile = $configFile;

        parent::__construct(str_replace('.json', '.lock', $configFile->getPath()));
    }

    public function getContent() : string
    {
        return $this->getFileSystem()->read($this->getPath());
    }

    public function getConfigFile(): ConfigFile
    {
        return $this->configFile;
    }
}
