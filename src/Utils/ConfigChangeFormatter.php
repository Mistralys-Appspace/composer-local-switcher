<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\State\ConfigChange;

/**
 * Turns a single {@see ConfigChange} into one human-readable line,
 * selecting a shape-specific describer instead of ever printing a raw
 * JSON object — the part of a switch's output a user must read before
 * confirming (see {@see \Mistralys\ComposerSwitcher\Utils\OutcomeRenderer}).
 *
 * Shapes, most to least specific:
 * - A path `repositories` entry: package, relative path, symlink
 *   state, and the alias version with the JSON key it was read from
 *   (`options.versions` — a path repository's alias can also appear as
 *   the root `require`/`require-dev` constraint, so naming the key
 *   disambiguates which value actually governs resolution).
 * - A non-path (VCS/other) `repositories` entry: its `type` and `url`.
 * - A `require`/`require-dev` constraint: section, package, before and
 *   after.
 * - Any other scalar-to-scalar change: path, before and after.
 * - Anything else (arrays/objects on either side): a compact,
 *   slash-and-unicode-unescaped JSON fallback, truncated past
 *   {@see self::MAX_VALUE_LENGTH}.
 *
 * Describers are private methods of this one class, selected by a
 * `match`/`if` chain on the change's shape — not a pluggable interface
 * or registry, since there is no second consumer to justify an
 * extension point at five shapes.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class ConfigChangeFormatter
{
    /**
     * The longest a generic JSON fallback value is allowed to render
     * before being truncated with a trailing `…`.
     */
    public const MAX_VALUE_LENGTH = 120;

    private readonly string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    /**
     * Describes a single {@see ConfigChange} as one human-readable
     * line, with no leading/trailing whitespace and no origin marker —
     * the caller (e.g. {@see OutcomeRenderer}) is responsible for
     * appending a `[permanent]`/`[discarded]` marker when relevant.
     */
    public function describe(ConfigChange $change) : string
    {
        $path = $change->getPath();

        if(($path[0] ?? null) === 'repositories')
        {
            $entry = $change->getAfter() ?? $change->getBefore();

            if(is_array($entry))
            {
                return $this->describeRepositoryEntry($entry);
            }
        }

        if(count($path) >= 2 && in_array($path[0], array('require', 'require-dev'), true))
        {
            return $this->describeConstraintChange($change);
        }

        if($this->isScalarOrNull($change->getBefore()) && $this->isScalarOrNull($change->getAfter()))
        {
            return $this->describeScalarChange($change);
        }

        return $this->describeGeneric($change);
    }

    /**
     * Gives a project-relative path (`./…`) when `$path` is under the
     * project root, a sibling-relative path (`../…`) when it is
     * adjacent to it (shares the same parent directory), and the
     * absolute path unchanged otherwise.
     */
    public function displayPath(string $path) : string
    {
        if($path === '')
        {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);

        if($normalized === $this->projectRoot)
        {
            return '.';
        }

        if(str_starts_with($normalized, $this->projectRoot . '/'))
        {
            return '.' . substr($normalized, strlen($this->projectRoot));
        }

        $parent = rtrim(dirname($this->projectRoot), '/');

        if($parent !== '' && $parent !== '.' && str_starts_with($normalized, $parent . '/'))
        {
            return '..' . substr($normalized, strlen($parent));
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $entry A `repositories` list entry (the change's `after` value, or `before` for a removal).
     */
    private function describeRepositoryEntry(array $entry) : string
    {
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : 'unknown';

        if($type !== 'path')
        {
            $url = is_string($entry['url'] ?? null) ? $entry['url'] : '(no url)';

            return sprintf('`%s` repository `%s`', $type, $url);
        }

        return $this->describePathRepository($entry);
    }

    /**
     * @param array<string,mixed> $entry A `type: path` repository entry, see {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::buildRepositoryEntry()}.
     */
    private function describePathRepository(array $entry) : string
    {
        $url = is_string($entry['url'] ?? null) ? $entry['url'] : '';
        $options = is_array($entry['options'] ?? null) ? $entry['options'] : array();
        $symlink = ($options['symlink'] ?? false) === true;
        $versions = is_array($options['versions'] ?? null) ? $options['versions'] : array();

        $package = array_key_first($versions);
        $alias = is_string($package) ? ($versions[$package] ?? null) : null;

        $description = sprintf('local path `%s`', $this->displayPath($url));

        if(is_string($package))
        {
            $description .= sprintf(' for `%s`', $package);
        }

        $details = array($symlink ? 'symlinked' : 'not symlinked');

        if(is_string($alias))
        {
            $details[] = sprintf('as version `%s` from `options.versions`', $alias);
        }

        return $description . ' (' . implode(', ', $details) . ')';
    }

    private function describeConstraintChange(ConfigChange $change) : string
    {
        $path = $change->getPath();

        return sprintf(
            '%s › %s: %s → %s',
            $path[0],
            $path[1] ?? '',
            $this->formatScalar($change->getBefore()),
            $this->formatScalar($change->getAfter())
        );
    }

    private function describeScalarChange(ConfigChange $change) : string
    {
        return sprintf(
            '%s: %s → %s',
            $change->getPathString(),
            $this->formatScalar($change->getBefore()),
            $this->formatScalar($change->getAfter())
        );
    }

    private function describeGeneric(ConfigChange $change) : string
    {
        return sprintf(
            '%s: %s → %s',
            $change->getPathString(),
            $this->formatValue($change->getBefore()),
            $this->formatValue($change->getAfter())
        );
    }

    private function isScalarOrNull(mixed $value) : bool
    {
        return $value === null || is_scalar($value);
    }

    private function formatScalar(mixed $value) : string
    {
        if($value === null)
        {
            return 'null';
        }

        if(is_bool($value))
        {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    private function formatValue(mixed $value) : string
    {
        if($this->isScalarOrNull($value))
        {
            return $this->formatScalar($value);
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '…';

        if(strlen($json) > self::MAX_VALUE_LENGTH)
        {
            $json = substr($json, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        return $json;
    }
}
