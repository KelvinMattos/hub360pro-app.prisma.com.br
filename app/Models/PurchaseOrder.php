<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'company_id', 'supplier_id', 'status',
        'total_amount', 'issue_date', 'expected_delivery_date', 'notes'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expected_delivery_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class , 'origin');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class , 'reference');
    }
}