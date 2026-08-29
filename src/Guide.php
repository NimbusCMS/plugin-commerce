<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/** The agent-facing guide (ADR 0013), served as an MCP resource. */
final class Guide
{
    public static function text(): string
    {
        return <<<'MD'
            # Commerce

            Orders that reserve stock. Commerce owns the order; **stock lives in the
            Inventory plugin**, reached through its reservation port — so Commerce
            never touches Inventory's tables, and if no inventory plugin is installed
            placing an order is refused.

            ## Lifecycle

            An order moves through `pending → paid → fulfilled`, or is `cancelled`.

            - `shop_place_order` — create an order from `lines`
              (`{sku, qty, location?, unit_price?}`). Every line's stock is
              **reserved** as part of the same transaction; if any line can't be
              reserved, the whole order is refused and nothing is held.
            - `shop_pay_order` — mark a pending order paid. Stock stays reserved.
            - `shop_fulfil_order` — ship a paid order: each line is **issued**
              (a real stock movement) and its hold released.
            - `shop_cancel_order` — release every hold on an unfulfilled order.
            - `shop_get_order` / `shop_list_orders` — read.

            ## Sharp edges

            - A place/fulfil/cancel that can't proceed returns
              `{"ok": false, "error": …}`, not a 500 — read `message`.
            - You do not pass the actor or time; the server records them.
            - Quantities are decimal strings — send `"2"`, not 2.

            Write tools require `commerce:write`; reads require `commerce:read`.
            MD;
    }
}
