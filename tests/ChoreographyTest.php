<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Commerce\OrderBook;
use NimbusCMS\Commerce\Schema as CommerceSchema;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\ReservationAdapter;
use NimbusCMS\Inventory\ReservationPort;
use NimbusCMS\Inventory\Reservations;
use NimbusCMS\Inventory\Schema as InventorySchema;
use PHPUnit\Framework\TestCase;

/**
 * The point of the whole initiative: Commerce reserves, ships and releases stock
 * through Inventory's port (ADR 0019), never its tables — and because both plugins
 * share the kernel's one connection, the cross-plugin work is atomic.
 *
 * Both plugins are wired over a single Connection here, exactly as the kernel wires
 * them, so a reserve inside an order's transaction joins it.
 */
final class ChoreographyTest extends TestCase
{
    private Ledger $ledger;
    private ReservationAdapter $port;
    private OrderBook $orders;
    private int $mainLoc;
    private const T = '2026-01-01 09:00:00';

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...InventorySchema::all(), ...InventorySchema::reservations(), ...CommerceSchema::all()] as $sql) {
            $db->execute($sql);
        }
        foreach ([InventorySchema::MOVEMENT, InventorySchema::STOCK, InventorySchema::LOCATION, InventorySchema::RESERVATION, CommerceSchema::ORDER, CommerceSchema::LINE] as $t) {
            $db->execute('TRUNCATE ' . $t);
        }

        $storage      = static fn (): PluginStorage => new PluginStorage($db);
        $this->ledger = new Ledger($storage);
        $this->port   = new ReservationAdapter($this->ledger, new Reservations($storage, $this->ledger));
        $port         = $this->port;
        $this->orders = new OrderBook($storage, static fn (): ReservationPort => $port);

        // Stock a SKU (Inventory's job).
        $this->mainLoc = $this->ledger->ensureLocation('main', 'Main', self::T);
        $this->ledger->receive('LATTE', $this->mainLoc, '10', 'each', 'setup', self::T);
    }

    public function test_placing_an_order_reserves_stock(): void
    {
        $order = $this->orders->place([['sku' => 'LATTE', 'location' => 'main', 'qty' => '3', 'unit_price' => '4.50']], 'a@b.test', self::T);

        self::assertSame('pending', $order['status']);
        self::assertSame('13.50', (string) $order['total']);
        self::assertSame('7.0000', $this->port->available('LATTE', 'main'), 'stock is now held for the order');
        self::assertSame('10.0000', $this->ledger->onHand('LATTE', $this->mainLoc), 'but nothing has shipped');
    }

    public function test_the_full_happy_path_place_pay_fulfil_ships_the_stock(): void
    {
        $order = $this->orders->place([['sku' => 'LATTE', 'location' => 'main', 'qty' => '4', 'unit_price' => '4']], null, self::T);
        $ref   = (string) $order['reference'];

        $this->orders->pay($ref, self::T);
        self::assertSame('paid', ($this->orders->get($ref) ?? [])['status']);
        self::assertSame('6.0000', $this->port->available('LATTE', 'main'), 'still held after payment');

        $this->orders->fulfil($ref, 'warehouse', self::T);
        self::assertSame('fulfilled', ($this->orders->get($ref) ?? [])['status']);
        self::assertSame('6.0000', $this->ledger->onHand('LATTE', $this->mainLoc), 'the 4 shipped');
        self::assertSame('6.0000', $this->port->available('LATTE', 'main'), 'and the hold cleared');
    }

    public function test_cancelling_releases_the_hold(): void
    {
        $order = $this->orders->place([['sku' => 'LATTE', 'location' => 'main', 'qty' => '5', 'unit_price' => '4']], null, self::T);
        $ref   = (string) $order['reference'];
        self::assertSame('5.0000', $this->port->available('LATTE', 'main'));

        $this->orders->cancel($ref, self::T);
        self::assertSame('cancelled', ($this->orders->get($ref) ?? [])['status']);
        self::assertSame('10.0000', $this->port->available('LATTE', 'main'), 'the hold was released');
    }

    public function test_an_order_beyond_available_is_rejected_and_nothing_is_left_reserved(): void
    {
        $this->orders->place([['sku' => 'LATTE', 'location' => 'main', 'qty' => '8', 'unit_price' => '4']], null, self::T);

        // A second order for 5 can't be met (only 2 available) — and the failure
        // must leave no partial holds or ghost order (the atomic cross-plugin path).
        try {
            $this->orders->place([['sku' => 'LATTE', 'location' => 'main', 'qty' => '5', 'unit_price' => '4']], null, self::T);
            self::fail('an unfulfillable order must be refused');
        } catch (\RuntimeException) {
            // expected — Inventory's InsufficientStock surfaces through the port
        }

        self::assertSame('2.0000', $this->port->available('LATTE', 'main'), 'only the first order holds stock');
        self::assertCount(1, $this->orders->recent(), 'the failed order rolled back — no ghost row');
    }

    public function test_a_multi_line_order_rolls_back_wholly_if_one_line_cannot_be_reserved(): void
    {
        // Line 1 fits, line 2 doesn't — the whole order (both holds + rows) rolls back.
        try {
            $this->orders->place([
                ['sku' => 'LATTE', 'location' => 'main', 'qty' => '4', 'unit_price' => '4'],
                ['sku' => 'LATTE', 'location' => 'main', 'qty' => '9', 'unit_price' => '4'],
            ], null, self::T);
            self::fail('the order must be refused');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame('10.0000', $this->port->available('LATTE', 'main'), 'line 1 hold was rolled back too');
        self::assertSame([], $this->orders->recent(), 'no order row survived');
    }

    public function test_commerce_degrades_when_no_inventory_is_installed(): void
    {
        $storage = static fn (): PluginStorage => new PluginStorage(new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]));
        // Null port = no inventory plugin (ADR 0019 fail-safe).
        $orders = new OrderBook($storage, static fn (): ?ReservationPort => null);

        $this->expectException(\RuntimeException::class);
        $orders->place([['sku' => 'X', 'qty' => '1']], null, self::T);
    }
}
