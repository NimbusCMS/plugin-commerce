<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Commerce\OrderBook;
use NimbusCMS\Commerce\OrderReadAdapter;
use NimbusCMS\Commerce\Schema as CommerceSchema;
use PHPUnit\Framework\TestCase;

/**
 * The public order-read projection (ADR 0026 / OrderReadPort). The one control
 * that matters: the adapter emits ONLY an allow-listed, public-safe view of an
 * order — it must never leak the customer email or internal ids/location onto the
 * confirmation page, even though OrderBook::get returns the whole row.
 */
final class OrderReadAdapterTest extends TestCase
{
    private Connection $db;
    private OrderReadAdapter $adapter;

    protected function setUp(): void
    {
        $this->db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach (CommerceSchema::all() as $sql) {
            $this->db->execute($sql);
        }
        $this->db->execute('TRUNCATE ' . CommerceSchema::ORDER);
        $this->db->execute('TRUNCATE ' . CommerceSchema::LINE);

        $storage       = fn (): PluginStorage => new PluginStorage($this->db);
        $this->adapter = new OrderReadAdapter(new OrderBook($storage, static fn () => null, null));
    }

    private function seedOrder(): void
    {
        $this->db->execute(
            'INSERT INTO ' . CommerceSchema::ORDER . ' (reference, status, customer_email, currency, total, placed_at, updated_at)
             VALUES (:ref, :st, :em, :cur, :tot, :placed, :updated)',
            ['ref' => 'ORD-1', 'st' => 'placed', 'em' => 'secret@example.test', 'cur' => 'USD', 'tot' => '5.40', 'placed' => '2026-01-01 09:00:00', 'updated' => '2026-01-01 09:00:00'],
        );
        $id = (int) $this->db->selectOne('SELECT id FROM ' . CommerceSchema::ORDER . ' WHERE reference = :r', ['r' => 'ORD-1'])['id'];
        foreach ([['avocado', '2', '0.90'], ['bananas', '3', '1.20']] as [$sku, $qty, $price]) {
            $this->db->execute(
                'INSERT INTO ' . CommerceSchema::LINE . ' (order_id, sku_code, location, qty, unit_price) VALUES (:oid, :sku, :loc, :qty, :price)',
                ['oid' => $id, 'sku' => $sku, 'loc' => 'main', 'qty' => $qty, 'price' => $price],
            );
        }
    }

    public function test_projects_only_public_safe_fields(): void
    {
        $this->seedOrder();
        $order = $this->adapter->get('ORD-1');

        self::assertNotNull($order);
        self::assertSame('ORD-1', $order['reference']);
        self::assertSame('placed', $order['status']);
        self::assertSame('5.40', $order['total']);
        self::assertSame('2026-01-01 09:00:00', $order['placed_at']);
        self::assertCount(2, $order['lines']);
        self::assertSame(['sku_code' => 'avocado', 'qty' => 2, 'unit_price' => '0.90', 'line_total' => '1.80'], $order['lines'][0]);
        self::assertSame(['sku_code' => 'bananas', 'qty' => 3, 'unit_price' => '1.20', 'line_total' => '3.60'], $order['lines'][1]);
    }

    public function test_never_leaks_pii_or_internal_ids(): void
    {
        $this->seedOrder();
        $order = $this->adapter->get('ORD-1');
        self::assertNotNull($order);

        // The whole projected payload — the confirmation page renders from this.
        $flat = (string) json_encode($order);
        self::assertStringNotContainsString('secret@example.test', $flat, 'the customer email must never reach the public page');

        self::assertArrayNotHasKey('customer_email', $order);
        self::assertArrayNotHasKey('id', $order);
        self::assertArrayNotHasKey('currency', $order);
        self::assertArrayNotHasKey('updated_at', $order);
        self::assertArrayNotHasKey('id', $order['lines'][0]);
        self::assertArrayNotHasKey('location', $order['lines'][0]);
        self::assertArrayNotHasKey('order_id', $order['lines'][0]);
    }

    public function test_a_missing_order_is_null(): void
    {
        self::assertNull($this->adapter->get('NOPE'));
    }
}
