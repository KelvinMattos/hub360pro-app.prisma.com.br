<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceCredential extends Model
{
    protected $fillable = [
        'company_id',
        'marketplace',
        'account_nickname',
        'seller_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Verifica se o token está próximo de expirar (menos de 10 minutos).
     */
    public function isNearExpiration(): bool
    {
        return $this->expires_at->isBefore(now()->addMinutes(10));
    }

    /**
     * Relacionamento com a Empresa (Tenant).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
