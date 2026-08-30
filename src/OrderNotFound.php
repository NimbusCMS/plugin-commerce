<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * No order exists for the given reference. A subclass of \RuntimeException, so
 * existing callers that catch that keep working; the distinct type lets a caller
 * (the admin actions) map "unknown order" to an honest notice separate from an
 * illegal transition.
 */
final class OrderNotFound extends \RuntimeException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct("No order with reference \"{$reference}\".");
    }
}
