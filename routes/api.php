<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\Webhooks\WebhookController;

/*
 |--------------------------------------------------------------------------
 | API Routes
 |--------------------------------------------------------------------------
 |
 | Aqui ficam as rotas de integração e externos.
 |
 */

Route::post('/webhook/asaas', [AsaasWebhookController::class , 'handle'])->name('api.webhook.asaas');

// Módulo 3: Hub & Webhooks (Alta Performance)
Route::any('/webhook/{platform}', [WebhookController::class , 'receive'])->name('api.webhook.receive');