<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkuStrategy extends Model
{
    protected $table = 'sku_strategy';

    protected $fillable = [
        'company_id', 'product_id', 'sku',
        'pricing_role', 'margin_pct', 'volume_30d',
        'lifecycle_stage', 'trend_30_90_pct', 'trend_90_180_pct',
        'stock_health_index', 'stock_coverage_days', 'stock_turnover', 'stock_aging_days',
        'competitive_position', 'market_gap_pct', 'buybox_distance_pct',
        'computed_at',
    ];

    protected $casts = [
        'margin_pct' => 'float',
        'volume_30d' => 'integer',
        'trend_30_90_pct' => 'float',
        'trend_90_180_pct' => 'float',
        'stock_health_index' => 'integer',
        'stock_coverage_days' => 'float',
        'stock_turnover' => 'float',
        'stock_aging_days' => 'integer',
        'market_gap_pct' => 'float',
        'buybox_distance_pct' => 'float',
        'computed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
