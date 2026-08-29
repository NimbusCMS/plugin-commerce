<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Plugin\PluginStorage;

/**
 * The Commerce admin page — a read-only list of orders with their status, total
 * and lines. Registered as a GET-only plugin admin page; orders are placed and
 * moved through the MCP tools (or an agent), so this is a window, not an editor.
 *
 * Customer emails and SKU codes can originate from callers, so every value is
 * escaped before it reaches the page.
 */
final class CommerceAdmin
{
    private const STATUS_TONE = [
        'pending'   => '#9a6a12',
        'paid'      => '#0f766e',
        'fulfilled' => '#5751d6',
        'cancelled' => '#a5386b',
    ];

    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage)
    {
    }

    public function render(): string
    {
        $s      = ($this->storage)();
        $orders = $s->select('SELECT id, reference, status, customer_email, total, placed_at FROM ' . Schema::ORDER . ' ORDER BY id DESC LIMIT 50');

        $lines = [];
        foreach ($s->select('SELECT order_id, sku_code, qty, unit_price FROM ' . Schema::LINE . ' ORDER BY id') as $l) {
            $lines[(int) $l['order_id']][] = $l;
        }

        $html = '<div class="nb-page-head"><h1>Commerce</h1></div>'
            . '<p class="nb-muted" style="margin:-8px 0 20px">Orders reserve stock against Inventory. '
            . 'Placed and moved through the MCP tools — an agent can place, pay, fulfil and cancel.</p>';

        if ($orders === []) {
            $html .= '<p class="nb-muted">No orders yet. Place one with the <code>shop_place_order</code> tool.</p>';
            return $html;
        }

        $html .= '<div class="nb-table-wrap nb-stack"><table class="nb-table"><thead><tr>'
            . '<th>Order</th><th>Status</th><th>Customer</th><th>Items</th>'
            . '<th style="text-align:right">Total</th><th>Placed</th></tr></thead><tbody>';

        foreach ($orders as $o) {
            $status = (string) $o['status'];
            $tone   = self::STATUS_TONE[$status] ?? '#565d6d';
            $items  = [];
            foreach ($lines[(int) $o['id']] ?? [] as $ln) {
                $items[] = $this->e((string) $ln['qty']) . ' × <code>' . $this->e((string) $ln['sku_code']) . '</code>';
            }
            $html .= '<tr><td data-label="Order"><code>' . $this->e((string) $o['reference']) . '</code></td>'
                . '<td data-label="Status"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:.8rem;color:#fff;background:' . $tone . '">' . $this->e($status) . '</span></td>'
                . '<td data-label="Customer">' . $this->e((string) ($o['customer_email'] ?? '—')) . '</td>'
                . '<td data-label="Items" class="nb-muted">' . implode(', ', $items) . '</td>'
                . '<td data-label="Total" style="text-align:right">$' . $this->e((string) $o['total']) . '</td>'
                . '<td data-label="Placed" class="nb-muted">' . $this->e((string) $o['placed_at']) . '</td></tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
