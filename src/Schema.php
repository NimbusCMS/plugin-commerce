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
