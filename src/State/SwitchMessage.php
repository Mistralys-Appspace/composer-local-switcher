<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object representing a single switch message,
 * pairing free-form text with an optional numeric message code.
 *
 * A code of `0` is treated as "no code" — see {@see self::hasCode()}.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class SwitchMessage
{
    public function __construct(
        private readonly int $code,
        private readonly string $text
    )
    {
    }

    public function getCode() : int
    {
        return $this->code;
    }

    public function getText() : string
    {
        return $this->text;
    }

    /**
     * Whether this message carries a specific message code.
     * Code `0` (the default/unset value) is treated as "no code".
     */
    public function hasCode() : bool
    {
        return $this->code > 0;
    }

    public function __toString() : string
    {
        return $this->text;
    }

    /**
     * @return array{code:int,text:string}
     */
    public function toArray() : array
    {
        return [
            'code' => $this->code,
            'text' => $this->text
        ];
    }
}
