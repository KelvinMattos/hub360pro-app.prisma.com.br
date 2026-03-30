<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingConfig extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'desired_margin' => 'decimal:2',
        'fixed_costs_extra' => 'decimal:2',
        'meli_commission_percent' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Atributo Virtual: Calcula o preço sugerido em tempo real
    public function getSuggestedPriceAttribute()
    {
        // Custos Fixos Totais (Reais)
        $fixedTotal = $this->cost_price + $this->shipping_cost + $this->fixed_costs_extra;
        
        // Taxas Percentuais Totais (Decimal)
        // Ex: 10% imposto + 14% comissão + 20% margem = 0.44
        $rates = ($this->tax_percentage + $this->meli_commission_percent + $this->desired_margin) / 100;
        
        // Fator de Divisão (Mark-up Reverso)
        $denominator = 1 - $rates;
        
        // Evita divisão por zero ou margens impossíveis (>100%)
        if ($denominator <= 0) return 0;
        
        // Cálculo: Custo Fixo / (1 - Taxas%)
        return round($fixedTotal / $denominator, 2);
    }
}