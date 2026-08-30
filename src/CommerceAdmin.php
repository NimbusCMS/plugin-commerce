<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Plugin\PluginStorage;

/**
 * The Commerce admin page — the orders list with their status, total and lines,
 * a quick place-order form, and per-row lifecycle buttons (pay, fulfil, cancel).
 * The same lifecycle an agent drives over MCP; this is the human hand on it.
 *
 * Customer emails and SKU codes can originate from callers, so every value is
 * escaped before it reaches the page. Status "pills" use the admin theme's
 * semantic tokens (which redefine per theme) rather than hard-coded colours, so
 * they stay legible in dark and every selectable theme.
 */
final class CommerceAdmin
{
    /** status => [background token, text token] — all theme-defined, so dark-safe. */
    private const STATUS_TONE = [
        'pending'   => ['--nb-warn-bg', '--nb-warn-text'],
        'paid'      => ['--nb-brand-tint', '--nb-link-color'],
        'fulfilled' => ['--nb-ok-bg', '--nb-ok-text'],
        'cancelled' => ['--nb-surface-2', '--nb-muted'],
    ];

    /** A tiny symbol map — no ext-intl dependency; unknown codes render as "12.50 XYZ". */
    private const SYMBOL = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
        'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'INR' => '₹',
    ];

    private const NOTICES = [
        'placed'      => ['ok', 'Order placed and stock reserved.'],
        'paid'        => ['ok', 'Order marked paid.'],
        'fulfilled'   => ['ok', 'Order fulfilled — stock shipped.'],
        'cancelled'   => ['ok', 'Order cancelled — stock released.'],
        'short'       => ['err', 'Not enough stock available to place that order.'],
        'badqty'      => ['err', 'Enter a valid quantity and unit price.'],
        'noinventory' => ['err', 'Install the Inventory plugin — an order reserves stock against it.'],
        'notfound'    => ['err', 'No order with that reference.'],
        'badstate'    => ['err', 'That order can’t move to that state.'],
        'invalid'     => ['err', 'Check the SKU and quantity and try again.'],
    ];

    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * @param string  $csrf   CSRF token for the forms (passed by core to the page handler)
     * @param ?string $notice a fixed notice code (from the ?ok=/?err= redirect)
     * @param ?string $status a status filter (from ?status=), allow-listed to the known statuses
     */
    public function render(string $csrf = '', ?string $notice = null, ?string $status = null): string
    {
        $s = ($this->storage)();

        // Allow-list the filter: an unknown value is ignored (never reaches SQL).
        $status = ($status !== null && isset(self::STATUS_TONE[$status])) ? $status : null;
        $where  = $status === null ? '' : ' WHERE status = :status';
        $params = $status === null ? [] : ['status' => $status];
        $orders = $s->select('SELECT id, reference, status, customer_email, currency, total, placed_at FROM ' . Schema::ORDER . $where . ' ORDER BY id DESC LIMIT 50', $params);

        /** @var list<string> $skus SKUs sold before — the place-form suggestions (own table; boundary-safe) */
        $skus = array_map(
            static fn (array $r): string => (string) $r['sku_code'],
            $s->select('SELECT DISTINCT sku_code FROM ' . Schema::LINE . ' ORDER BY sku_code'),
        );

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
            . 'Place an order below and advance it with the buttons, or drive the lifecycle over MCP.</p>'
            . $this->datalist($skus)
            . $this->placeForm($csrf);

        $html .= $this->statusFilter($status);

        if ($orders === []) {
            $html .= $status === null
                ? '<p class="nb-muted">No orders yet. Place one above, or with the <code>shop_place_order</code> tool.</p>'
                : '<p class="nb-muted">No ' . $this->e($status) . ' orders.</p>';
            return $html;
        }

        $html .= '<div class="nb-table-wrap nb-stack"><table class="nb-table"><thead><tr>'
            . '<th>Order</th><th>Status</th><th>Customer</th><th>Items</th>'
            . '<th style="text-align:right">Total</th><th>Placed</th><th>Actions</th></tr></thead><tbody>';

        foreach ($orders as $o) {
            $items = [];
            foreach ($lines[(int) $o['id']] ?? [] as $ln) {
                $items[] = $this->e((string) $ln['qty']) . ' × <code>' . $this->e((string) $ln['sku_code']) . '</code>';
            }
            $html .= '<tr><td data-label="Order"><code>' . $this->e((string) $o['reference']) . '</code></td>'
                . '<td data-label="Status">' . $this->pill((string) $o['status']) . '</td>'
                . '<td data-label="Customer">' . $this->e((string) ($o['customer_email'] ?? '—')) . '</td>'
                . '<td data-label="Items" class="nb-muted">' . implode(', ', $items) . '</td>'
                . '<td data-label="Total" style="text-align:right">' . $this->money((string) $o['total'], (string) $o['currency']) . '</td>'
                . '<td data-label="Placed" class="nb-muted">' . $this->e((string) $o['placed_at']) . '</td>'
                . '<td data-label="Actions">' . $this->actions((string) $o['reference'], (string) $o['status'], $csrf) . '</td></tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    /** A coloured status pill using theme tokens (dark-safe). */
    private function pill(string $status): string
    {
        [$bg, $fg] = self::STATUS_TONE[$status] ?? ['--nb-surface-2', '--nb-muted'];

        return '<span style="display:inline-block;padding:2px 8px;border-radius:var(--nb-radius-pill,999px);font-size:.8rem;'
            . 'background:var(' . $bg . ');color:var(' . $fg . ')">' . $this->e($status) . '</span>';
    }

    /** The lifecycle buttons valid for this order's status; each is a CSRF-protected POST. */
    private function actions(string $reference, string $status, string $csrf): string
    {
        $verbs = match ($status) {
            'pending' => ['pay' => 'Pay', 'cancel' => 'Cancel'],
            'paid'    => ['fulfil' => 'Fulfil', 'cancel' => 'Cancel'],
            default   => [],
        };
        if ($verbs === []) {
            return '<span class="nb-muted">—</span>';
        }

        $out = '<div style="display:flex;gap:.4rem;flex-wrap:wrap">';
        foreach ($verbs as $action => $label) {
            $primary = $action === 'cancel' ? '' : ' nb-btn-primary';
            $out .= '<form method="post" action="/admin/commerce/' . $this->e($action) . '" style="margin:0">'
                . '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">'
                . '<input type="hidden" name="reference" value="' . $this->e($reference) . '">'
                . '<button type="submit" class="nb-btn' . $primary . '" style="padding:2px 10px;font-size:.8rem">' . $this->e($label) . '</button>'
                . '</form>';
        }
        return $out . '</div>';
    }

    /** Status filter chips (GET links, allow-listed). */
    private function statusFilter(?string $active): string
    {
        $chip = function (string $label, ?string $status) use ($active): string {
            $on   = $status === $active;
            $href = $status === null ? '/admin/commerce' : '/admin/commerce?status=' . rawurlencode($status);
            $cls  = 'nb-btn' . ($on ? ' nb-btn-primary' : '');
            return '<a class="' . $cls . '" href="' . $this->e($href) . '">' . $this->e($label) . '</a>';
        };

        $out = '<div class="nb-stack" style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.75rem">'
            . $chip('All', null);
        foreach (array_keys(self::STATUS_TONE) as $status) {
            $out .= $chip(ucfirst($status), $status);
        }
        return $out . '</div>';
    }

    /**
     * Known-SKU suggestions for the place form, from this plugin's own order lines.
     *
     * @param list<string> $skus
     */
    private function datalist(array $skus): string
    {
        $out = '<datalist id="ord-skus">';
        foreach ($skus as $sku) {
            $out .= '<option value="' . $this->e((string) $sku) . '"></option>';
        }
        return $out . '</datalist>';
    }

    private function money(string $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $symbol   = self::SYMBOL[$currency] ?? '';

        return $symbol !== ''
            ? $this->e($symbol . $amount)
            : $this->e($amount . ' ' . $currency);
    }

    /** A quick single-line place-order form (posts to the plugin admin action with CSRF). */
    private function placeForm(string $csrf): string
    {
        $f = function (string $label, string $name, string $ph, bool $suggest = false): string {
            $list = $suggest ? ' list="ord-skus"' : '';
            return '<div class="nb-field" style="flex:1 1 130px"><label for="ord-' . $this->e($name) . '">' . $this->e($label) . '</label>'
                . '<input id="ord-' . $this->e($name) . '" name="' . $this->e($name) . '"' . $list . ' placeholder="' . $this->e($ph) . '"></div>';
        };

        return '<form class="nb-form-card" method="post" action="/admin/commerce/place" style="margin-bottom:1.5rem">'
            . '<h2>Place an order</h2>'
            . '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">'
            . '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">'
            . $f('SKU', 'sku', 'house-blend', true)
            . $f('Location', 'location', 'main')
            . $f('Qty', 'qty', '2')
            . $f('Unit price', 'unit_price', '12.50')
            . $f('Customer email', 'customer_email', 'someone@example.test')
            . '<button type="submit" class="nb-btn nb-btn-primary">Place order</button>'
            . '</div></form>';
    }

    public function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
