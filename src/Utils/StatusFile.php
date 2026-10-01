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
    }

    /**
     * Reads the current state fresh from {@see self::getData()} on
     * every call, rather than caching it on this instance.
     *
     * A per-instance cache previously lived here, but became a
     * correctness hazard once {@see \Mistralys\ComposerSwitcher\Utils\FileSystem}'s
     * dry-run overlay was introduced: a dry-run write served this
     * method a pending, never-applied mode via the overlay, and that
     * value stayed cached on this object after the overlay was
     * discarded (dry-run mode disabled), so a *later, real* read could
     * still observe the stale dry-run value instead of the file's
     * actual on-disk content. Re-reading every time keeps this file's
     * state consistent with whatever `FileSystem` reports as "current"
     * (real or overlaid) at the moment of the call.
     *
     * @return array<int|string,mixed>
     */
    private function loadState() : array
    {
        return $this->getData();
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
