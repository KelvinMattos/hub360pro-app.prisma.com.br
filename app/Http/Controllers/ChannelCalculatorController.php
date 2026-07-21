<?php

namespace App\Http\Controllers;

use App\Services\ChannelConfigService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Calculadora de Retorno por Canal.
 *
 * Substitui a antiga calculadora exclusiva do Mercado Livre por uma calculadora
 * geral: dado o custo (e opcionalmente um preço), mostra ponto de equilíbrio,
 * preço-meta de lucro e a margem líquida real em TODOS os canais de venda,
 * respeitando comissão e taxas fixas por faixa (ML/Shopee) de cada um.
 */
class ChannelCalculatorController extends Controller
{
    public function __construct(private ChannelConfigService $config)
    {
    }

    public function index()
    {
        return Inertia::render('Calculator/Channels', [
            'defaults' => $this->config->forCompany(Auth::user()?->company_id),
        ]);
    }
}
