<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce\Tests;

use Nimbus\Auth\CapabilityRegistry;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Mcp\Guide\SkillRegistry;
use Nimbus\Mcp\McpToolsetRegistry;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginLoader;
use NimbusCMS\Commerce\CommercePlugin;
use PHPUnit\Framework\TestCase;

/**
 * The package boundary: a real Composer install of this package is discovered by
 * Nimbus's loader and registers its migration, capability, toolset and guide — no
 * database, because storage and the inventory port are taken lazily.
 */
final class PackageIntegrationTest extends TestCase
{
    private string $installedJson;

    protected function setUp(): void
    {
        $this->installedJson = tempnam(sys_get_temp_dir(), 'nb-installed-') ?: '';
    }

    protected function tearDown(): void
    {
        @unlink($this->installedJson);
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $m = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertIsArray($m);
        return $m;
    }

    private function installedAs(): string
    {
        $m = $this->manifest();
        file_put_contents($this->installedJson, json_encode([
            'packages' => [['name' => $m['name'], 'type' => $m['type'], 'extra' => $m['extra']]],
        ], JSON_THROW_ON_ERROR));
        return $this->installedJson;
    }

    public function test_it_depends_on_nimbus_and_inventory(): void
    {
        $require = $this->manifest()['require'];
        self::assertArrayHasKey('nimbuscms/nimbus', $require);
        self::assertArrayHasKey('nimbuscms/inventory', $require, 'it consumes Inventory\'s reservation port');
    }

    public function test_discovery_registers_the_migration_capability_toolset_and_guide(): void
    {
        $migrations   = new MigrationRegistry();
        $capabilities = new CapabilityRegistry();
        $mcpToolsets  = new McpToolsetRegistry();
        $skills       = new SkillRegistry();

        $loader      = new PluginLoader($this->installedAs());
        $diagnostics = $loader->load(new PluginCapabilities(
            migrations: $migrations,
            skills: $skills,
            capabilities: $capabilities,
            mcpToolsets: $mcpToolsets,
        ));

        self::assertSame([], $diagnostics, 'a correctly installed package must load cleanly');
        self::assertSame([CommercePlugin::ID => $this->manifest()['name']], $loader->registered());
        self::assertSame(['nimbuscms.commerce:001_orders'], array_column($migrations->all(), 'name'));
        self::assertSame([CommercePlugin::ID], $capabilities->managementResources());
        self::assertCount(1, $mcpToolsets->all(), 'its MCP toolset');
        self::assertNotSame([], $skills->documents(), 'its agent guide');
    }
}
