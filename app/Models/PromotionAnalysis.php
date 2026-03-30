<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model para análise de promoções (Sheet CALCULO PROMO).
 */
class PromotionAnalysis extends Model
{
    protected $table = 'promotion_analyses';
    protected $guarded = [];

    protected $casts = [
        'current_price' => 'decimal:2',
        'breakeven_price' => 'decimal:2',
        'target_price_15' => 'decimal:2',
        'suggested_promo_price' => 'decimal:2',
        'is_deficitary' => 'boolean'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function feeConfig()
    {
        return $this->belongsTo(MarketplaceFee::class , 'marketplace_fee_id');
    }
}
