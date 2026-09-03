<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * The read contract Commerce publishes so a storefront can render a public **order
 * confirmation** — the read counterpart to {@see CartPort} (ADR 0019 service ports;
 * ADR 0026 public checkout). A presentation plugin (the Storefront) depends on this
 * **interface**, obtains the live one via `$ctx->services()->get(OrderReadPort::class)`
 * — `null` when Commerce is absent — and never touches Commerce's tables.
 *
 * **Public-safe by construction.** It returns ONLY an allow-listed projection of an
 * order: its reference, coarse status, total, timestamp, and line items (sku, qty,
 * unit price, line total). It deliberately withholds the customer email, internal
 * ids, and stock location — nothing the confirmation page shouldn't show. The
 * caller is responsible for authorising *which* order the visitor may read (the
 * storefront gates `/order/{ref}` on the one-time order cookie, ADR 0026); this port
 * only shapes a safe view, it grants no access decision.
 */
interface OrderReadPort
{
    /**
     * One order by reference, projected to public-safe fields, or null when absent.
     *
     * @return array{
     *     reference:string,
     *     status:string,
     *     total:string,
     *     placed_at:?string,
     *     lines:list<array{sku_code:string,qty:int,unit_price:string,line_total:string}>
     * }|null
     */
    public function get(string $ref): ?array;
}
