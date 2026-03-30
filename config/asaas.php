<?php

return [
    'api_key' => env('ASAAS_API_KEY'),
    'env' => env('ASAAS_ENV', 'sandbox'), // sandbox ou production
    'base_url' => env('ASAAS_ENV') === 'production'
    ? 'https://www.asaas.com/api/v3'
    : 'https://sandbox.asaas.com/api/v3',
];