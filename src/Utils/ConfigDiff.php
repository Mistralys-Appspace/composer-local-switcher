<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;

/**
 * Structural diff between two `composer.json`-shaped arrays.
 *
 * This differ runs over the actual before/after arrays a switch
 * writes, rather than having a transform log its own operations —
 * the shown change set is therefore derived from the real output of
 * every code path, including one that composes `revert()` and
 * `apply()`, instead of a second, hand-maintained record that could
 * drift from what is actually written.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class ConfigDiff
{
    /**
     * Top-level keys whose value is compared as a list (item by item,
     * by value) rather than recursed into as an associative structure.
     */
    private const LIST_KEYS = array(ConfigSwitcher::KEY_REPOSITORIES);

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param callable(string[],mixed,mixed):ConfigChangeOrigin $classifier Called with the change's path, before value and after value; returns the {@see ConfigChangeOrigin} to attach.
     * @return ConfigChange[]
     */
    public static function between(array $before, array $after, callable $classifier) : array
    {
        return self::diffAssoc($before, $after, array(), $classifier);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param string[] $path
     * @param callable(string[],mixed,mixed):ConfigChangeOrigin $classifier
     * @return ConfigChange[]
     */
    private static function diffAssoc(array $before, array $after, array $path, callable $classifier) : array
    {
        $changes = array();

        foreach(self::unionKeys($before, $after) as $key) {
            $currentPath = array_merge($path, array((string)$key));
            $hasBefore = array_key_exists($key, $before);
            $hasAfter = array_key_exists($key, $after);

            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if($hasBefore && !$hasAfter) {
                $changes[] = new ConfigChange($currentPath, $beforeValue, null, ConfigChange::KIND_REMOVED, $classifier($currentPath, $beforeValue, null));
                continue;
            }

            if(!$hasBefore && $hasAfter) {
                $changes[] = new ConfigChange($currentPath, null, $afterValue, ConfigChange::KIND_ADDED, $classifier($currentPath, null, $afterValue));
                continue;
            }

            if($beforeValue === $afterValue) {
                continue;
            }

            if(in_array($key, self::LIST_KEYS, true) && is_array($beforeValue) && is_array($afterValue) && array_is_list($beforeValue) && array_is_list($afterValue)) {
                $changes = array_merge($changes, self::diffList($beforeValue, $afterValue, $currentPath, $classifier));
                continue;
            }

            if(is_array($beforeValue) && is_array($afterValue) && !array_is_list($beforeValue) && !array_is_list($afterValue)) {
                $changes = array_merge($changes, self::diffAssoc($beforeValue, $afterValue, $currentPath, $classifier));
                continue;
            }

            $changes[] = new ConfigChange($currentPath, $beforeValue, $afterValue, ConfigChange::KIND_CHANGED, $classifier($currentPath, $beforeValue, $afterValue));
        }

        return $changes;
    }

    /**
     * Compares a list's items by value: an `after` item with no
     * value-equal match in `before` is {@see ConfigChange::KIND_ADDED},
     * and a `before` item with no value-equal match in `after` is
     * {@see ConfigChange::KIND_REMOVED} — there is no `Changed` kind
     * for a list item, since a modified entry is indistinguishable
     * from a remove-then-add of two different values.
     *
     * @param array<int,mixed> $before
     * @param array<int,mixed> $after
     * @param string[] $path
     * @param callable(string[],mixed,mixed):ConfigChangeOrigin $classifier
     * @return ConfigChange[]
     */
    private static function diffList(array $before, array $after, array $path, callable $classifier) : array
    {
        $changes = array();
        $beforeRemaining = $before;

        foreach($after as $index => $entry) {
            $matchIndex = self::findValueIndex($beforeRemaining, $entry);

            if($matchIndex !== null) {
                unset($beforeRemaining[$matchIndex]);
                continue;
            }

            $entryPath = array_merge($path, array((string)$index));
            $changes[] = new ConfigChange($entryPath, null, $entry, ConfigChange::KIND_ADDED, $classifier($entryPath, null, $entry));
        }

        foreach($beforeRemaining as $index => $entry) {
            $entryPath = array_merge($path, array((string)$index));
            $changes[] = new ConfigChange($entryPath, $entry, null, ConfigChange::KIND_REMOVED, $classifier($entryPath, $entry, null));
        }

        return $changes;
    }

    /**
     * @param array<int,mixed> $haystack
     */
    private static function findValueIndex(array $haystack, mixed $needle) : ?int
    {
        foreach($haystack as $index => $value) {
            if($value === $needle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return string[]
     */
    private static function unionKeys(array $before, array $after) : array
    {
        $keys = array();

        foreach(array_keys($before) as $key) {
            $keys[$key] = true;
        }

        foreach(array_keys($after) as $key) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }
}
