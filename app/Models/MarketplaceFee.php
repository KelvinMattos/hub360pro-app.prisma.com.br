<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa a configuração de taxas por plataforma/canal.
 * Essencial para o cálculo de Precificação multicanal.
 * 
 * @property int $company_id
 * @property string $platform
 * @property float $commission_percent
 * @property float $tax_percent
 * @property array|null $fixed_fee_rules
 */
class MarketplaceFee extends Model
{
    protected $fillable = [
        'company_id',
        'platform',
        'commission_percent',
        'tax_percent',
        'fixed_fee_rules',
    ];

    protected $casts = [
        'fixed_fee_rules' => 'array',
        'commission_percent' => 'float',
        'tax_percent' => 'float',
    ];

    /**
     * Relacionamento com a Empresa (Tenant).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
