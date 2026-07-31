<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaFiscalPagina extends Model
{
    protected $table = 'nota_fiscal_paginas';

    protected $fillable = ['nota_fiscal_compra_id', 'page_number', 'content'];

    public function notaFiscal(): BelongsTo
    {
        return $this->belongsTo(NotaFiscalCompra::class, 'nota_fiscal_compra_id');
    }
}
