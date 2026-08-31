<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\CatalogReadPort;

/**
 * The public shopping cart (ADR 0026).
 *
 * The cart is authorised **only** by its opaque random `cart_token` (the cookie);
 * a line stores **only** `{sku, qty}`. Price is resolved **server-side** from the
 * Inventory item ({@see CatalogReadPort}) at render and at checkout — the client
 * sends a SKU and a quantity, never a price. Checkout places the order through
 * {@see OrderBook} (which reserves stock atomically) and clears the cart.
 *
 * `add` accepts only an **active** item, a **positive bounded** quantity, and caps
 * the number of lines — so a hostile client can't stuff the cart, add a hidden
 * SKU, or reserve-bomb at checkout. Abandoned carts are removed by {@see gc}.
 */
final class Cart
{
    /** Carts untouched for this many days are garbage-collected. */
    public const TTL_DAYS = 14;
    /** Max distinct lines per cart. */
    private const MAX_LINES = 100;
    /** Max quantity per line. */
    private const MAX_QTY = 999;

    /**
     * @param \Closure():PluginStorage      $storage
     * @param \Closure():?CatalogReadPort   $catalog the Inventory catalog port (price + active check)
     */
    public function __construct(
        private \Closure $storage,
        private \Closure $catalog,
        private OrderBook $orders,
    ) {
    }

    /**
     * Resolve the cart for a client-supplied token, or mint a fresh one. A token
     * the client sends that does not name an existing cart is **ignored** and a
     * new random token minted — a client can never choose its own (guessable)
     * cart id.
     *
     * @return array{token:string,csrf:string} the authoritative token (set it as the cookie) + its CSRF secret
     */
    public function getOrCreate(?string $token, string $now): array
    {
        if ($token !== null && $token !== '') {
            $row = $this->storage()->selectOne('SELECT cart_token, csrf FROM ' . Schema::CART . ' WHERE cart_token = :t', ['t' => $token]);
            if ($row !== null) {
                return ['token' => (string) $row['cart_token'], 'csrf' => (string) $row['csrf']];
            }
        }
        $new  = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->storage()->execute(
            'INSERT INTO ' . Schema::CART . ' (cart_token, csrf, created_at, updated_at) VALUES (:t, :c, :now, :now2)',
            ['t' => $new, 'c' => $csrf, 'now' => $now, 'now2' => $now],
        );
        return ['token' => $new, 'csrf' => $csrf];
    }

    /** Constant-time check that `$submitted` matches the cart's CSRF secret. */
    public function csrfOk(string $token, ?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }
        $row = $this->storage()->selectOne('SELECT csrf FROM ' . Schema::CART . ' WHERE cart_token = :t', ['t' => $token]);
        return $row !== null && hash_equals((string) $row['csrf'], $submitted);
    }

    /**
     * Add `$qty` of an **active** SKU to the cart (incrementing an existing line).
     * Rejects an unknown/inactive SKU, a non-positive/over-cap/non-integer qty, and
     * a cart already at its line cap.
     */
    public function add(string $token, string $sku, string $qty, string $now): void
    {
        $sku = trim($sku);
        $n   = $this->qty($qty);
        if (($this->catalog())?->get($sku) === null) {
            throw new \InvalidArgumentException('That product is not available.');
        }

        $s       = $this->storage();
        $existing = $s->selectOne('SELECT qty FROM ' . Schema::CART_LINE . ' WHERE cart_token = :t AND sku_code = :sku', ['t' => $token, 'sku' => $sku]);
        if ($existing === null) {
            $count = $s->selectOne('SELECT COUNT(*) AS n FROM ' . Schema::CART_LINE . ' WHERE cart_token = :t', ['t' => $token]);
            if ($count !== null && (int) $count['n'] >= self::MAX_LINES) {
                throw new \InvalidArgumentException('Your cart is full.');
            }
            $s->execute('INSERT INTO ' . Schema::CART_LINE . ' (cart_token, sku_code, qty, added_at) VALUES (:t, :sku, :qty, :now)', ['t' => $token, 'sku' => $sku, 'qty' => $n, 'now' => $now]);
        } else {
            $s->execute('UPDATE ' . Schema::CART_LINE . ' SET qty = :qty WHERE cart_token = :t AND sku_code = :sku', ['qty' => min(self::MAX_QTY, (int) $existing['qty'] + $n), 't' => $token, 'sku' => $sku]);
        }
        $this->touch($token, $now);
    }

    /** Set the exact quantity of a line; 0 removes it. */
    public function setQty(string $token, string $sku, string $qty, string $now): void
    {
        $sku = trim($sku);
        $raw = trim($qty);
        if ($raw === '0') {
            $this->remove($token, $sku, $now);
            return;
        }
        $n = $this->qty($qty);
        $this->storage()->execute('UPDATE ' . Schema::CART_LINE . ' SET qty = :qty WHERE cart_token = :t AND sku_code = :sku', ['qty' => $n, 't' => $token, 'sku' => $sku]);
        $this->touch($token, $now);
    }

    public function remove(string $token, string $sku, string $now): void
    {
        $this->storage()->execute('DELETE FROM ' . Schema::CART_LINE . ' WHERE cart_token = :t AND sku_code = :sku', ['t' => $token, 'sku' => trim($sku)]);
        $this->touch($token, $now);
    }

    /**
     * The cart's contents, priced **live** from the Inventory item — a line whose
     * item is no longer active/available is dropped (it can't be bought). Prices
     * and totals are server-computed; nothing here is client-influenced.
     *
     * @return array{lines:list<array{sku_code:string,name:string,unit:?string,qty:int,unit_price:string,line_total:string,availability:string}>,total:string,count:int}
     */
    public function contents(string $token): array
    {
        $rows    = $this->storage()->select('SELECT sku_code, qty FROM ' . Schema::CART_LINE . ' WHERE cart_token = :t ORDER BY added_at', ['t' => $token]);
        $catalog = $this->catalog();
        $lines   = [];
        $total   = 0.0;
        foreach ($rows as $r) {
            $sku  = (string) $r['sku_code'];
            $item = $catalog?->get($sku);
            if ($item === null) {
                continue; // inactive/removed since added — not purchasable
            }
            $qty       = (int) $r['qty'];
            $price     = (string) $item['price'];
            $lineTotal = number_format((float) $price * $qty, 2, '.', '');
            $total    += (float) $lineTotal;
            $lines[]   = [
                'sku_code'     => $sku,
                'name'         => (string) $item['name'],
                'unit'         => $item['unit'] === null ? null : (string) $item['unit'],
                'qty'          => $qty,
                'unit_price'   => $price,
                'line_total'   => $lineTotal,
                'availability' => (string) $item['availability'],
            ];
        }
        return ['lines' => $lines, 'total' => number_format($total, 2, '.', ''), 'count' => count($lines)];
    }

    /**
     * Place the cart as an order and clear it. Prices are resolved server-side from
     * the item (never the client), stock is reserved atomically by {@see OrderBook}
     * (a failed reserve rolls the whole order back), and only then is the cart
     * cleared. Returns the order reference.
     *
     * @param array{name?:string,email?:string} $customer
     * @throws \InvalidArgumentException when the cart is empty (nothing purchasable)
     */
    public function checkout(string $token, array $customer, string $now): string
    {
        $contents = $this->contents($token);
        if ($contents['lines'] === []) {
            throw new \InvalidArgumentException('Your cart is empty.');
        }
        $lines = array_map(
            static fn (array $l): array => ['sku' => $l['sku_code'], 'qty' => (string) $l['qty'], 'unit_price' => $l['unit_price']],
            $contents['lines'],
        );
        $email = isset($customer['email']) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) !== false ? (string) $customer['email'] : null;

        $order = $this->orders->place($lines, $email, $now, 'storefront');
        $this->clear($token, $now);
        return (string) $order['reference'];
    }

    /** Empty a cart (keeps the cart row + token). */
    public function clear(string $token, string $now): void
    {
        $this->storage()->execute('DELETE FROM ' . Schema::CART_LINE . ' WHERE cart_token = :t', ['t' => $token]);
        $this->touch($token, $now);
    }

    /**
     * Remove carts untouched for {@see TTL_DAYS} (the GC maintenance task).
     *
     * @return int carts removed
     */
    public function gc(string $now): int
    {
        $cutoff = date('Y-m-d H:i:s', (int) strtotime($now) - self::TTL_DAYS * 86400);
        $s      = $this->storage();
        $old    = $s->select('SELECT cart_token FROM ' . Schema::CART . ' WHERE updated_at < :cut', ['cut' => $cutoff]);
        foreach ($old as $c) {
            $s->execute('DELETE FROM ' . Schema::CART_LINE . ' WHERE cart_token = :t', ['t' => (string) $c['cart_token']]);
        }
        return $s->execute('DELETE FROM ' . Schema::CART . ' WHERE updated_at < :cut', ['cut' => $cutoff]);
    }

    // --- internals -------------------------------------------------------

    /** A positive integer quantity within [1, MAX_QTY]; rejects 0/neg/decimal/non-numeric. */
    private function qty(string $qty): int
    {
        $q = trim($qty);
        if (preg_match('/^\d+$/', $q) !== 1) {
            throw new \InvalidArgumentException('Enter a whole quantity.');
        }
        $n = (int) $q;
        if ($n < 1 || $n > self::MAX_QTY) {
            throw new \InvalidArgumentException('Choose a quantity between 1 and ' . self::MAX_QTY . '.');
        }
        return $n;
    }

    private function touch(string $token, string $now): void
    {
        $this->storage()->execute('UPDATE ' . Schema::CART . ' SET updated_at = :now WHERE cart_token = :t', ['now' => $now, 't' => $token]);
    }

    private function catalog(): ?CatalogReadPort
    {
        return ($this->catalog)();
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
