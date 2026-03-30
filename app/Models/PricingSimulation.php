<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Model PricingSimulation - Arquitetura Impecável.
 * Gerencia cenários hipotéticos de precificação.
 */
class PricingSimulation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'user_id',
        'product_id',
        'scenario_name',
        'marketplace_target',
        'cost',
        'freight',
        'fixed_fee',
        'taxes_percent',
        'commission_percent',
        'margin_percent',
        'suggested_price',
        'contribution_margin_value',
        'status',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'freight' => 'decimal:2',
        'fixed_fee' => 'decimal:2',
        'taxes_percent' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'suggested_price' => 'decimal:2',
        'contribution_margin_value' => 'decimal:2',
    ];

    /**
     * Global Scope para Multi-Tenancy (Isolamento por Empresa).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if (Auth::check()) {
                $builder->where('company_id', Auth::user()->company_id);
            }
        });

        static::creating(function ($model) {
            if (Auth::check()) {
                $model->company_id = Auth::user()->company_id;
                $model->user_id = Auth::id();
            }
        });
    }

    /**
     * O usuário que criou o cenário.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O produto real (se aplicável).
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope local para buscar apenas simulações ativas (rascunhos não aplicados).
     */
    public function scopeActiveDrafts($query): Builder
    {
        // Proteção ativa: verifica se a coluna status existe antes de filtrar
        if (\Schema::hasColumn($this->getTable(), 'status')) {
            return $query->where('status', 'draft');
        }

        return $query;
    }
}
