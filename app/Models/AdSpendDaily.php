<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gasto de campanha por dia, importado do relatório do Google Ads / Meta Ads Manager. */
class AdSpendDaily extends Model
{
    protected $fillable = [
        'company_id', 'ad_account_id', 'platform', 'date', 'campaign_name', 'campaign_id',
        'spend', 'impressions', 'clicks', 'conversions',
    ];

    protected $casts = [
        'date' => 'date',
        'spend' => 'float',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'conversions' => 'integer',
    ];
}
