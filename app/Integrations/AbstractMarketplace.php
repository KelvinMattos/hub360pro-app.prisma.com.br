<?php

namespace App\Integrations;

use App\Integrations\Contracts\MarketplaceInterface;
use App\Models\Integration;

abstract class AbstractMarketplace implements MarketplaceInterface
{
    protected Integration $integration;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    abstract public function getName(): string;

    abstract public function getIcon(): string;

// Métodos comuns podem vir aqui
}