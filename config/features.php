<?php

/**
 * Feature flags reversíveis — desligar aqui NÃO apaga dados nem tabelas.
 * Basta trocar para true (ou setar a env var) para reativar a rota e o
 * item de menu correspondente.
 */
return [
    'orders' => (bool) env('FEATURE_ORDERS', false),
    'expedition' => (bool) env('FEATURE_EXPEDITION', false),
    'marketplaces_questions' => (bool) env('FEATURE_MARKETPLACES_QUESTIONS', false),
    'marketplaces_auto_reply' => (bool) env('FEATURE_MARKETPLACES_AUTO_REPLY', false),
    'marketplaces_listings_bulk' => (bool) env('FEATURE_MARKETPLACES_LISTINGS_BULK', false),
];
