<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Commerce\Cart;
use NimbusCMS\Commerce\OrderBook;
use NimbusCMS\Commerce\Schema as CommerceSchema;
use NimbusCMS\Inventory\Catalog;
use NimbusCMS\Inventory\CatalogReadAdapter;
use NimbusCMS\Inventory\CatalogReadPort;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\ReservationAdapter;
use NimbusCMS\Inventory\ReservationPort;
use NimbusCMS\Inventory\Reservations;
use NimbusCMS\Inventory\Schema as InventorySchema;
use PHPUnit\Framework\TestCase;

/**
 * The public cart (ADR 0026). These prove the pinned security controls: the cart
 * stores only {sku, qty}; price + totals are resolved SERVER-SIDE from the item
 * (no tampering); only active SKUs and bounded whole quantities are accepted; the
 * cart token can't be client-chosen; checkout reserves stock atomically; and old
 * carts are GC'd. Both plugins are wired over one connection, as the kernel wires
 * them.
 */
final class CartTest extends TestCase
{
    private Catalog $catalog;
    private Ledger $ledger;
    private Cart $cart;
    private ReservationAdapter $port;
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
        foreach ([...InventorySchema::items(), ...InventorySchema::all(), ...InventorySchema::reservations(), ...CommerceSchema::all(), ...CommerceSchema::events(), ...CommerceSchema::cart()] as $sql) {
            $db->execute($sql);
        }
        foreach ([InventorySchema::ITEM, InventorySchema::CATEGORY, InventorySchema::MOVEMENT, InventorySchema::STOCK, InventorySchema::LOCATION, InventorySchema::RESERVATION, CommerceSchema::ORDER, CommerceSchema::LINE, CommerceSchema::EVENT, CommerceSchema::CART, CommerceSchema::CART_LINE] as $t) {
            $db->execute('TRUNCATE ' . $t);
        }

        $storage       = static fn (): PluginStorage => new PluginStorage($db);
        $this->catalog = new Catalog($storage);
        $this->ledger  = new Ledger($storage);
        $this->port    = new ReservationAdapter($this->ledger, new Reservations($storage, $this->ledger));
        $port          = $this->port;
        $orders        = new OrderBook($storage, static fn (): ReservationPort => $port);
        $catalogPort   = new CatalogReadAdapter($this->catalog);
        $this->cart    = new Cart($storage, static fn (): CatalogReadPort => $catalogPort, $orders);

        // Seed a sellable, stocked item.
        $this->catalog->saveItem('apple', ['name' => 'Apple', 'price' => '0.50', 'active' => true], self::T);
        $this->catalog->saveItem('hidden', ['name' => 'Secret', 'price' => '1.00', 'active' => false], self::T);
        $this->ledger->receive('apple', $this->ledger->ensureLocation('main', 'Main', self::T), '100', 'each', 'seed', self::T);
    }

    private function newCart(): string
    {
        return $this->cart->getOrCreate(null, self::T)['token'];
    }

    public function test_add_and_contents_price_server_side(): void
    {
        $t = $this->newCart();
        $this->cart->add($t, 'apple', '3', self::T);

        $c = $this->cart->contents($t);
        self::assertSame(1, $c['count']);
        self::assertSame('apple', $c['lines'][0]['sku_code']);
        self::assertSame(3, $c['lines'][0]['qty']);
        self::assertSame('0.50', $c['lines'][0]['unit_price'], 'price comes from the item, not the client');
        self::assertSame('1.50', $c['lines'][0]['line_total']);
        self::assertSame('1.50', $c['total']);
    }

    public function test_price_follows_the_item_not_a_stored_snapshot(): void
    {
        $t = $this->newCart();
        $this->cart->add($t, 'apple', '2', self::T);
        // The merchant changes the price; the cart reflects the live price.
        $this->catalog->saveItem('apple', ['price' => '0.75'], self::T);
        self::assertSame('1.50', $this->cart->contents($t)['total']);
    }

    public function test_an_inactive_or_unknown_sku_cannot_be_added(): void
    {
        $t = $this->newCart();
        foreach (['hidden', 'no-such'] as $sku) {
            try {
                $this->cart->add($t, $sku, '1', self::T);
                self::fail("adding {$sku} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->cart->contents($t)['count']);
    }

    public function test_quantity_must_be_a_bounded_whole_number(): void
    {
        $t = $this->newCart();
        foreach (['0', '-1', 'abc', '1.5', '1000000'] as $bad) {
            try {
                $this->cart->add($t, 'apple', $bad, self::T);
                self::fail("qty {$bad} should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_set_qty_zero_removes_the_line(): void
    {
        $t = $this->newCart();
        $this->cart->add($t, 'apple', '2', self::T);
        $this->cart->setQty($t, 'apple', '0', self::T);
        self::assertSame(0, $this->cart->contents($t)['count']);
    }

    public function test_a_client_cannot_choose_its_own_cart_token(): void
    {
        // A token the client invents does not become a cart — a fresh random one is minted.
        $out = $this->cart->getOrCreate('attacker-chosen-token', self::T);
        self::assertNotSame('attacker-chosen-token', $out['token']);
        self::assertSame(64, strlen($out['token']), 'a 32-byte random hex token');
    }

    public function test_csrf_secret_is_checked_constant_time(): void
    {
        $c = $this->cart->getOrCreate(null, self::T);
        self::assertTrue($this->cart->csrfOk($c['token'], $c['csrf']));
        self::assertFalse($this->cart->csrfOk($c['token'], 'wrong'));
        self::assertFalse($this->cart->csrfOk($c['token'], null));
    }

    public function test_checkout_places_a_server_priced_order_reserves_stock_and_clears_the_cart(): void
    {
        $t = $this->newCart();
        $this->cart->add($t, 'apple', '4', self::T);

        $ref = $this->cart->checkout($t, ['name' => 'Sam', 'email' => 'sam@example.test'], self::T);

        self::assertNotSame('', $ref);
        // Stock reserved (4 of 100 held), cart emptied.
        self::assertSame('96.0000', $this->port->available('apple', 'main'));
        self::assertSame(0, $this->cart->contents($t)['count']);
    }

    public function test_checkout_of_an_empty_cart_is_refused(): void
    {
        $t = $this->newCart();
        $this->expectException(\InvalidArgumentException::class);
        $this->cart->checkout($t, ['email' => 'a@b.test'], self::T);
    }

    public function test_gc_removes_carts_untouched_past_the_ttl(): void
    {
        $old = $this->cart->getOrCreate(null, '2026-01-01 09:00:00')['token'];
        $this->cart->add($old, 'apple', '1', '2026-01-01 09:00:00');
        // 20 days later.
        $removed = $this->cart->gc('2026-01-21 09:00:00');
        self::assertGreaterThanOrEqual(1, $removed);
        self::assertSame(0, $this->cart->contents($old)['count'], 'the old cart and its lines are gone');
    }
}
