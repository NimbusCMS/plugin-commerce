<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * The contract Commerce publishes for a storefront to drive a public cart and
 * checkout (ADR 0019 service ports; ADR 0026). The Storefront depends on this
 * **interface**, obtains the live one via `$ctx->services()->get(CartPort::class)`
 * (null when Commerce is absent → the storefront hides the cart), and never
 * touches Commerce's tables.
 *
 * Authorisation is the opaque `cart_token` (the cookie) alone; every method takes
 * it. Prices are resolved server-side — the caller passes a SKU and a quantity,
 * never a price. State-changing calls are guarded by the per-cart CSRF secret
 * ({@see csrfOk}).
 */
interface CartPort
{
    /**
     * Resolve the cart for a client token or mint a fresh one (a client can't
     * choose its own token). Returns the authoritative token — set it as the
     * cookie — and the cart's CSRF secret to render into forms.
     *
     * @return array{token:string,csrf:string}
     */
    public function getOrCreate(?string $token): array;

    /** Constant-time check that a submitted CSRF token matches the cart's secret. */
    public function csrfOk(string $token, ?string $submitted): bool;

    /** Add a quantity of an active SKU (validated; rejects unknown/inactive/bad-qty). */
    public function add(string $token, string $sku, string $qty): void;

    /** Set a line's exact quantity (0 removes it). */
    public function setQty(string $token, string $sku, string $qty): void;

    public function remove(string $token, string $sku): void;

    /**
     * The cart priced live from the catalog (server-side).
     *
     * @return array{lines:list<array{sku_code:string,name:string,unit:?string,qty:int,unit_price:string,line_total:string,availability:string}>,total:string,count:int}
     */
    public function contents(string $token): array;

    /**
     * Place the cart as an order (server-side prices, atomic stock reservation) and
     * clear it. Returns the order reference.
     *
     * @param array{name?:string,email?:string} $customer
     */
    public function checkout(string $token, array $customer): string;
}
