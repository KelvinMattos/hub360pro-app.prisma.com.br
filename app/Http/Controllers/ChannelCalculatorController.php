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

    /** Terminações de arredondamento comercial oferecidas na tela -> valor aceito por PricingEngine::roundToCharm(). */
    private const ROUND_ENDINGS = ['00' => 0.00, '50' => 0.50, '90' => 0.90, '99' => 0.99];

    /** Calcula equilíbrio/meta/promo/margem por canal a partir do PricingEngine. */
    public function compute(Request $request)
    {
        $data = $request->validate([
            'custo' => ['required', 'numeric', 'min:0'],
            'preco' => ['nullable', 'numeric', 'min:0'],
            'imposto' => ['required', 'numeric'],
            'mc' => ['required', 'numeric'],
            'markup' => ['required', 'numeric'],
            'roundEnabled' => ['nullable', 'boolean'],
            'roundEnding' => ['nullable', 'in:00,50,90,99'],
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
        $roundEnabled = (bool) ($data['roundEnabled'] ?? false);
        $roundEnding = self::ROUND_ENDINGS[$data['roundEnding'] ?? '90'] ?? 0.90;

        $rows = array_map(function (array $ch) use ($custo, $preco, $imposto, $mc, $markup, $roundEnabled, $roundEnding) {
            $comissao = (float) $ch['comissao'];
            $temFaixa = $ch['temFaixa'] ?? 'none';
            $encargos = $imposto + $mc + $comissao;

            // Sempre o valor EXATO — status e margem nunca usam o preço arredondado,
            // senão "Abaixo eq." e "Prejuízo" mudariam de resposta só por causa da
            // terminação escolhida na tela.
            $equilibrio = $custo > 0 ? $this->pricingEngine->tieredBreakEven($custo, $encargos, $temFaixa) : null;
            $meta = $equilibrio !== null ? round($equilibrio * (1 + $markup / 100), 2) : null;
            // PV Promo já tem arredondamento embutido na própria fórmula validada contra
            // a planilha (CLAUDE.md §9) — a terminação escolhida só substitui o 0,90 padrão.
            $promo = ($preco && $equilibrio !== null)
                ? round($this->pricingEngine->suggestedPromoPrice(
                    $preco, $equilibrio, (float) ($ch['descAtual'] ?? 20), (float) ($ch['descEquil'] ?? 10),
                    $roundEnabled ? $roundEnding : 0.90
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

            // Arredondamento comercial é só para EXIBIÇÃO de Equilíbrio/Meta.
            $equilibrioDisplay = ($roundEnabled && $equilibrio !== null)
                ? $this->pricingEngine->roundToCharm($equilibrio, $roundEnding)
                : $equilibrio;
            $metaDisplay = ($roundEnabled && $meta !== null)
                ? $this->pricingEngine->roundToCharm($meta, $roundEnding)
                : $meta;

            return [
                'id' => $ch['id'],
                'label' => $ch['label'] ?? $ch['id'],
                'comissao' => $comissao,
                'temFaixa' => $temFaixa,
                'descAtual' => $ch['descAtual'] ?? 20,
                'descEquil' => $ch['descEquil'] ?? 10,
                'equilibrio' => $equilibrioDisplay !== null ? round($equilibrioDisplay, 2) : null,
                'meta' => $metaDisplay,
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
