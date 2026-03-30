<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'integration_id',
        'external_id',
        'product_external_id',
        'question_text',
        'answer_text',
        'status',
        'buyer_username',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function credential()
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }
}
