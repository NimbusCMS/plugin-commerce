<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
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

        // Admin page: an orders overview + a quick place-order form (H3).
        $context->adminPages()->register(
            'commerce',
            'Commerce',
            '🧾',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new CommerceAdmin($storage))->render($csrf, $r->query('ok') ?? $r->query('err')),
        );
        $context->adminPages()->action('commerce', 'place', static function (Request $r) use ($orders): Response {
            $sku = trim((string) ($r->input('sku') ?? ''));
            $qty = trim((string) ($r->input('qty') ?? ''));
            if ($sku === '' || $qty === '') {
                return Response::redirect('/admin/commerce?err=invalid');
            }
            $line = [
                'sku'        => $sku,
                'location'   => trim((string) ($r->input('location') ?? '')) ?: 'main',
                'qty'        => $qty,
                'unit_price' => trim((string) ($r->input('unit_price') ?? '')) ?: '0',
            ];
            $email = trim((string) ($r->input('customer_email') ?? '')) ?: null;
            try {
                $orders->place([$line], $email, date('Y-m-d H:i:s'));
                return Response::redirect('/admin/commerce?ok=placed');
            } catch (\NimbusCMS\Inventory\InsufficientStock) {
                return Response::redirect('/admin/commerce?err=short');
            } catch (\Throwable) {
                return Response::redirect('/admin/commerce?err=invalid');
            }
        });

        $context->skills()->register('Commerce', Guide::text());
    }
}
