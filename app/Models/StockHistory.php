<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Model para histórico de estoque (Sheet PV ATUAL).
 */
class StockHistory extends Model
{
    protected $table = 'stock_history';
    protected $fillable = ['product_id', 'quantity', 'cost_price', 'received_at'];

    protected $casts = [
        'received_at' => 'datetime',
        'cost_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calcula a idade do estoque em dias.
     */
    public function getDaysInStockAttribute(): int
    {
        return $this->received_at->diffInDays(now());
    }

    /**
     * Retorna o flag de performance (ex: +2 anos).
     */
    public function getPerformanceFlagAttribute(): string
    {
        $days = $this->days_in_stock;

        if ($days > 730)
            return '+2 anos';
        if ($days > 365)
            return '1-2 anos';
        if ($days > 180)
            return '6-12 meses';

        return 'Recente';
    }
}
