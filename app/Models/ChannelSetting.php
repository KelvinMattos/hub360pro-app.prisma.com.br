<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelSetting extends Model
{
    protected $fillable = [
        'company_id', 'channel_key', 'label', 'origem', 'col', 'comissao',
        'tem_faixa', 'markup', 'desc_atual', 'desc_equil', 'active', 'sort_order',
    ];

    protected $casts = [
        'comissao' => 'float', 'markup' => 'float',
        'desc_atual' => 'float', 'desc_equil' => 'float',
        'active' => 'boolean', 'sort_order' => 'integer',
    ];
}
