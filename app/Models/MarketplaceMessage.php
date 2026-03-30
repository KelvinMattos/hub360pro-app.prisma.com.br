<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'integration_id',
        'order_id',
        'external_id',
        'sender_id',
        'text',
        'direction',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'json',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function credential()
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
