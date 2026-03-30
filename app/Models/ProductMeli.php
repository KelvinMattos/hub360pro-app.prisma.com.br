<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMeli extends Model
{
    protected $table = 'products_meli';
    
    protected $guarded = [];

    protected $casts = [
        'attributes_snapshot' => 'array', // JSON com a ficha técnica
        'health_score' => 'decimal:2'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}