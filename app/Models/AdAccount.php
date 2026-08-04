<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma conta de anúncios (ex.: "Google Ads - Conta Principal") usada pra rotular o gasto importado. */
class AdAccount extends Model
{
    protected $fillable = ['company_id', 'platform', 'label', 'external_account_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
