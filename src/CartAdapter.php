<?php

declare(strict_types=1);

namespace NimbusCMS\Commerce;

/**
 * Commerce's implementation of {@see CartPort} — a thin delegate to the {@see Cart}
 * service that supplies the server clock, so consuming the port grants no
 * capability Commerce wouldn't and the boundary (ADR 0005) holds.
 */
final class CartAdapter implements CartPort
{
    public function __construct(private Cart $cart)
    {
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function getOrCreate(?string $token): array
    {
        return $this->cart->getOrCreate($token, $this->now());
    }

    public function csrfOk(string $token, ?string $submitted): bool
    {
        return $this->cart->csrfOk($token, $submitted);
    }

    public function add(string $token, string $sku, string $qty): void
    {
        $this->cart->add($token, $sku, $qty, $this->now());
    }

    public function setQty(string $token, string $sku, string $qty): void
    {
        $this->cart->setQty($token, $sku, $qty, $this->now());
    }

    public function remove(string $token, string $sku): void
    {
        $this->cart->remove($token, $sku, $this->now());
    }

    public function contents(string $token): array
    {
        return $this->cart->contents($token);
    }

    public function checkout(string $token, array $customer): string
    {
        return $this->cart->checkout($token, $customer, $this->now());
    }
}
