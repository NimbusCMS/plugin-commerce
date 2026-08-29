<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Http\Request;
use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginContext;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\ReservationPort;

/**
 * The official Commerce plugin — orders that reserve stock against Inventory.
 *
 * It composes the plugin keystone: a grantable capability (ADR 0015), MCP tools
 * (ADR 0016), namespaced events (ADR 0014), and — the reason it can exist as a
 * separate plugin — a **typed service port** (ADR 0019) to obtain Inventory's
 * reservation API at request time, without importing Inventory's implementation or
 * touching its tables. If no inventory plugin is installed, the port resolves to
 * null and placing an order is refused, so Commerce depends on Inventory *softly*.
 */
final class CommercePlugin implements Plugin
{
    public const ID = 'nimbuscms.commerce';

    public function register(PluginContext $context): void
    {
        $context->migrations()->register('001_orders', Schema::all());
        $context->capabilities()->declare('Commerce', ['read', 'write']);

        $storage = static fn (): PluginStorage => $context->storage();
        $emit    = static function (string $name, array $payload) use ($context): void {
            $context->events()->emit($name, $payload);
        };
        // Resolved lazily, at request time — never during register(), when the
        // provider's load order is undefined (ADR 0019).
        $stock = static fn (): ?ReservationPort => $context->services()->get(ReservationPort::class);

        $orders = new OrderBook($storage, $stock, $emit);

        $context->mcp()->register(new CommerceToolset($orders));

        // A read-only admin overview of orders; the lifecycle runs through the tools.
        $context->adminPages()->register('commerce', 'Commerce', '🧾', static fn (Request $r): string => (new CommerceAdmin($storage))->render());

        $context->skills()->register('Commerce', Guide::text());
    }
}
