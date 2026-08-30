<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * No inventory plugin is installed, so stock cannot be reserved and an order
 * cannot be placed (ADR 0019 soft dependency). A subclass of \RuntimeException so
 * existing callers that catch that keep working; the distinct type lets the admin
 * map it to an honest "install Inventory" notice without swallowing unrelated
 * runtime errors (e.g. a database fault) under the same message.
 */
final class NoInventory extends \RuntimeException
{
}
