<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Meta mensal de faturamento definida pelo usuário — `channel = ''` é a meta geral. */
class ChannelSalesGoal extends Model
{
    protected $table = 'channel_sales_goals';

    protected $fillable = ['company_id', 'channel', 'year', 'month', 'goal_amount'];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'goal_amount' => 'float',
    ];
}
