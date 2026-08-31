<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * Commerce's own tables (ADR 0005 — prefixed away from core `nb_*` and from
 * Inventory's `inventory_*`). Commerce owns the **order**; it never stores stock —
 * that lives in Inventory, reached through the reservation port (ADR 0019).
 */
final class Schema
{
    public const ORDER = 'commerce_order';
    public const LINE  = 'commerce_order_line';
    public const EVENT = 'commerce_order_event';
    public const CART      = 'commerce_cart';
    public const CART_LINE = 'commerce_cart_line';

    /**
     * The public shopping cart (ADR 0026). A cart is authorised solely by its
     * opaque, cryptographically-random `cart_token` (the cookie) — never a
     * guessable id — and carries a per-cart `csrf` secret rendered into every form
     * and verified on the state-changing POSTs (core CSRF is session/admin-only).
     * A line stores **only** `{sku, qty}` — **never a price**; price is resolved
     * server-side from the Inventory item at render and at checkout, so a client
     * can never influence what it pays. Abandoned carts are GC'd by a maintenance
     * task.
     *
     * @return list<string> each statement individually idempotent (ADR 0005)
     */
    public static function cart(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::CART . ' (
                cart_token VARCHAR(64) NOT NULL PRIMARY KEY,
                csrf       VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_cart_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS ' . self::CART_LINE . ' (
                cart_token VARCHAR(64) NOT NULL,
                sku_code   VARCHAR(80) NOT NULL,
                qty        INT UNSIGNED NOT NULL,
                added_at   DATETIME NOT NULL,
                PRIMARY KEY (cart_token, sku_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /** @return list<string> each statement individually idempotent (ADR 0005) */
    public static function all(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::ORDER . ' (
                id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reference      VARCHAR(40) NOT NULL,
                status         VARCHAR(20) NOT NULL,
                customer_email VARCHAR(191) NULL,
                currency       VARCHAR(3) NOT NULL DEFAULT \'USD\',
                total          DECIMAL(18,2) NOT NULL DEFAULT 0,
                placed_at      DATETIME NOT NULL,
                updated_at     DATETIME NOT NULL,
                UNIQUE KEY uq_reference (reference),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS ' . self::LINE . ' (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id    BIGINT UNSIGNED NOT NULL,
                sku_code    VARCHAR(80) NOT NULL,
                location    VARCHAR(40) NOT NULL,
                qty         DECIMAL(18,4) NOT NULL,
                unit_price  DECIMAL(18,2) NOT NULL DEFAULT 0,
                INDEX idx_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /**
     * The append-only order event log (Phase 2) — one row per lifecycle transition
     * (placed/paid/fulfilled/cancelled) with the actor and time, so the order detail
     * can show a real timeline. Never updated or deleted; the order's own status
     * stays the authoritative current state.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::EVENT . ' (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id    BIGINT UNSIGNED NOT NULL,
                status      VARCHAR(20) NOT NULL,
                actor       VARCHAR(120) NOT NULL,
                occurred_at DATETIME NOT NULL,
                INDEX idx_order (order_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}
