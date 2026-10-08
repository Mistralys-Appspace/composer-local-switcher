<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable record of a single structural difference between two
 * `composer.json`-shaped arrays, produced by
 * {@see \Mistralys\ComposerSwitcher\Utils\ConfigDiff::between()}.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class ConfigChange
{
    public const KIND_ADDED = 'added';
    public const KIND_REMOVED = 'removed';
    public const KIND_CHANGED = 'changed';

    /**
     * The top-level sections whose entries are version-relevant:
     * a change at `require › <package>` or `require-dev › <package>`
     * is always a version change.
     */
    private const VERSION_RELEVANT_SECTIONS = array('require', 'require-dev');

    /**
     * @param string[] $path The leaf path, as segments (e.g. `['require', 'vendor/pkg']`).
     * @param mixed $before The value before the change, or `null` for an added entry.
     * @param mixed $after The value after the change, or `null` for a removed entry.
     * @param string $kind One of the `KIND_*` constants.
     */
    public function __construct(
        private readonly array $path,
        private readonly mixed $before,
        private readonly mixed $after,
        private readonly string $kind,
        private readonly ConfigChangeOrigin $origin
    )
    {
    }

    /**
     * @return string[]
     */
    public function getPath() : array
    {
        return $this->path;
    }

    /**
     * The path rendered as a single human-readable string, e.g.
     * `require › vendor/pkg`.
     */
    public function getPathString() : string
    {
        return implode(' › ', $this->path);
    }

    public function getBefore() : mixed
    {
        return $this->before;
    }

    public function getAfter() : mixed
    {
        return $this->after;
    }

    public function getKind() : string
    {
        return $this->kind;
    }

    public function getOrigin() : ConfigChangeOrigin
    {
        return $this->origin;
    }

    /**
     * Whether this change is relevant to dependency version
     * resolution: a `require`/`require-dev` package entry, or a
     * `repositories[…]` entry whose content carries an
     * `options.versions` alias map. The latter is content-based
     * rather than path-based, because list entries ({@see \Mistralys\ComposerSwitcher\Utils\ConfigDiff})
     * are compared whole-item (added/removed), not recursed into —
     * so there is no deeper path segment to inspect, only the entry's
     * own `before`/`after` value.
     */
    public function isVersionChange() : bool
    {
        if(count($this->path) >= 2 && in_array($this->path[0], self::VERSION_RELEVANT_SECTIONS, true)) {
            return true;
        }

        if(($this->path[0] ?? null) === 'repositories') {
            $entry = $this->after ?? $this->before;

            return is_array($entry) && isset($entry['options']['versions']);
        }

        return false;
    }

    /**
     * @return array{path:string[],before:mixed,after:mixed,kind:string,origin:string}
     */
    public function toArray() : array
    {
        return array(
            'path' => $this->path,
            'before' => $this->before,
            'after' => $this->after,
            'kind' => $this->kind,
            'origin' => $this->origin->value
        );
    }
}
