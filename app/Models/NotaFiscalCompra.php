<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaFiscalCompra extends Model
{
    protected $table = 'notas_fiscais_compra';

    protected $fillable = [
        'company_id', 'supplier_id', 'fornecedor', 'path', 'filename',
        'data_emissao', 'hash', 'pages_count', 'status', 'error', 'indexed_at',
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'indexed_at' => 'datetime',
    ];

    public function paginas(): HasMany
    {
        return $this->hasMany(NotaFiscalPagina::class, 'nota_fiscal_compra_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function nomeFornecedor(): string
    {
        return $this->supplier->name ?? $this->fornecedor ?? 'Não identificado';
    }
}
