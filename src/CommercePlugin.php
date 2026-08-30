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
        $context->migrations()->register('002_order_events', Schema::events());
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

        // Admin page: an orders overview + place form + per-row lifecycle buttons
        // (H3). Gated on this plugin's own wildcard-immune capability (ADR 0020) —
        // advancing an order in the UI needs `nimbuscms.commerce:write`, exactly
        // like the MCP tools, so a content-only editor can't.
        $context->adminPages()->register(
            'commerce',
            'Commerce',
            '🧾',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new CommerceAdmin($storage))->render($csrf, $r->query('ok') ?? $r->query('err'), $r->query('status'), $r->query('order'), $nonce),
            self::ID . ':write',
        );
        $context->adminPages()->action('commerce', 'place', static function (Request $r) use ($orders): Response {
            $sku   = trim((string) ($r->input('sku') ?? ''));
            $qty   = trim((string) ($r->input('qty') ?? ''));
            $price = trim((string) ($r->input('unit_price') ?? '')) ?: '0';
            if ($sku === '' || $qty === '') {
                return Response::redirect('/admin/commerce?err=invalid');
            }
            // Validate the numbers at the boundary so a non-numeric qty/price is an
            // honest "badqty" notice, not a database error surfacing as something else.
            if (preg_match('/^\d+(\.\d{1,4})?$/', $qty) !== 1 || preg_match('/^\d+(\.\d{1,2})?$/', $price) !== 1) {
                return Response::redirect('/admin/commerce?err=badqty');
            }
            $line = [
                'sku'        => $sku,
                'location'   => trim((string) ($r->input('location') ?? '')) ?: 'main',
                'qty'        => $qty,
                'unit_price' => $price,
            ];
            $email = trim((string) ($r->input('customer_email') ?? '')) ?: null;
            try {
                $orders->place([$line], $email, date('Y-m-d H:i:s'), 'admin-ui');
                return Response::redirect('/admin/commerce?ok=placed');
            } catch (\NimbusCMS\Inventory\InsufficientStock) {
                return Response::redirect('/admin/commerce?err=short');
            } catch (NoInventory) {
                return Response::redirect('/admin/commerce?err=noinventory');
            } catch (\InvalidArgumentException) {
                return Response::redirect('/admin/commerce?err=badqty');
            } catch (\Throwable) {
                return Response::redirect('/admin/commerce?err=invalid');
            }
        });

        // The lifecycle actions — the UI catching up to the MCP tools. Each reads
        // the order reference, advances it, and maps a typed failure to an honest
        // notice (unknown order vs illegal transition).
        foreach ([
            'pay'    => static fn (OrderBook $o, string $ref): array => $o->pay($ref, date('Y-m-d H:i:s'), 'admin-ui'),
            'fulfil' => static fn (OrderBook $o, string $ref): array => $o->fulfil($ref, 'admin-ui', date('Y-m-d H:i:s')),
            'cancel' => static fn (OrderBook $o, string $ref): array => $o->cancel($ref, date('Y-m-d H:i:s'), 'admin-ui'),
        ] as $action => $run) {
            $context->adminPages()->action('commerce', $action, static function (Request $r) use ($orders, $run, $action): Response {
                $ref = trim((string) ($r->input('reference') ?? ''));
                if ($ref === '') {
                    return Response::redirect('/admin/commerce?err=invalid');
                }
                try {
                    $run($orders, $ref);
                    return Response::redirect('/admin/commerce?ok=' . ($action === 'pay' ? 'paid' : ($action === 'fulfil' ? 'fulfilled' : 'cancelled')));
                } catch (OrderNotFound) {
                    return Response::redirect('/admin/commerce?err=notfound');
                } catch (IllegalTransition) {
                    return Response::redirect('/admin/commerce?err=badstate');
                } catch (\Throwable) {
                    return Response::redirect('/admin/commerce?err=invalid');
                }
            });
        }

        $context->skills()->register('Commerce', Guide::text());
    }
}
