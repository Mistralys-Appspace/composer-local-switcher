<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object representing a single managed (local package)
 * entry the user edited anyway, which
 * {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::revert()}
 * discards in favor of the snapshot value: an in-place edit (the value
 * changed but the entry stayed in the same section), a removal (the
 * entry disappeared and no `currentSection` is known), or a move (the
 * entry reappeared, edited or not, under a different section).
 *
 * @package Composer Switcher
 * @subpackage State
 *
 * @phpstan-type ManagedEntryOverrideShape array{packageName:string,section:string,snapshotValue:mixed,currentValue:mixed,currentSection:string|null}
 */
final class ManagedEntryOverride
{
    public function __construct(
        private readonly string $packageName,
        private readonly string $section,
        private readonly mixed $snapshotValue,
        private readonly mixed $currentValue,
        private readonly ?string $currentSection = null
    )
    {
    }

    public function getPackageName() : string
    {
        return $this->packageName;
    }

    /**
     * The section the package is managed under in the snapshot
     * (the section the override is reported against).
     */
    public function getSection() : string
    {
        return $this->section;
    }

    public function getSnapshotValue() : mixed
    {
        return $this->snapshotValue;
    }

    public function getCurrentValue() : mixed
    {
        return $this->currentValue;
    }

    /**
     * The section the entry currently lives under, or `null` when
     * the entry was removed outright and no current section is known.
     */
    public function getCurrentSection() : ?string
    {
        return $this->currentSection;
    }

    /**
     * Whether this override moved the entry to a different section
     * rather than editing or removing it in place. `false` for both
     * an in-place edit (`currentSection === section`) and a removal
     * (`currentSection === null`).
     */
    public function isMove() : bool
    {
        return $this->currentSection !== null && $this->currentSection !== $this->section;
    }

    /**
     * The section(s) this override touches: both the snapshot section
     * and the current section for a move ({@see self::isMove()}), or
     * just the snapshot section for an in-place edit or a removal.
     *
     * @return string[]
     */
    public function getAffectedSections() : array
    {
        if($this->isMove()) {
            return array($this->section, (string)$this->currentSection);
        }

        return array($this->section);
    }

    /**
     * @return ManagedEntryOverrideShape
     */
    public function toArray() : array
    {
        return array(
            'packageName' => $this->packageName,
            'section' => $this->section,
            'snapshotValue' => $this->snapshotValue,
            'currentValue' => $this->currentValue,
            'currentSection' => $this->currentSection
        );
    }

    /**
     * @param ManagedEntryOverrideShape $data
     */
    public static function fromArray(array $data) : self
    {
        return new self(
            $data['packageName'],
            $data['section'],
            $data['snapshotValue'],
            $data['currentValue'],
            $data['currentSection']
        );
    }
}
