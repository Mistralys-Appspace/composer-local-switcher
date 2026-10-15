<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * The severity of a {@see SwitchMessage}, carried as data instead of a
 * text prefix (the former `WARNING:` convention) so a console renderer
 * can both filter (hide {@see self::Detail} unless verbose) and style
 * (e.g. {@see self::Warning}) without parsing free-form message text.
 *
 * @package Composer Switcher
 * @subpackage State
 */
enum MessageLevel: string
{
    /**
     * Bookkeeping information only shown at increased verbosity (e.g.
     * `-v`) — "Using Composer DEV configuration.", "Rebuilt a fresh DEV
     * `composer.json`.", the dry-run notice, the per-package
     * version-derivation line.
     */
    case Detail = 'detail';

    /**
     * Ordinary, always-visible information about what the switch did.
     */
    case Info = 'info';

    /**
     * A message that previously carried the free-form `WARNING:` text
     * prefix — a non-fatal condition worth the user's attention (e.g.
     * a missing lock file, a modified snapshot).
     */
    case Warning = 'warning';
}
