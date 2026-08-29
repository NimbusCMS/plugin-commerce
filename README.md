# NimbusCMS Commerce

Orders + checkout for [NimbusCMS](https://github.com/NimbusCMS/nimbus), reserving
stock against the [Inventory](https://github.com/NimbusCMS/nimbus-plugin-inventory)
plugin. An official plugin — and the one that proves the plugin platform: it
declares a capability, exposes MCP tools, emits events, and reaches Inventory
through a **typed service port**, never its tables.

## The choreography

Commerce owns the **order**; stock lives in Inventory. An order moves
`pending → paid → fulfilled`, or is `cancelled`, and each step drives Inventory:

| Step | Order | Inventory |
| --- | --- | --- |
| **place** | created | every line **reserved** (in the same transaction — a line that can't be reserved refuses the whole order) |
| **pay** | paid | — (stock stays held) |
| **fulfil** | fulfilled | every line **issued** (shipped) and its hold released |
| **cancel** | cancelled | every hold **released** |

Because both plugins share the kernel's one database connection, a reserve inside
an order's transaction *joins* it — so placing a multi-line order is atomic across
the plugin boundary: it reserves everything or nothing.

If no inventory plugin is installed, the port resolves to null and placing an order
is refused with a clear message — Commerce depends on Inventory **softly**.

## Agent-first

Every step is an MCP tool, so an agent can run a shop end to end:
`shop_place_order`, `shop_pay_order`, `shop_fulfil_order`, `shop_cancel_order`,
`shop_get_order`, `shop_list_orders` — gated on the plugin's own
`commerce:read`/`commerce:write` capability (a write tool can't be reached by a
content token).

## Install

```
composer require nimbuscms/commerce
```

It requires the Inventory plugin. Run migrations (`nimbus migrate`), then grant
`Commerce` to a role or mint a token scoped to it.

## Not in v1 (by design)

A hosted-payment webhook (the `/ext/shop/webhook` route + a `PaymentProvider`
interface), the storefront HTML, shipping/tax providers, and refunds/returns. v1 is
the order lifecycle and the inventory choreography done right, agent-drivable — the
foundation those build on.

MIT licensed.
