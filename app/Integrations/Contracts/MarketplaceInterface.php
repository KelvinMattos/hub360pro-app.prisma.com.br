<?php

namespace App\Integrations\Contracts;

interface MarketplaceInterface
{
    public function getName(): string;

    public function getIcon(): string;

    public function authenticate(): void;

    public function syncProducts(): void;

    public function syncOrders(): void;

    public function updateStock(string $externalProductId, int $quantity): bool;
}