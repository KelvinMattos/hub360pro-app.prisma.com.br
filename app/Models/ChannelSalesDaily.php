<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma linha = um dia de performance de um canal de venda (ver App\Support\SalesChannels). */
class ChannelSalesDaily extends Model
{
    protected $table = 'channel_sales_daily';

    protected $fillable = [
        'company_id', 'channel', 'sale_date',
        'gross_value', 'paid_value', 'canceled_value',
        'fees', 'shipping_cost', 'net_value', 'orders_count',
        'source_file',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'gross_value' => 'float',
        'paid_value' => 'float',
        'canceled_value' => 'float',
        'fees' => 'float',
        'shipping_cost' => 'float',
        'net_value' => 'float',
        'orders_count' => 'integer',
    ];
}
