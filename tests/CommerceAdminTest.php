<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Commerce\CommerceAdmin;
use NimbusCMS\Commerce\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The Commerce admin page rendering. Locks down the Phase 1 "Workbench" additions:
 * theme-token status pills (dark-safe), currency-aware totals, per-row lifecycle
 * buttons, the SKU datalist, and the allow-listed status filter.
 */
final class CommerceAdminTest extends TestCase
{
    private Connection $db;
    private PluginStorage $storage;
    private CommerceAdmin $admin;

    protected function setUp(): void
    {
        $this->db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...Schema::all(), ...Schema::events()] as $sql) {
            $this->db->execute($sql);
        }
        $this->db->execute('TRUNCATE ' . Schema::ORDER);
        $this->db->execute('TRUNCATE ' . Schema::LINE);
        $this->db->execute('TRUNCATE ' . Schema::EVENT);

        $this->storage = new PluginStorage($this->db);
        $this->admin   = new CommerceAdmin(fn (): PluginStorage => $this->storage);
    }

    private function order(string $ref, string $status, string $currency, string $total, string $sku): int
    {
        $oid = $this->storage->insert(
            'INSERT INTO ' . Schema::ORDER . ' (reference, status, customer_email, currency, total, placed_at, updated_at)
             VALUES (:r, :s, :e, :c, :t, :n, :n2)',
            ['r' => $ref, 's' => $status, 'e' => 'buyer@test.local', 'c' => $currency, 't' => $total, 'n' => '2026-01-01 09:00:00', 'n2' => '2026-01-01 09:00:00'],
        );
        $this->storage->insert(
            'INSERT INTO ' . Schema::LINE . ' (order_id, sku_code, location, qty, unit_price) VALUES (:o, :sku, :loc, :q, :p)',
            ['o' => $oid, 'sku' => $sku, 'loc' => 'main', 'q' => '2', 'p' => '6.25'],
        );
        return $oid;
    }

    private function event(int $oid, string $status, string $when): void
    {
        $this->storage->insert(
            'INSERT INTO ' . Schema::EVENT . ' (order_id, status, actor, occurred_at) VALUES (:o, :s, :a, :n)',
            ['o' => $oid, 's' => $status, 'a' => 'admin-ui', 'n' => $when],
        );
    }

    public function test_status_pills_use_theme_tokens_not_hard_coded_colours(): void
    {
        $this->order('ORD-1', 'pending', 'USD', '12.50', 'house-blend');
        $html = $this->admin->render('tok');

        self::assertStringContainsString('background:var(--nb-warn-bg);color:var(--nb-warn-text)', $html, 'pending pill uses theme tokens');
        self::assertStringNotContainsString('color:#fff', $html, 'no hard-coded pill colours');
        self::assertStringNotContainsString('#9a6a12', $html, 'no legacy hex tones');
    }

    public function test_totals_render_in_the_orders_currency(): void
    {
        $this->order('ORD-USD', 'pending', 'USD', '12.50', 'house-blend');
        $this->order('ORD-EUR', 'paid', 'EUR', '9.00', 'oat-milk');
        $html = $this->admin->render('tok');

        self::assertStringContainsString('$12.50', $html);
        self::assertStringContainsString('€9.00', $html);
    }

    public function test_an_unknown_currency_falls_back_to_the_code(): void
    {
        $this->order('ORD-X', 'pending', 'ZZZ', '5.00', 'thing');
        self::assertStringContainsString('5.00 ZZZ', $this->admin->render('tok'));
    }

    public function test_lifecycle_buttons_match_the_status(): void
    {
        $this->order('ORD-P', 'pending', 'USD', '1.00', 'a');
        $this->order('ORD-F', 'fulfilled', 'USD', '1.00', 'b');
        $html = $this->admin->render('tok');

        self::assertStringContainsString('action="/admin/commerce/pay"', $html, 'a pending order can be paid');
        self::assertStringContainsString('action="/admin/commerce/cancel"', $html, 'and cancelled');
        // A fulfilled order is terminal — no lifecycle buttons.
        self::assertStringNotContainsString('action="/admin/commerce/fulfil"', $html);
    }

    public function test_the_status_filter_is_allow_listed_and_narrows(): void
    {
        $this->order('ORD-P', 'pending', 'USD', '1.00', 'a');
        $this->order('ORD-D', 'paid', 'USD', '1.00', 'b');

        $paid = $this->admin->render('tok', null, 'paid');
        self::assertStringContainsString('ORD-D', $paid);
        self::assertStringNotContainsString('ORD-P', $paid);

        // A junk status is ignored (treated as no filter) and never reflected raw.
        $junk = $this->admin->render('tok', null, '"><script>alert(1)</script>');
        self::assertStringContainsString('ORD-P', $junk, 'junk filter falls back to all orders');
        self::assertStringNotContainsString('<script>alert(1)</script>', $junk);
    }

    public function test_the_place_form_suggests_previously_ordered_skus(): void
    {
        $this->order('ORD-1', 'pending', 'USD', '1.00', 'house-blend');
        $html = $this->admin->render('tok');

        self::assertStringContainsString('<datalist id="ord-skus">', $html);
        self::assertStringContainsString('<option value="house-blend">', $html);
    }

    public function test_order_rows_link_to_the_detail_view(): void
    {
        $this->order('ORD-1', 'pending', 'USD', '1.00', 'house-blend');
        self::assertStringContainsString('href="/admin/commerce?order=ORD-1"', $this->admin->render('tok'));
    }

    public function test_the_order_detail_shows_lines_and_timeline(): void
    {
        $oid = $this->order('ORD-9', 'paid', 'EUR', '12.50', 'house-blend');
        $this->event($oid, 'pending', '2026-01-01 09:00:00');
        $this->event($oid, 'paid', '2026-01-01 09:05:00');

        $html = $this->admin->render('tok', null, null, 'ORD-9');

        self::assertStringContainsString('Order <code>ORD-9</code>', $html);
        self::assertStringContainsString('house-blend', $html, 'the line');
        self::assertStringContainsString('€12.50', $html, 'total in the order currency');
        self::assertStringContainsString('Timeline', $html);
        self::assertStringContainsString('Placed', $html, 'the pending event reads as Placed');
        self::assertStringContainsString('Paid', $html);
        self::assertStringContainsString('&larr; All orders', $html, 'back link');
    }

    public function test_an_unknown_order_shows_a_helpful_note(): void
    {
        self::assertStringContainsString('No order with that reference', $this->admin->render('tok', null, null, 'ORD-NOPE'));
    }

    public function test_the_order_detail_escapes_a_hostile_reference(): void
    {
        $html = $this->admin->render('tok', null, null, '"><script>alert(1)</script>');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('No order with that reference', $html);
    }
}
