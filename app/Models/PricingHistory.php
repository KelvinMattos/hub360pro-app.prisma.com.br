<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Histórico e Auditoria de mudanças de preço.
 */
class PricingHistory extends Model
{
    protected $table = 'pricing_history';
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function simulation()
    {
        return $this->belongsTo(PricingSimulation::class , 'simulation_id');
    }
}
