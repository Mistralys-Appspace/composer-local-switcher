<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\ConfigSwitcher;

class FlagFile extends BaseFile
{
    private readonly string $mode;

    public function __construct(ConfigSwitcher $switcher, string $mode)
    {
        $this->mode = strtoupper($mode);

        parent::__construct(str_replace('.json', '.json.'.$this->mode, $switcher->getMainFile()->getPath()));
    }

    public function create() : self
    {
        $this->getFileSystem()->write(
            $this->getPath(),
            $this->mode,
            'Creating ' . $this->mode . ' flag file.'
        );

        return $this;
    }
}
