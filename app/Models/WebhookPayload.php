<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistência bruta de webhooks (Landing Zone).
 * Garante que nenhuma notificação seja perdida e permite re-processamento.
 */
class WebhookPayload extends Model
{
    protected $fillable = [
        'platform',
        'external_resource_id',
        'payload',
        'status',
        'processed_at',
        'error_log'
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime'
    ];
}
