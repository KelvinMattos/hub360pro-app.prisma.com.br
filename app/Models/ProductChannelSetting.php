<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductChannelSetting extends Model
{
    protected $table = 'product_channel_settings';

    // Usamos fillable para segurança e clareza dos campos permitidos
    protected $fillable = [
        'product_id',
        'integration_id',
        'listing_type', // classic, premium, standard
        'custom_price',
        'custom_promotional_price'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }
}