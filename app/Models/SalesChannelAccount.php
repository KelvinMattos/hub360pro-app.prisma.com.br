<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma conta física de um canal (ex.: "Mercado Livre - Loja A") usada pra rotular pedidos importados. */
class SalesChannelAccount extends Model
{
    protected $fillable = ['company_id', 'channel', 'label', 'external_identifier', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
