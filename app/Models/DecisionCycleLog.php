<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionCycleLog extends Model
{
    protected $fillable = [
        'decision_cycle_id', 'product_id', 'price_before', 'price_after', 'action', 'reason',
    ];

    protected $casts = [
        'price_before' => 'float',
        'price_after' => 'float',
    ];

    public function cycle()
    {
        return $this->belongsTo(DecisionCycle::class, 'decision_cycle_id');
    }
}
