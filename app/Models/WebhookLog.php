<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model WebhookLog - Registro robusto de notificações externas.
 */
class WebhookLog extends Model
{
    protected $fillable = [
        'company_id',
        'source',
        'event_type',
        'payload',
        'status',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Empresa vinculada (se identificável no momento do recebimento).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
