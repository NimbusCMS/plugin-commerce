<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\ReservationPort;

/**
 * The order lifecycle, and the one place Commerce talks to Inventory — through the
 * reservation port (ADR 0019), never its tables.
 *
 * The choreography:
 *  - **place**  → create the order and **reserve** every line's stock. Because
 *    Commerce and Inventory share the kernel's one database connection, the port's
 *    reserve joins Commerce's transaction, so if any line can't be reserved the
 *    whole order — rows and holds — rolls back together. No order is ever left
 *    half-reserved.
 *  - **pay**     → mark paid (a real integration flips this from a payment webhook;
 *    stock stays reserved).
 *  - **fulfil**  → **issue** every line (ship the stock and release its hold, on
 *    the ledger).
 *  - **cancel**  → **release** every line's hold.
 *
 * If no inventory plugin is installed the port is null and placing an order is
 * refused with a clear message — a *soft* dependency (ADR 0019), not a crash.
 */
final class OrderBook
{
    public const PENDING   = 'pending';
    public const PAID      = 'paid';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';

    /**
     * @param \Closure():PluginStorage                   $storage
     * @param \Closure():?ReservationPort                $stock  resolves the inventory port at
     *   request time (null when no inventory plugin is installed — ADR 0019 fail-safe);
     *   never obtained during register(), when plugin load order is undefined
     * @param ?\Closure(string,array<string,mixed>):void $emit   namespaced plugin events (H1)
     */
    public function __construct(
        private \Closure $storage,
        private \Closure $stock,
        private ?\Closure $emit = null,
    ) {
    }

    private function stock(): ?ReservationPort
    {
        return ($this->stock)();
    }

    /**
     * Place an order and reserve its stock, atomically.
     *
     * @param list<array{sku:string,location?:string,qty:string,unit_price?:string}> $lines
     *
     * @return array<string,mixed> the created order
     *
     * @throws \RuntimeException         if no inventory plugin is installed
     * @throws \InvalidArgumentException on an empty order
     */
    public function place(array $lines, ?string $customerEmail, string $now, string $actor = 'system'): array
    {
        $port = $this->stock();
        if ($port === null) {
            throw new NoInventory('No inventory plugin is installed, so stock cannot be reserved — an order cannot be placed.');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('An order needs at least one line.');
        }

        $ref = $this->newReference();
        $this->storage()->transaction(function () use ($lines, $customerEmail, $now, $ref, $port, $actor): void {
            $s   = $this->storage();
            $oid = $s->insert(
                'INSERT INTO ' . Schema::ORDER . ' (reference, status, customer_email, currency, total, placed_at, updated_at)
                 VALUES (:ref, :status, :email, :cur, 0, :now, :now2)',
                ['ref' => $ref, 'status' => self::PENDING, 'email' => $customerEmail, 'cur' => 'USD', 'now' => $now, 'now2' => $now],
            );

            foreach ($lines as $ln) {
                $location = $this->str($ln['location'] ?? 'main');
                $lineId   = $s->insert(
                    'INSERT INTO ' . Schema::LINE . ' (order_id, sku_code, location, qty, unit_price)
                     VALUES (:oid, :sku, :loc, :qty, :price)',
                    ['oid' => $oid, 'sku' => $this->str($ln['sku']), 'loc' => $location, 'qty' => $this->str($ln['qty']), 'price' => $this->str($ln['unit_price'] ?? '0')],
                );
                // Joins this transaction (shared connection): a failed reserve rolls
                // the whole order back, holds included.
                $port->reserve($this->str($ln['sku']), $location, $this->str($ln['qty']), $this->lineRef($ref, $lineId));
            }

            $s->execute(
                'UPDATE ' . Schema::ORDER . ' SET total = (SELECT COALESCE(SUM(qty * unit_price), 0) FROM ' . Schema::LINE . ' WHERE order_id = :oid) WHERE id = :oid2',
                ['oid' => $oid, 'oid2' => $oid],
            );
            $this->recordEvent($oid, self::PENDING, $actor, $now);
        });

        $this->announce('placed', $ref);
        return $this->get($ref) ?? throw new \RuntimeException('Order vanished after placement.');
    }

    /**
     * Mark a pending order paid. Stock stays reserved until fulfilment.
     *
     * @return array<string,mixed>
     */
    public function pay(string $ref, string $now, string $actor = 'system'): array
    {
        $order = $this->requireOrder($ref);
        if ($order['status'] !== self::PENDING) {
            throw new IllegalTransition((string) $order['status'], self::PAID);
        }
        $this->storage()->transaction(function () use ($order, $actor, $now): void {
            $this->setStatus((int) $order['id'], self::PAID, $now);
            $this->recordEvent((int) $order['id'], self::PAID, $actor, $now);
        });
        $this->announce('paid', $ref);
        return $this->get($ref) ?? throw new \RuntimeException('Unknown order.');
    }

    /**
     * Fulfil a paid order: issue (ship) every line and release its hold.
     *
     * @return array<string,mixed>
     */
    public function fulfil(string $ref, string $actor, string $now): array
    {
        $order = $this->requireOrder($ref);
        if ($order['status'] !== self::PAID) {
            throw new IllegalTransition((string) $order['status'], self::FULFILLED);
        }

        $this->storage()->transaction(function () use ($order, $ref, $actor, $now): void {
            foreach ($this->linesOf((int) $order['id']) as $ln) {
                $this->stock()?->issue((string) $ln['sku_code'], (string) $ln['location'], (string) $ln['qty'], $this->lineRef($ref, (int) $ln['id']), $actor);
            }
            $this->setStatus((int) $order['id'], self::FULFILLED, $now);
            $this->recordEvent((int) $order['id'], self::FULFILLED, $actor, $now);
        });

        $this->announce('fulfilled', $ref);
        return $this->get($ref) ?? throw new \RuntimeException('Unknown order.');
    }

    /**
     * Cancel an unfulfilled order: release every line's hold.
     *
     * @return array<string,mixed>
     */
    public function cancel(string $ref, string $now, string $actor = 'system'): array
    {
        $order = $this->requireOrder($ref);
        if ($order['status'] === self::FULFILLED) {
            throw new IllegalTransition(self::FULFILLED, self::CANCELLED);
        }
        if ($order['status'] === self::CANCELLED) {
            return $this->get($ref) ?? throw new \RuntimeException('Unknown order.');
        }

        $this->storage()->transaction(function () use ($order, $ref, $actor, $now): void {
            foreach ($this->linesOf((int) $order['id']) as $ln) {
                $this->stock()?->release($this->lineRef($ref, (int) $ln['id']));
            }
            $this->setStatus((int) $order['id'], self::CANCELLED, $now);
            $this->recordEvent((int) $order['id'], self::CANCELLED, $actor, $now);
        });

        $this->announce('cancelled', $ref);
        return $this->get($ref) ?? throw new \RuntimeException('Unknown order.');
    }

    /**
     * An order with its lines, or null.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $ref): ?array
    {
        $order = $this->storage()->selectOne('SELECT * FROM ' . Schema::ORDER . ' WHERE reference = :ref', ['ref' => $ref]);
        if ($order === null) {
            return null;
        }
        $order['lines'] = $this->linesOf((int) $order['id']);
        return $order;
    }

    /**
     * Recent orders (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->storage()->select('SELECT reference, status, customer_email, total, placed_at FROM ' . Schema::ORDER . ' ORDER BY id DESC LIMIT ' . $limit);
    }

    // --- internals -------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function linesOf(int $orderId): array
    {
        return $this->storage()->select('SELECT id, sku_code, location, qty, unit_price FROM ' . Schema::LINE . ' WHERE order_id = :oid ORDER BY id', ['oid' => $orderId]);
    }

    /** @return array<string,mixed> */
    private function requireOrder(string $ref): array
    {
        $order = $this->storage()->selectOne('SELECT * FROM ' . Schema::ORDER . ' WHERE reference = :ref', ['ref' => $ref]);
        if ($order === null) {
            throw new OrderNotFound($ref);
        }
        return $order;
    }

    private function setStatus(int $orderId, string $status, string $now): void
    {
        $this->storage()->execute('UPDATE ' . Schema::ORDER . ' SET status = :st, updated_at = :now WHERE id = :oid', ['st' => $status, 'now' => $now, 'oid' => $orderId]);
    }

    /**
     * Append one row to the order's append-only event log (the timeline). Called
     * inside each transition's transaction; `actor` is server-set by the caller
     * (the admin action or the token principal), never from request input.
     */
    private function recordEvent(int $orderId, string $status, string $actor, string $now): void
    {
        $this->storage()->insert(
            'INSERT INTO ' . Schema::EVENT . ' (order_id, status, actor, occurred_at) VALUES (:o, :s, :a, :n)',
            ['o' => $orderId, 's' => $status, 'a' => $actor, 'n' => $now],
        );
    }

    /**
     * The order's lifecycle timeline (append-only), oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function timeline(string $ref): array
    {
        $order = $this->storage()->selectOne('SELECT id FROM ' . Schema::ORDER . ' WHERE reference = :ref', ['ref' => $ref]);
        if ($order === null) {
            return [];
        }
        return $this->storage()->select(
            'SELECT status, actor, occurred_at FROM ' . Schema::EVENT . ' WHERE order_id = :oid ORDER BY id',
            ['oid' => (int) $order['id']],
        );
    }

    private function lineRef(string $orderRef, int $lineId): string
    {
        return $orderRef . ':' . $lineId;
    }

    private function newReference(): string
    {
        return 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function announce(string $event, string $reference): void
    {
        if ($this->emit !== null) {
            ($this->emit)($event, ['reference' => $reference]);
        }
    }

    private function str(mixed $v): string
    {
        return is_string($v) || is_int($v) || is_float($v) ? (string) $v : '';
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
