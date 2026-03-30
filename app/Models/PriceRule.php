<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'product_id',
        'marketplace_item_id',
        'strategy',
        'value',
        'min_price',
        'max_price',
        'is_active',
        'last_applied_at',
    ];

    protected $casts = [
        'last_applied_at' => 'datetime',
        'value' => 'float',
        'min_price' => 'float',
        'max_price' => 'float',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
