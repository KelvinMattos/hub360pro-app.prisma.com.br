<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Representa um grupo de simulações (Cenário).
 */
class PricingSimulationScenario extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'is_applied'];

    public function simulations()
    {
        return $this->hasMany(PricingSimulation::class , 'scenario_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
