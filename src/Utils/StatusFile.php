<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\ConfigSwitcher;

class StatusFile extends ConfigFile
{
    public const KEY_MODE = 'mode';
    public const KEY_DATE = 'date';
    public const KEY_MAIN_FILE = 'mainFile';
    public const KEY_PROD_FILE = 'prodFile';
    public const KEY_DEV_FILE = 'devFile';

    public function saveState(string $mode, ConfigSwitcher $switcher) : void
    {
        $this->putData(array(
            self::KEY_MODE => $mode,
            self::KEY_DATE => date('Y-m-d H:i:s'),
            self::KEY_MAIN_FILE => self::canonicalizePath($switcher->getMainFile()->getPath()),
            self::KEY_PROD_FILE => self::canonicalizePath($switcher->getProdFile()->getPath()),
            self::KEY_DEV_FILE => self::canonicalizePath($switcher->getDevFile()->getPath()),
        ));

        // Reset the state to reload it the next time it's requested.
        $this->state = null;
    }

    /**
     * @var array<int|string,mixed>|null
     */
    private $state = null;

    /**
     * @return array<int|string,mixed>
     */
    private function loadState() : array
    {
        if($this->state !== null) {
            return $this->state;
        }

        $this->state = $this->getData();

        return $this->state;
    }

    public function getMode() : ?string
    {
        $data = $this->loadState();
        return $data[self::KEY_MODE] ?? null;
    }

    public function getDate() : ?string
    {
        $data = $this->loadState();
        return $data[self::KEY_DATE] ?? null;
    }

    public function isDEV() : bool
    {
        return $this->getMode() === ConfigSwitcher::MODE_DEV;
    }

    public function isPROD() : bool
    {
        return $this->getMode() === ConfigSwitcher::MODE_PROD;
    }

    public function getData(): array
    {
        // Allow the file to not exist.
        if(!$this->exists()) {
            return array();
        }

        return parent::getData();
    }

    private static function canonicalizePath(string $path) : string
    {
        $resolved = realpath($path);
        return $resolved !== false ? $resolved : $path;
    }
}
