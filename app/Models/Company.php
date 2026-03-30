<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name', 
        'document', 
        'user_id',
        'tax_rate',             // <--- Percentual de Imposto
        'operational_cost_rate' // <--- Percentual de Custo Operacional
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function integrations()
    {
        return $this->hasMany(Integration::class);
    }
}