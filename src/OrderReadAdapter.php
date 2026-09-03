<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * Commerce's implementation of {@see OrderReadPort} — projects {@see OrderBook}'s
 * full order row (a `SELECT *` that includes the customer email and internal ids)
 * down to the public-safe allow-list. It builds a NEW array field by field and
 * never spreads the raw row, so a future `commerce_order` column can't silently
 * leak onto a public confirmation page.
 */
final class OrderReadAdapter implements OrderReadPort
{
    public function __construct(private OrderBook $orders)
    {
    }

    public function get(string $ref): ?array
    {
        $order = $this->orders->get($ref);
        if ($order === null) {
            return null;
        }

        $lines = [];
        foreach (is_array($order['lines'] ?? null) ? $order['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty        = (int) ($line['qty'] ?? 0);
            $unitPrice  = (string) ($line['unit_price'] ?? '0.00');
            $lines[] = [
                'sku_code'   => (string) ($line['sku_code'] ?? ''),
                'qty'        => $qty,
                'unit_price' => $unitPrice,
                // Same decimal discipline the cart uses (number_format, 2dp).
                'line_total' => number_format((float) $unitPrice * $qty, 2, '.', ''),
            ];
        }

        // Explicit allow-list — NEVER spread $order (drops customer_email, id, …).
        return [
            'reference' => (string) ($order['reference'] ?? ''),
            'status'    => (string) ($order['status'] ?? ''),
            'total'     => (string) ($order['total'] ?? '0.00'),
            'placed_at' => isset($order['placed_at']) ? (string) $order['placed_at'] : null,
            'lines'     => $lines,
        ];
    }
}
