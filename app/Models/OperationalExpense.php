<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Representa as despesas operacionais da empresa (Custos Fixos/Variáveis).
 * Essencial para o cálculo de Lucro Líquido Real e DRE.
 */
class OperationalExpense extends Model
{
    protected $fillable = [
        'company_id',
        'description',
        'amount',
        'type',
        'category',
        'reference_date',
        'is_paid'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reference_date' => 'date',
        'is_paid' => 'boolean'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
