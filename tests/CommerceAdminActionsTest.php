<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce\Tests;

use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Database\Connection;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginLoader;
use Nimbus\Plugin\PluginStorage;
use Nimbus\Plugin\ServiceRegistry;
use NimbusCMS\Commerce\Schema as CommerceSchema;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Schema as InventorySchema;
use PHPUnit\Framework\TestCase;

/**
 * The Commerce admin lifecycle actions (H3), exercised through the *real* loader
 * with Inventory loaded alongside — so Commerce resolves Inventory's reservation
 * port exactly as the kernel wires it. Proves the page gates on the plugin's own
 * capability (ADR 0020; registration would throw on an older core) and that each
 * action maps a typed failure to an honest notice.
 */
final class CommerceAdminActionsTest extends TestCase
{
    private Connection $db;
    private string $installedJson;
    /** @var array<string,callable(Request):Response> */
    private array $actions;

    protected function setUp(): void
    {
        $this->db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...InventorySchema::all(), ...InventorySchema::reservations(), ...CommerceSchema::all(), ...CommerceSchema::events()] as $sql) {
            $this->db->execute($sql);
        }
        foreach ([InventorySchema::MOVEMENT, InventorySchema::STOCK, InventorySchema::LOCATION, InventorySchema::RESERVATION, CommerceSchema::ORDER, CommerceSchema::LINE, CommerceSchema::EVENT] as $t) {
            $this->db->execute('TRUNCATE ' . $t);
        }

        // Stock a SKU so orders can be placed.
        $ledger = new Ledger(fn (): PluginStorage => new PluginStorage($this->db));
        $ledger->receive('LATTE', $ledger->ensureLocation('main', 'Main', '2026-01-01 09:00:00'), '10', 'each', 'setup', '2026-01-01 09:00:00');

        // Install BOTH packages so Commerce resolves Inventory's port from the shared
        // service registry — the real cross-plugin wiring.
        $commerce  = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        $inventory = json_decode((string) file_get_contents(__DIR__ . '/../vendor/nimbuscms/inventory/composer.json'), true);
        $pkg       = static fn (array $m): array => ['name' => $m['name'], 'type' => $m['type'], 'extra' => $m['extra']];
        $this->installedJson = (string) tempnam(sys_get_temp_dir(), 'nb-installed-');
        file_put_contents($this->installedJson, json_encode(['packages' => [$pkg($inventory), $pkg($commerce)]], JSON_THROW_ON_ERROR));

        $adminPages  = new AdminPageRegistry();
        $diagnostics = (new PluginLoader($this->installedJson))->load(new PluginCapabilities(
            adminPages: $adminPages,
            services: new ServiceRegistry(),
            db: $this->db,
        ));
        self::assertSame([], $diagnostics, 'both plugins load cleanly (Commerce page gated on nimbuscms.commerce:write)');

        $this->actions = [];
        foreach ($adminPages->actions() as $a) {
            if ($a['provider'] === 'nimbuscms.commerce') {
                $this->actions[$a['action']] = $a['handler'];
            }
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->installedJson);
    }

    /** @param array<string,string> $input */
    private function post(string $action, array $input): Response
    {
        return ($this->actions[$action])(new Request('POST', '/admin/commerce/' . $action, [], $input, [], []));
    }

    private function place(): string
    {
        $r = $this->post('place', ['sku' => 'LATTE', 'location' => 'main', 'qty' => '2', 'unit_price' => '4.50']);
        self::assertSame('/admin/commerce?ok=placed', $r->header('Location'), 'placing succeeds when stock is available');
        $ref = $this->db->selectOne('SELECT reference FROM ' . CommerceSchema::ORDER . ' ORDER BY id DESC LIMIT 1');
        return (string) $ref['reference'];
    }

    public function test_place_reserves_and_redirects_ok(): void
    {
        $this->place();
    }

    public function test_place_more_than_available_is_short(): void
    {
        $r = $this->post('place', ['sku' => 'LATTE', 'location' => 'main', 'qty' => '999', 'unit_price' => '4.50']);
        self::assertSame('/admin/commerce?err=short', $r->header('Location'));
    }

    public function test_place_a_bad_quantity_is_badqty(): void
    {
        $r = $this->post('place', ['sku' => 'LATTE', 'location' => 'main', 'qty' => 'lots', 'unit_price' => '4.50']);
        self::assertSame('/admin/commerce?err=badqty', $r->header('Location'));
    }

    public function test_pay_then_fulfil_advances_the_order(): void
    {
        $ref = $this->place();

        self::assertSame('/admin/commerce?ok=paid', $this->post('pay', ['reference' => $ref])->header('Location'));
        self::assertSame('/admin/commerce?ok=fulfilled', $this->post('fulfil', ['reference' => $ref])->header('Location'));
    }

    public function test_cancel_releases_the_hold(): void
    {
        $ref = $this->place();
        self::assertSame('/admin/commerce?ok=cancelled', $this->post('cancel', ['reference' => $ref])->header('Location'));
    }

    public function test_paying_an_unknown_order_is_notfound(): void
    {
        $r = $this->post('pay', ['reference' => 'ORD-NOPE']);
        self::assertSame('/admin/commerce?err=notfound', $r->header('Location'));
    }

    public function test_fulfilling_an_unpaid_order_is_badstate(): void
    {
        $ref = $this->place(); // still pending
        $r   = $this->post('fulfil', ['reference' => $ref]);
        self::assertSame('/admin/commerce?err=badstate', $r->header('Location'));
    }

    public function test_a_missing_reference_is_invalid(): void
    {
        self::assertSame('/admin/commerce?err=invalid', $this->post('pay', [])->header('Location'));
    }
}
