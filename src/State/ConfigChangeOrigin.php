<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Why a given {@see ConfigChange} exists, from the switcher's own
 * point of view — not just *what* changed, but *whose* change it is.
 *
 * @package Composer Switcher
 * @subpackage State
 */
enum ConfigChangeOrigin: string
{
    /**
     * An entry the switcher itself manages: a local package's
     * require/require-dev constraint or its path repository entry,
     * written by {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::apply()}.
     */
    case LocalSwitch = 'local-switch';

    /**
     * A DEV-time edit to a non-managed entry (a `composer require`/
     * `remove`, a script/autoload edit, a manually added repository)
     * becoming — or already being — permanent, carried back by
     * {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::revert()}.
     */
    case CarriedBack = 'carried-back';

    /**
     * A DEV-time edit to a managed entry, reset back to its snapshot
     * value by {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::revert()}
     * rather than kept.
     */
    case Discarded = 'discarded';
}
