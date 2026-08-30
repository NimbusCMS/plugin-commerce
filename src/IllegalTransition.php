<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * An order was asked to move to a status it cannot reach from its current one
 * (e.g. fulfilling an unpaid order, cancelling a fulfilled one). A subclass of
 * \RuntimeException — the distinct type lets the admin actions map a bad
 * transition to an honest notice, separate from an unknown-order error.
 */
final class IllegalTransition extends \RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct("An order cannot move to \"{$to}\" from \"{$from}\".");
    }
}
