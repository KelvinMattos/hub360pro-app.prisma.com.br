<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingHistory extends Model
{
    protected $fillable = [
        'company_id', 'product_id', 'marketplace_item_id', 'field', 'old_value', 'new_value', 'action_source'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
