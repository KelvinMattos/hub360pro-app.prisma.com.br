<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa uma Despesa Fixa da empresa (Tenancy).
 * Utilizada para o rateio dinâmico no DRE.
 */
class FixedExpense extends Model
{
    protected $fillable = [
        'company_id',
        'description',
        'category',
        'amount',
        'expense_date',
    ];

    protected $casts = [
        'amount' => 'float',
        'expense_date' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
