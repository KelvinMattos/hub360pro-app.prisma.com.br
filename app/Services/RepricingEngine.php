<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de repricing — DESLIGADO POR PADRÃO e em DRY-RUN por padrão.
 *
 * Travas obrigatórias (todas verificadas por produto, e o motivo do bloqueio
 * fica registrado na auditoria):
 *   1. PISO: consumido do App\Services\PricingEngine — custo + ENCARGOS DO
 *      CANAL (comissão do canal de venda do produto + imposto global) +
 *      margem mínima da MARCA (fallback: margem global). Nunca sugere preço
 *      abaixo do piso.
 *      ⚠️ Correção: a versão anterior calculava `custo × (1 + margem)`,
 *      ignorando os encargos do canal — com comissão de ~22%, esse "piso"
 *      ficava ABAIXO do ponto de equilíbrio real e autorizava venda no
 *      prejuízo (ver PricingEngineTest::test_old_buggy_floor_formula...).
 *   2. FRESCOR: ignora produto sem preço de mercado ou com dado mais antigo
 *      que `max_age_hours`.
 *   3. VARIAÇÃO MÁXIMA: alteração acima de `max_change_pct` não é aplicada
 *      automaticamente — fica marcada como "requer aprovação manual".
 *   4. FONTE: ignora preço vindo de fonte não confiável. O preço PIX/à vista
 *      (lowPrice agregado) NUNCA entra — o coletor já o separa em `pix_price`
 *      e jamais o grava em `market_price`.
 *
 * Toda execução gera um LOTE com auditoria (antes/depois/fonte/horário) e pode
 * ser revertida (`rollback`).
 */
class RepricingEngine
{
    public function __construct(
        private PricingEngine $pricingEngine,
        private ChannelConfigService $channelConfig
    ) {
    }

    public const DEFAULTS = [
        'repricing_enabled' => false,  // trava mestra
        'dry_run' => true,             // por padrão só simula
        'min_margin' => 10.0,          // margem mínima global (%) sobre o custo
        'max_change_pct' => 15.0,      // variação máxima automática (%)
        'max_age_hours' => 24,         // idade máxima do preço de mercado
        'undercut' => 0.10,            // quanto ficar abaixo do mercado (R$)
        'only_losing' => true,         // só mexe em quem está perdendo
    ];

    /** Fontes de preço de mercado aceitas para reprecificar. */
    private const TRUSTED_SOURCES = ['import', 'manual', 'scraper_netshoes', 'api'];

    private function has(string $c): bool
    {
        return Schema::hasColumn('products', $c);
    }

    private function effSql(): string
    {
        $parts = [];
        foreach (['promotional_price', 'sale_price', 'price'] as $c) {
            if ($this->has($c)) {
                $parts[] = "NULLIF($c, 0)";
            }
        }
        return empty($parts) ? '0' : 'COALESCE(' . implode(', ', $parts) . ', 0)';
    }

    /** Margens mínimas por marca (normalizadas em minúsculas). */
    public function brandMargins(int $companyId): array
    {
        if (!Schema::hasTable('brand_margins')) {
            return [];
        }
        try {
            return DB::table('brand_margins')->where('company_id', $companyId)
                ->pluck('min_margin_pct', 'brand')
                ->mapWithKeys(fn ($v, $k) => [mb_strtolower(trim($k)) => (float) $v])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function saveBrandMargin(int $companyId, string $brand, float $pct): void
    {
        if (!Schema::hasTable('brand_margins')) {
            return;
        }
        DB::table('brand_margins')->updateOrInsert(
            ['company_id' => $companyId, 'brand' => trim($brand)],
            ['min_margin_pct' => $pct, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * Mapa `selling_channel` (normalizado) -> comissão % do canal, a partir
     * da config de canais da empresa (a mesma fonte do Cálculo Promo).
     * Também devolve o imposto global — os dois juntos formam os "encargos"
     * exigidos por PricingEngine::floorPrice().
     */
    private function channelCharges(int $companyId): array
    {
        $config = $this->channelConfig->forCompany($companyId);
        $imposto = (float) ($config['imposto'] ?? 0);

        $comissoes = [];
        foreach ($config['channels'] ?? [] as $ch) {
            $key = mb_strtolower(trim((string) ($ch['id'] ?? '')));
            $label = mb_strtolower(trim((string) ($ch['label'] ?? '')));
            $comissao = (float) ($ch['comissao'] ?? 0);
            if ($key !== '') $comissoes[$key] = $comissao;
            if ($label !== '') $comissoes[$label] = $comissao;
        }

        return ['imposto' => $imposto, 'comissoes' => $comissoes];
    }

    /**
     * Encargos totais (%) para o canal de venda de um produto: comissão do
     * canal (casada por `selling_channel`, com fallback para a comissão
     * média cadastrada) + imposto global.
     *
     * O Buy Box hoje só cobre a Netshoes (ver NetshoesBuyBoxScraper) — por
     * isso, sem `selling_channel` reconhecido, cai no fallback "netshoes".
     */
    private function resolveCharges(array $channelData, ?string $sellingChannel): float
    {
        $comissoes = $channelData['comissoes'];
        $key = mb_strtolower(trim((string) $sellingChannel));

        $comissao = $comissoes[$key]
            ?? $comissoes['netshoes']
            ?? (!empty($comissoes) ? array_sum($comissoes) / count($comissoes) : 0.0);

        return $comissao + $channelData['imposto'];
    }

    /**
     * Monta o plano: o que MUDARIA, com o motivo de cada bloqueio.
     * Não altera nada — é a base tanto do dry-run quanto da aplicação.
     */
    public function plan(int $companyId, array $opts = []): array
    {
        $cfg = array_merge(self::DEFAULTS, array_filter($opts, fn ($v) => $v !== null && $v !== ''));

        if (!Schema::hasTable('products') || !$this->has('market_price')) {
            return ['items' => [], 'stats' => $this->emptyStats(), 'config' => $cfg];
        }

        $eff = $this->effSql();
        $margins = $this->brandMargins($companyId);
        $channelData = $this->channelCharges($companyId);

        $sel = ['id', DB::raw("$eff as preco")];
        foreach (['sku', 'brand', 'market_price', 'market_seller', 'market_source', 'selling_channel',
                  'market_checked_at', 'cost_price', 'buybox_winner', 'stock_quantity'] as $c) {
            if ($this->has($c)) {
                $sel[] = $c;
            }
        }
        $sel[] = ($this->has('title') ? 'title' : 'sku') . ' as titulo';

        $q = DB::table('products')->select($sel);
        if ($this->has('company_id')) {
            $q->where('company_id', $companyId);
        }
        if ($this->has('monitored')) {
            $q->where('monitored', true);
        }
        $q->whereNotNull('market_price')->where('market_price', '>', 0);
        if ($cfg['only_losing']) {
            $q->whereRaw("$eff > market_price");
        }

        $cut = now()->subHours((int) $cfg['max_age_hours']);
        $items = [];
        $stats = $this->emptyStats();

        foreach ($q->limit(2000)->get() as $r) {
            $stats['evaluated']++;

            $preco = (float) ($r->preco ?? 0);
            $mkt = (float) $r->market_price;
            $marca = $r->brand ?? null;
            $custo = isset($r->cost_price) ? (float) $r->cost_price : null;

            $margem = $marca && isset($margins[mb_strtolower(trim($marca))])
                ? $margins[mb_strtolower(trim($marca))]
                : (float) $cfg['min_margin'];

            $encargos = $this->resolveCharges($channelData, $r->selling_channel ?? null);
            $piso = ($custo && $custo > 0)
                ? $this->pricingEngine->floorPrice($custo, $encargos, $margem)
                : null;
            $novo = round(max(0.01, $mkt - (float) $cfg['undercut']), 2);
            $variacao = $preco > 0 ? abs(($novo - $preco) / $preco * 100) : 0;

            $bloqueio = null;

            // Trava 4: fonte confiável
            $fonte = $r->market_source ?? null;
            if ($fonte !== null && !in_array($fonte, self::TRUSTED_SOURCES, true)) {
                $bloqueio = "Fonte de preço não confiável ({$fonte}).";
            }
            // Trava 2: frescor do dado
            elseif (!isset($r->market_checked_at) || $r->market_checked_at === null) {
                $bloqueio = 'Sem data de verificação do preço de mercado.';
            } elseif (strtotime((string) $r->market_checked_at) < $cut->timestamp) {
                $idade = round((time() - strtotime((string) $r->market_checked_at)) / 3600);
                $bloqueio = "Preço de mercado desatualizado ({$idade}h > {$cfg['max_age_hours']}h).";
            }
            // Trava 1: piso de custo + encargos do canal + margem
            elseif ($piso === null) {
                $bloqueio = 'Sem custo cadastrado — não dá para garantir a margem.';
            } elseif ($novo < $piso) {
                $bloqueio = sprintf(
                    'Abaixo do piso (R$ %.2f = custo + %.1f%% de encargos do canal + %.1f%% de margem da marca).',
                    $piso, $encargos, $margem
                );
            }
            // Trava 3: variação máxima
            elseif ($variacao > (float) $cfg['max_change_pct']) {
                $bloqueio = sprintf('Variação de %.1f%% acima do limite de %.1f%% — requer aprovação manual.',
                    $variacao, $cfg['max_change_pct']);
            }
            // Nada a fazer
            elseif (abs($novo - $preco) < 0.01) {
                $bloqueio = 'Preço já está no alvo.';
            }

            $item = [
                'id' => $r->id,
                'titulo' => $r->titulo,
                'sku' => $r->sku ?? null,
                'marca' => $marca,
                'preco' => round($preco, 2),
                'market_price' => $mkt,
                'novo' => $novo,
                'variacao' => round($variacao, 1),
                'custo' => $custo,
                'encargos' => $encargos,
                'piso' => $piso,
                'margem' => $margem,
                'fonte' => $fonte,
                'checked_at' => $r->market_checked_at ?? null,
                'bloqueio' => $bloqueio,
                'aplicavel' => $bloqueio === null,
            ];

            if ($bloqueio === null) {
                $stats['changed']++;
            } else {
                $stats['skipped']++;
            }
            $items[] = $item;
        }

        return ['items' => $items, 'stats' => $stats, 'config' => $cfg];
    }

    /**
     * Aplica o plano. Com dry_run=true (padrão) apenas registra o que seria
     * feito. Retorna o id do lote para permitir rollback.
     */
    public function apply(int $companyId, ?int $userId, array $opts = []): array
    {
        $plan = $this->plan($companyId, $opts);
        $cfg = $plan['config'];

        if (!($cfg['repricing_enabled'] ?? false)) {
            return ['ok' => false, 'batch_id' => null, 'stats' => $plan['stats'],
                    'message' => 'O repricing automático está desativado. Ative nas configurações antes de aplicar.'];
        }

        $dry = (bool) ($cfg['dry_run'] ?? true);
        $col = $this->has('promotional_price') ? 'promotional_price'
            : ($this->has('sale_price') ? 'sale_price' : null);

        if (!$dry && !$col) {
            return ['ok' => false, 'batch_id' => null, 'stats' => $plan['stats'],
                    'message' => 'Não há coluna de preço para gravar.'];
        }

        $batchId = null;
        DB::beginTransaction();
        try {
            $batchId = DB::table('repricing_batches')->insertGetId([
                'company_id' => $companyId,
                'user_id' => $userId,
                'dry_run' => $dry,
                'evaluated' => $plan['stats']['evaluated'],
                'changed' => $dry ? 0 : $plan['stats']['changed'],
                'skipped' => $plan['stats']['skipped'],
                'settings' => json_encode($cfg),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $logs = [];
            foreach ($plan['items'] as $it) {
                $apply = $it['aplicavel'] && !$dry;
                if ($apply) {
                    DB::table('products')->where('id', $it['id'])->update([$col => $it['novo']]);
                }
                $logs[] = [
                    'batch_id' => $batchId,
                    'company_id' => $companyId,
                    'product_id' => $it['id'],
                    'price_before' => $it['preco'],
                    'price_after' => $it['aplicavel'] ? $it['novo'] : null,
                    'market_price' => $it['market_price'],
                    'market_source' => $it['fonte'],
                    'market_checked_at' => $it['checked_at'],
                    'action' => $apply ? 'applied' : ($it['aplicavel'] ? 'simulated' : 'skipped'),
                    'reason' => $it['bloqueio'],
                    'applied' => $apply,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($logs, 500) as $chunk) {
                DB::table('repricing_logs')->insert($chunk);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[repricing] falha ao aplicar', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'batch_id' => null, 'stats' => $plan['stats'],
                    'message' => 'Falha ao aplicar (nada foi gravado): ' . $e->getMessage()];
        }

        $s = $plan['stats'];
        return [
            'ok' => true,
            'batch_id' => $batchId,
            'stats' => $s,
            'message' => $dry
                ? "Simulação (dry-run): {$s['changed']} produtos SERIAM alterados, {$s['skipped']} barrados pelas travas. Nada foi gravado."
                : "Repricing aplicado: {$s['changed']} preços alterados, {$s['skipped']} barrados pelas travas.",
        ];
    }

    /** Desfaz um lote: restaura o preço anterior de cada item aplicado. */
    public function rollback(int $companyId, int $batchId): array
    {
        $batch = DB::table('repricing_batches')->where('id', $batchId)
            ->where('company_id', $companyId)->first();

        if (!$batch) {
            return ['ok' => false, 'message' => 'Lote não encontrado.'];
        }
        if ($batch->dry_run) {
            return ['ok' => false, 'message' => 'Este lote foi apenas uma simulação — não há o que desfazer.'];
        }
        if ($batch->rolled_back) {
            return ['ok' => false, 'message' => 'Este lote já foi desfeito.'];
        }

        $col = $this->has('promotional_price') ? 'promotional_price'
            : ($this->has('sale_price') ? 'sale_price' : null);
        if (!$col) {
            return ['ok' => false, 'message' => 'Não há coluna de preço para restaurar.'];
        }

        $n = 0;
        DB::beginTransaction();
        try {
            $logs = DB::table('repricing_logs')->where('batch_id', $batchId)->where('applied', true)->get();
            foreach ($logs as $l) {
                DB::table('products')->where('id', $l->product_id)->update([$col => $l->price_before]);
                $n++;
            }
            DB::table('repricing_logs')->where('batch_id', $batchId)->where('applied', true)
                ->update(['action' => 'rolled_back', 'updated_at' => now()]);
            DB::table('repricing_batches')->where('id', $batchId)
                ->update(['rolled_back' => true, 'rolled_back_at' => now(), 'updated_at' => now()]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ['ok' => false, 'message' => 'Falha ao desfazer: ' . $e->getMessage()];
        }

        return ['ok' => true, 'message' => "Lote desfeito: {$n} preços restaurados."];
    }

    /** Últimos lotes, para a tela de auditoria. */
    public function batches(int $companyId, int $limit = 20): array
    {
        if (!Schema::hasTable('repricing_batches')) {
            return [];
        }
        return DB::table('repricing_batches')->where('company_id', $companyId)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'dry_run' => (bool) $b->dry_run,
                'evaluated' => (int) $b->evaluated,
                'changed' => (int) $b->changed,
                'skipped' => (int) $b->skipped,
                'rolled_back' => (bool) $b->rolled_back,
                'created_at' => $b->created_at,
            ])->all();
    }

    private function emptyStats(): array
    {
        return ['evaluated' => 0, 'changed' => 0, 'skipped' => 0];
    }
}
