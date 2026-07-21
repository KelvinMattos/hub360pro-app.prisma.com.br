<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

/**
 * Cálculo Promo direto do banco (sem importar planilhas).
 *
 * Reproduz o motor validado da planilha CALCULO PROMO, mas lendo os dados já
 * persistidos (custo, preço por canal, promoção, estoque, data de lançamento)
 * e a configuração de canais da empresa. Calcula, por canal:
 *   PV atual, ponto de equilíbrio, PV meta de lucro, PV promo sugerido,
 *   resultado atual/promo, % de desconto e tempo de estoque.
 */
class PromoCalculatorService
{
    private const ML_TIER = [
        ['max' => 12.5, 'mode' => 'half'], ['max' => 29, 'fee' => 6.25],
        ['max' => 50, 'fee' => 6.5], ['max' => 79, 'fee' => 6.75], ['max' => INF, 'fee' => 0],
    ];
    private const SHOPEE_TIER = [
        ['max' => 79.99, 'fee' => 4], ['max' => 99.99, 'fee' => 16],
        ['max' => 199.99, 'fee' => 20], ['max' => INF, 'fee' => 26],
    ];

    public function __construct(private ChannelConfigService $config)
    {
    }

    public function config(?int $companyId): array
    {
        return $this->config->forCompany($companyId);
    }

    /** Calcula todas as linhas do canal escolhido. */
    public function compute(int $companyId, array $cfg, string $channelKey): array
    {
        $imposto = (float) $cfg['imposto'];
        $mc = (float) $cfg['mc'];
        $rounding = (float) ($cfg['rounding'] ?? 0.90);
        $channels = collect($cfg['channels'])->filter(fn ($c) => ($c['active'] ?? true))->values();
        $ch = $channels->firstWhere('id', $channelKey) ?? $channels->firstWhere('id', 'site') ?? $channels->first();

        $comissao = (float) ($ch['comissao'] ?? 0);
        $temFaixa = $ch['temFaixa'] ?? 'none';
        $markup = (float) ($ch['markup'] ?? 23.433);
        $descAtual = (float) ($ch['descAtual'] ?? 20);
        $descEquil = (float) ($ch['descEquil'] ?? 10);
        $encargos = $imposto + $mc + $comissao;

        $col = $ch['col'] ?? null;
        if (!$col && ($ch['id'] ?? '') === 'centauro') $col = 'Centauro';

        $hasChannelPrices = Schema::hasColumn('products', 'channel_prices');
        $hasPromo = Schema::hasColumn('products', 'promotional_price');
        $hasLaunch = Schema::hasColumn('products', 'launched_at');

        $select = ['sku', 'title', 'stock_quantity', 'cost_price', 'sale_price'];
        if (Schema::hasColumn('products', 'brand')) $select[] = 'brand';
        if ($hasChannelPrices) $select[] = 'channel_prices';
        if ($hasPromo) $select[] = 'promotional_price';
        if ($hasLaunch) $select[] = 'launched_at';

        $rows = DB::table('products')->where('company_id', $companyId)->select($select)->get();
        $now = Carbon::now();
        $out = [];

        foreach ($rows as $p) {
            $sku = (string) ($p->sku ?? '');
            if ($sku === '') continue;
            $custo = (float) ($p->cost_price ?? 0);
            $saleBase = (float) ($p->sale_price ?? 0);

            $pvAtual = $saleBase;
            if ($hasChannelPrices && $col) {
                $cp = json_decode($p->channel_prices ?? '', true);
                if (is_array($cp) && isset($cp[$col]) && (float) $cp[$col] > 0) {
                    $pvAtual = (float) $cp[$col];
                }
            }

            $pontoEq = $custo > 0 ? $this->breakEven($custo, $imposto, $mc, $comissao, $temFaixa) : null;
            $meta = $pontoEq !== null ? $pontoEq * (1 + $markup / 100) : null;
            $promo = ($pvAtual > 0 && $pontoEq !== null) ? $this->promoSugerido($pvAtual, $pontoEq, $descAtual, $descEquil, $rounding) : null;
            $resAtual = $pvAtual > 0 ? $this->margem($pvAtual, $custo, $encargos) : null;
            $resPromo = $promo !== null ? $this->margem($promo, $custo, $encargos) : null;
            $percDesc = ($promo !== null && $pvAtual > 0) ? ($promo / $pvAtual - 1) : null;
            $promoMenor = ($promo !== null && $pvAtual > 0) ? ($promo < $pvAtual ? 'SIM' : 'NÃO') : null;

            $out[] = [
                'sku' => $sku,
                'produto' => $p->title ?? '',
                'marca' => $p->brand ?? '',
                'estoque' => (int) ($p->stock_quantity ?? 0),
                'custo' => round($custo, 2),
                'tempo_estoque' => $this->tempoEstoque($sku, $hasLaunch ? ($p->launched_at ?? null) : null, $now),
                'pv_atual' => round($pvAtual, 2),
                'ponto_equilibrio' => $pontoEq !== null ? round($pontoEq, 2) : null,
                'meta_lucro' => $meta !== null ? round($meta, 2) : null,
                'promo_sugerido' => $promo !== null ? round($promo, 2) : null,
                'resultado_atual' => $resAtual !== null ? round($resAtual, 2) : null,
                'resultado_promo' => $resPromo !== null ? round($resPromo, 2) : null,
                'perc_desc' => $percDesc !== null ? round($percDesc, 4) : null,
                'promo_menor' => $promoMenor,
            ];
        }

        return [
            'channel' => ['key' => $ch['id'] ?? 'site', 'label' => $ch['label'] ?? 'Site', 'comissao' => $comissao, 'encargos_pct' => round($encargos, 2)],
            'channels' => $channels->map(fn ($c) => ['key' => $c['id'], 'label' => $c['label']])->all(),
            'globals' => ['imposto' => $imposto, 'mc' => $mc, 'has_channel_prices' => $hasChannelPrices],
            'rows' => $out,
        ];
    }

    private function breakEven(float $cost, float $imposto, float $mc, float $comissao, string $temFaixa): float
    {
        $aliq = 1 - ($imposto + $mc + $comissao) / 100;
        if ($aliq <= 0) return 0;
        $base = $cost / $aliq;
        if ($temFaixa === 'ml') {
            if ($base < 12.5) return $base + $base / 2;
            foreach (self::ML_TIER as $t) {
                if (isset($t['fee']) && $base < $t['max']) return ($cost + $t['fee']) / $aliq;
            }
            return $base;
        }
        if ($temFaixa === 'shopee') {
            foreach (self::SHOPEE_TIER as $t) {
                if ($base <= $t['max']) return ($cost + $t['fee']) / $aliq;
            }
            return $base;
        }
        return $base;
    }

    private function promoSugerido(float $pv, float $eq, float $descAtual, float $descEquil, float $rounding): float
    {
        $p1 = floor($pv * (1 - $descAtual / 100) - $rounding) + $rounding;
        $p2 = $eq * (1 - $descEquil / 100);
        return max($p1, $p2);
    }

    private function margem(float $pv, float $custo, float $encargos): float
    {
        return $pv - $pv * ($encargos / 100) - $custo;
    }

    private function tempoEstoque(string $sku, $launchedAt, Carbon $now): string
    {
        if ($launchedAt) {
            try {
                return $this->bucket((int) abs(Carbon::parse($launchedAt)->diffInMonths($now)));
            } catch (\Throwable $e) {
                // cai na heurística
            }
        }
        $iDash = strpos($sku, '-');
        $iU = strpos($sku, '_');
        $cut = $iDash !== false ? $iDash : ($iU !== false ? $iU : strlen($sku));
        $prefix = trim(substr($sku, 0, $cut));
        if ($prefix === '' || !ctype_digit($prefix)) return '';
        $c = (int) $prefix;
        return match (true) {
            $c > 16500 => 'Menos de 6 meses',
            $c >= 16000 => 'Mais de 6 meses',
            $c >= 15500 => '1 ano',
            $c >= 15000 => '1 ano e meio',
            $c >= 14500 => '2 anos',
            default => '+2 anos',
        };
    }

    private function bucket(int $months): string
    {
        return match (true) {
            $months < 6 => 'Menos de 6 meses',
            $months < 12 => 'Mais de 6 meses',
            $months < 18 => '1 ano',
            $months < 24 => '1 ano e meio',
            $months < 30 => '2 anos',
            default => '+2 anos',
        };
    }
}
