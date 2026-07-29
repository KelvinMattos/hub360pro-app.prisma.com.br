<?php

namespace App\Http\Controllers;

use App\Services\ChannelConfigService;
use App\Services\PricingEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Calculadora de Retorno por Canal.
 *
 * Substitui a antiga calculadora exclusiva do Mercado Livre por uma calculadora
 * geral: dado o custo (e opcionalmente um preço), mostra ponto de equilíbrio,
 * preço-meta de lucro e a margem líquida real em TODOS os canais de venda,
 * respeitando comissão e taxas fixas por faixa (ML/Shopee) de cada um.
 *
 * O cálculo é 100% via PricingEngine (fonte única de verdade) — a tela Vue
 * não reimplementa nenhuma fórmula, apenas chama `compute()` (com debounce)
 * a cada alteração de custo/preço/canal.
 */
class ChannelCalculatorController extends Controller
{
    public function __construct(
        private ChannelConfigService $config,
        private PricingEngine $pricingEngine
    ) {
    }

    public function index()
    {
        return Inertia::render('Calculator/Channels', [
            'defaults' => $this->config->forCompany(Auth::user()?->company_id),
        ]);
    }

    /** Calcula equilíbrio/meta/promo/margem por canal a partir do PricingEngine. */
    public function compute(Request $request)
    {
        $data = $request->validate([
            'custo' => ['required', 'numeric', 'min:0'],
            'preco' => ['nullable', 'numeric', 'min:0'],
            'imposto' => ['required', 'numeric'],
            'mc' => ['required', 'numeric'],
            'markup' => ['required', 'numeric'],
            'channels' => ['required', 'array'],
            'channels.*.id' => ['required', 'string'],
            'channels.*.label' => ['nullable', 'string'],
            'channels.*.comissao' => ['required', 'numeric'],
            'channels.*.temFaixa' => ['nullable', 'string'],
            'channels.*.descAtual' => ['nullable', 'numeric'],
            'channels.*.descEquil' => ['nullable', 'numeric'],
        ]);

        $custo = (float) $data['custo'];
        $preco = isset($data['preco']) && $data['preco'] !== null ? (float) $data['preco'] : null;
        $imposto = (float) $data['imposto'];
        $mc = (float) $data['mc'];
        $markup = (float) $data['markup'];

        $rows = array_map(function (array $ch) use ($custo, $preco, $imposto, $mc, $markup) {
            $comissao = (float) $ch['comissao'];
            $temFaixa = $ch['temFaixa'] ?? 'none';
            $encargos = $imposto + $mc + $comissao;

            $equilibrio = $custo > 0 ? $this->pricingEngine->tieredBreakEven($custo, $encargos, $temFaixa) : null;
            $meta = $equilibrio !== null ? round($equilibrio * (1 + $markup / 100), 2) : null;
            $promo = ($preco && $equilibrio !== null)
                ? round($this->pricingEngine->suggestedPromoPrice(
                    $preco, $equilibrio, (float) ($ch['descAtual'] ?? 20), (float) ($ch['descEquil'] ?? 10)
                ), 2)
                : null;
            $margem = $preco ? round($this->pricingEngine->unitContribution($preco, $custo, $encargos), 2) : null;
            $margemPct = ($margem !== null && $preco) ? round($margem / $preco * 100, 1) : null;

            $status = '—';
            $statusClass = 'pill-idle';
            if ($preco && $margem !== null) {
                if ($margem < 0) {
                    $status = 'Prejuízo';
                    $statusClass = 'pill-red';
                } elseif ($equilibrio !== null && $preco < $equilibrio) {
                    $status = 'Abaixo eq.';
                    $statusClass = 'pill-amber';
                } else {
                    $status = 'Lucro';
                    $statusClass = 'pill-green';
                }
            }

            return [
                'id' => $ch['id'],
                'label' => $ch['label'] ?? $ch['id'],
                'comissao' => $comissao,
                'temFaixa' => $temFaixa,
                'descAtual' => $ch['descAtual'] ?? 20,
                'descEquil' => $ch['descEquil'] ?? 10,
                'equilibrio' => $equilibrio !== null ? round($equilibrio, 2) : null,
                'meta' => $meta,
                'promo' => $promo,
                'margem' => $margem,
                'margemPct' => $margemPct,
                'status' => $status,
                'statusClass' => $statusClass,
            ];
        }, $data['channels']);

        return response()->json(['rows' => $rows]);
    }
}
