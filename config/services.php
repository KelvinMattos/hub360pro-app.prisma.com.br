<?php

return [

    /*
     |--------------------------------------------------------------------------
     | Third Party Services
     |--------------------------------------------------------------------------
     |
     | This file is for storing the credentials for third party services such
     | as Mailgun, Postmark, AWS and more. This file provides the de facto
     | location for this type of information, allowing packages to have
     | a conventional file to locate the various service credentials.
     |
     */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // --- INTEGRAÇÕES PRISMAHUB (NOVAS) ---

    'mercadolibre' => [
        'app_id' => env('ML_APP_ID'),
        'client_id' => env('ML_APP_ID'), // Alias para o Service
        'client_secret' => env('ML_SECRET_KEY'),
        'redirect' => env('ML_REDIRECT_URI'),
    ],

    // Credenciais do Google Ads (client id/secret/developer token) ficam por
    // empresa na tabela `integrations` (tela Conexões) — igual ao Mercado
    // Livre. Aqui só a versão da API, que muda com o tempo (Google
    // descontinua versões antigas em ~1 ano; ajustar quando a API acusar
    // "unsupported version").
    'google_ads' => [
        'version' => env('GOOGLE_ADS_API_VERSION', 'v24'),
    ],

    'bling' => [
        'client_id' => env('BLING_CLIENT_ID'),
        'client_secret' => env('BLING_CLIENT_SECRET'),
        'redirect' => env('BLING_REDIRECT_URI'),
    ],

];