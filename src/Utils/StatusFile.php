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

    /**
     * Key for the snapshot hash (an MD5 of the snapshot's config plus
     * lock contents) recorded at the moment a switch was applied —
     * lets a later read detect that the on-disk config/lock no longer
     * matches what this switch actually produced (a tampered or
     * independently edited snapshot).
     */
    public const KEY_SNAPSHOT_HASH = 'snapshotHash';

    /**
     * Key for the list of local repositories that were applied by
     * the switch this status snapshot describes — lets a later read
     * compute a refresh delta (which entries were added/removed/
     * changed) without re-deriving it from the DEV config alone.
     */
    public const KEY_APPLIED_REPOSITORIES = 'appliedRepositories';

    /**
     * @param string $mode
     * @param ConfigSwitcher $switcher
     * @param string|null $snapshotHash An MD5 of the snapshot's config
     *        plus lock contents, or `null` to omit it (e.g. a caller
     *        not yet able to compute it). Omitted rather than stored
     *        as `null` so a legacy read of an older status file and a
     *        read of a file saved without a hash are indistinguishable
     *        — both report `null` from {@see self::getSnapshotHash()}.
     * @param array<int,array<string,mixed>>|null $appliedRepositories The
     *        applied local repositories, as plain serializable data
     *        (e.g. one {@see \Mistralys\ComposerSwitcher\State\LocalRepository::toArray()}
     *        per entry) — this class does not depend on that type
     *        itself, to keep the status file's persistence concern
     *        decoupled from the specific value object a caller uses.
     * @return void
     */
    public function saveState(
        string $mode,
        ConfigSwitcher $switcher,
        ?string $snapshotHash = null,
        ?array $appliedRepositories = null
    ) : void
    {
        $data = array(
            self::KEY_MODE => $mode,
            self::KEY_DATE => date('Y-m-d H:i:s'),
            self::KEY_MAIN_FILE => self::canonicalizePath($switcher->getMainFile()->getPath()),
            self::KEY_PROD_FILE => self::canonicalizePath($switcher->getProdFile()->getPath()),
            self::KEY_DEV_FILE => self::canonicalizePath($switcher->getDevFile()->getPath()),
        );

        if($snapshotHash !== null) {
            $data[self::KEY_SNAPSHOT_HASH] = $snapshotHash;
        }

        if($appliedRepositories !== null) {
            $data[self::KEY_APPLIED_REPOSITORIES] = $appliedRepositories;
        }

        $this->putData($data);
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

    /**
     * The snapshot hash recorded by the last {@see self::saveState()}
     * call, or `null` when absent — either because no switch has ever
     * saved one (a legacy status file written before this key existed)
     * or because the caller explicitly omitted it. Both cases are
     * indistinguishable by design: a `null` read always means "no
     * tamper check possible", never a thrown error.
     */
    public function getSnapshotHash() : ?string
    {
        $data = $this->loadState();
        $value = $data[self::KEY_SNAPSHOT_HASH] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The applied local repositories recorded by the last
     * {@see self::saveState()} call, or `null` when absent (a legacy
     * status file, or a caller that omitted the value) — never a
     * thrown error.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function getAppliedRepositories() : ?array
    {
        $data = $this->loadState();
        $value = $data[self::KEY_APPLIED_REPOSITORIES] ?? null;

        return is_array($value) ? $value : null;
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
