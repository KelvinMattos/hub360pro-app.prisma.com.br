<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDaily extends Model
{
    protected $table = 'analytics_daily';
    
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'gross_revenue' => 'decimal:2',
        'conversion_rate' => 'decimal:2'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}