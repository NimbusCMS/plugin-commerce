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

    private const NOTICES = [
        'placed'    => ['ok', 'Order placed and stock reserved.'],
        'paid'      => ['ok', 'Order marked paid.'],
        'fulfilled' => ['ok', 'Order fulfilled — stock shipped.'],
        'cancelled' => ['ok', 'Order cancelled — stock released.'],
        'short'     => ['err', 'Not enough stock available to place that order.'],
        'invalid'   => ['err', 'Check the SKU and quantity and try again.'],
    ];

    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * @param string  $csrf   CSRF token for the forms (passed by core to the page handler)
     * @param ?string $notice a fixed notice code (from the ?ok=/?err= redirect)
     */
    public function render(string $csrf = '', ?string $notice = null): string
    {
        $s      = ($this->storage)();
        $orders = $s->select('SELECT id, reference, status, customer_email, total, placed_at FROM ' . Schema::ORDER . ' ORDER BY id DESC LIMIT 50');

        $banner = '';
        if ($notice !== null && isset(self::NOTICES[$notice])) {
            [$kind, $msg] = self::NOTICES[$notice];
            $banner = '<div class="nb-notice nb-notice-' . ($kind === 'ok' ? 'ok' : 'error') . '">' . $this->e($msg) . '</div>';
        }

        $lines = [];
        foreach ($s->select('SELECT order_id, sku_code, qty, unit_price FROM ' . Schema::LINE . ' ORDER BY id') as $l) {
            $lines[(int) $l['order_id']][] = $l;
        }

        $html = '<div class="nb-page-head"><h1>Commerce</h1></div>' . $banner
            . '<p class="nb-muted" style="margin:-8px 0 20px">Orders reserve stock against Inventory. '
            . 'Place a quick order below, or drive the full lifecycle (pay, fulfil, cancel) over MCP.</p>'
            . $this->placeForm($csrf);

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

    /** A quick single-line place-order form (posts to the plugin admin action with CSRF). */
    private function placeForm(string $csrf): string
    {
        $f = static fn (string $label, string $name, string $ph): string =>
            '<div class="nb-field" style="flex:1 1 130px"><label for="ord-' . htmlspecialchars($name, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</label>'
            . '<input id="ord-' . htmlspecialchars($name, ENT_QUOTES) . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" placeholder="' . htmlspecialchars($ph, ENT_QUOTES) . '"></div>';

        return '<form class="nb-form-card" method="post" action="/admin/commerce/place" style="margin-bottom:1.5rem">'
            . '<h2>Place an order</h2>'
            . '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">'
            . '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">'
            . $f('SKU', 'sku', 'house-blend')
            . $f('Location', 'location', 'main')
            . $f('Qty', 'qty', '2')
            . $f('Unit price', 'unit_price', '12.50')
            . $f('Customer email', 'customer_email', 'someone@example.test')
            . '<button type="submit" class="nb-btn nb-btn-primary">Place order</button>'
            . '</div></form>';
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
