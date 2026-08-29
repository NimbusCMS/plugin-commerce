<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Mcp\PluginTool;
use Nimbus\Mcp\PluginToolset;

/**
 * Commerce over MCP — an agent can take an order from placement to fulfilment. The
 * {@see PluginToolset} base gates every tool on the plugin's `nimbuscms.commerce`
 * capability (a write tool needs `:write`), so this class writes no auth code, and
 * domain errors (an oversell surfaced from Inventory, a bad state transition) come
 * back as data rather than a 500.
 */
final class CommerceToolset extends PluginToolset
{
    public function __construct(private OrderBook $orders)
    {
    }

    public function namespace(): string
    {
        return 'shop';
    }

    protected function tools(): array
    {
        $ref = ['type' => 'string', 'description' => 'The order reference (e.g. "ORD-1A2B3C4D").'];

        return [
            new PluginTool('place_order', 'write', 'Place an order and reserve its stock.', [
                'type'       => 'object',
                'required'   => ['lines'],
                'properties' => [
                    'lines' => [
                        'type'        => 'array',
                        'description' => 'The order lines.',
                        'items'       => [
                            'type'       => 'object',
                            'required'   => ['sku', 'qty'],
                            'properties' => [
                                'sku'        => ['type' => 'string'],
                                'location'   => ['type' => 'string', 'description' => 'Location code (default "main").'],
                                'qty'        => ['type' => 'string', 'description' => 'Decimal quantity.'],
                                'unit_price' => ['type' => 'string', 'description' => 'Price per unit (default "0").'],
                            ],
                        ],
                    ],
                    'customer_email' => ['type' => 'string'],
                ],
            ], $this->place(...)),

            new PluginTool('pay_order', 'write', 'Mark a pending order paid (stock stays reserved).', ['type' => 'object', 'required' => ['reference'], 'properties' => ['reference' => $ref]], $this->pay(...)),
            new PluginTool('fulfil_order', 'write', 'Fulfil a paid order: ship every line and release its hold.', ['type' => 'object', 'required' => ['reference'], 'properties' => ['reference' => $ref]], $this->fulfil(...)),
            new PluginTool('cancel_order', 'write', 'Cancel an unfulfilled order and release its holds.', ['type' => 'object', 'required' => ['reference'], 'properties' => ['reference' => $ref]], $this->cancel(...)),
            new PluginTool('get_order', 'read', 'An order with its lines and status.', ['type' => 'object', 'required' => ['reference'], 'properties' => ['reference' => $ref]], $this->getOrder(...)),
            new PluginTool('list_orders', 'read', 'Recent orders, newest first.', ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]], $this->list(...)),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function place(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a): array {
            /** @var list<array{sku:string,location?:string,qty:string,unit_price?:string}> $lines */
            $lines = [];
            foreach (is_array($a['lines'] ?? null) ? $a['lines'] : [] as $ln) {
                if (is_array($ln)) {
                    $lines[] = ['sku' => (string) ($ln['sku'] ?? ''), 'location' => (string) ($ln['location'] ?? 'main'), 'qty' => (string) ($ln['qty'] ?? ''), 'unit_price' => (string) ($ln['unit_price'] ?? '0')];
                }
            }
            $email = isset($a['customer_email']) && is_string($a['customer_email']) ? $a['customer_email'] : null;
            return ['ok' => true, 'order' => $this->orders->place($lines, $email, $this->now())];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function pay(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(fn (): array => ['ok' => true, 'order' => $this->orders->pay($this->ref($a), $this->now())]);
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function fulfil(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(fn (): array => ['ok' => true, 'order' => $this->orders->fulfil($this->ref($a), $p->name, $this->now())]);
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function cancel(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(fn (): array => ['ok' => true, 'order' => $this->orders->cancel($this->ref($a), $this->now())]);
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function getOrder(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $order = $this->orders->get($this->ref($a));
        return $order === null ? ['ok' => false, 'error' => 'not_found'] : ['ok' => true, 'order' => $order];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function list(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $limit = isset($a['limit']) && is_numeric($a['limit']) ? (int) $a['limit'] : 50;
        return ['orders' => $this->orders->recent($limit)];
    }

    /**
     * @param \Closure():array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function guard(\Closure $work): array
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'invalid', 'message' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => 'refused', 'message' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $a */
    private function ref(array $a): string
    {
        $ref = $a['reference'] ?? null;
        if (!is_string($ref) || trim($ref) === '') {
            throw new \InvalidArgumentException('An order "reference" is required.');
        }
        return trim($ref);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
