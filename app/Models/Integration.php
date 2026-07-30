<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $table = 'integrations';

    /**
     * Valor canônico de `platform` para Mercado Livre. Incidente: o OAuth
     * (SettingsController::handleMeliCallback) gravava 'mercadolivre' para a
     * credencial por conta enquanto o restante do fluxo (redirectToMeli,
     * MeliRefreshTokenCommand, SyncProductsCommand, etc.) buscava
     * 'mercadolibre' — a mesma tela de conexão gerava duas grafias
     * diferentes para o mesmo marketplace, e qualquer leitura por valor
     * exato só enxergava metade das integrações.
     */
    public const PLATFORM_MERCADO_LIVRE = 'mercadolibre';

    protected $fillable = [
        'company_id',
        'platform',
        'auto_fetch_fees',
        'account_nickname',
        'seller_id',
        'is_active',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'expires_at',
        'external_user_id',
        'external_nickname',
        'redirect_uri',
        'app_id',
        'client_secret',
        'status',
        'reputation_level',
        'power_seller_status',
        'cancellation_rate',
        'delayed_handling_rate',
        'claims_rate',
        'reputation_metrics'
    ];

    // access_token/refresh_token do Mercado Livre estavam indo pro navegador —
    // Integration::get() é serializado direto como prop do Inertia em
    // MarketplaceListingController::index() sem select nem Resource, então
    // qualquer campo não escondido aqui vaza no HTML/JSON da página.
    protected $hidden = [
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'participates_in_program' => 'boolean',
        'auto_fetch_fees' => 'boolean',
        'commission_percent' => 'float',
        'fixed_fee' => 'float',
        'is_active' => 'boolean',
        'cancellation_rate' => 'float',
        'delayed_handling_rate' => 'float',
        'claims_rate' => 'float',
        'reputation_metrics' => 'array'
    ];

    /**
     * Verifica se o token está próximo de expirar (menos de 10 minutos).
     */
    public function isNearExpiration(): bool
    {
        $expiration = $this->token_expires_at ?? $this->expires_at;
        
        if (!$expiration) return true;
        
        return $expiration->isBefore(now()->addMinutes(60));
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}