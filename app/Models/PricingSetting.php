<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $fillable = [
        'company_id', 'imposto', 'mc', 'desc_atual_default', 'desc_equil_default', 'rounding',
    ];

    protected $casts = [
        'imposto' => 'float', 'mc' => 'float',
        'desc_atual_default' => 'float', 'desc_equil_default' => 'float', 'rounding' => 'float',
    ];
}
