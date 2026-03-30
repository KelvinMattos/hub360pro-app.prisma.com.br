<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitorMonitor extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_tracked_price' => 'decimal:2',
        'price_gap_percent' => 'decimal:2',
        'last_check_at' => 'datetime',
        'is_active' => 'boolean'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}