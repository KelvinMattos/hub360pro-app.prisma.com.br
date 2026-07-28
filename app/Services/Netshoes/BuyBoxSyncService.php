<?php

namespace App\Services\Netshoes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Orquestra a coleta de Buy Box.
 *
 * ⚠️ DESLIGADO POR PADRÃO: a Netshoes bloqueia requisições server-side (403
 * Akamai). Enquanto não houver canal autorizado (API de seller ou relatório do
 * Seller Center), a fonte de preço de mercado é a importação de planilha.
 *
 * Garantias desta camada:
 *  - NUNCA grava preço/market_source quando a resposta não for 200 + preço
 *    extraído. Falha vira `market_error` explícito, não silêncio.
 *  - Circuit breaker: se o site responder "bloqueado" seguidamente, aborta a
 *    rodada em vez de insistir centenas de vezes.
 */
class BuyBoxSyncService
{
    /** Após tantos bloqueios seguidos, desiste da rodada. */
    private const BLOCK_STREAK_ABORT = 5;

    public const DEFAULT_CONFIG = [
        'scraper_enabled' => false,     // coleta direta do site (bloqueada hoje)
        'netshoes_seller_name' => '',   // nome da NOSSA loja (define o buybox_winner)
        'search_url' => NetshoesBuyBoxScraper::DEFAULTS['search_url'],
        'timeout' => 20,
        'delay_ms' => 1500,
        'batch_limit' => 200,
        'recheck_hours' => 12,
    ];

    public function __construct(private NetshoesBuyBoxScraper $scraper)
    {
    }

    /* ----------------------------- config ----------------------------- */

    public function config(?int $companyId): array
    {
        if (!$companyId || !Schema::hasTable('monitoring_settings')) {
            return self::DEFAULT_CONFIG;
        }
        try {
            $row = DB::table('monitoring_settings')->where('company_id', $companyId)->first();
            $cfg = $row && $row->config ? json_decode($row->config, true) : [];
            return array_merge(self::DEFAULT_CONFIG, is_array($cfg) ? $cfg : []);
        } catch (\Throwable $e) {
            return self::DEFAULT_CONFIG;
        }
    }

    public function saveConfig(?int $companyId, array $config): array
    {
        $merged = array_merge($this->config($companyId), $config);
        if ($companyId && Schema::hasTable('monitoring_settings')) {
            DB::table('monitoring_settings')->updateOrInsert(
                ['company_id' => $companyId],
                ['config' => json_encode($merged), 'updated_at' => now(), 'created_at' => now()]
            );
        }
        return $merged;
    }

    /* ------------------------------ coleta ------------------------------ */

    /** Quantos produtos têm SKU Netshoes (0 = nada a coletar). */
    public function eligibleCount(int $companyId): int
    {
        if (!Schema::hasColumn('products', 'netshoes_sku')) {
            return 0;
        }
        $q = DB::table('products')->whereNotNull('netshoes_sku')->where('netshoes_sku', '!=', '');
        if (Schema::hasColumn('products', 'company_id')) {
            $q->where('company_id', $companyId);
        }
        return (int) $q->count();
    }

    public function pending(int $companyId, int $limit, int $recheckHours, bool $onlyStale = true)
    {
        if (!Schema::hasColumn('products', 'netshoes_sku')) {
            return collect();
        }
        $q = DB::table('products')->select('id', 'sku', 'netshoes_sku')
            ->whereNotNull('netshoes_sku')->where('netshoes_sku', '!=', '');

        if (Schema::hasColumn('products', 'company_id')) {
            $q->where('company_id', $companyId);
        }
        if (Schema::hasColumn('products', 'monitored')) {
            $q->where('monitored', true);
        }
        if ($onlyStale && Schema::hasColumn('products', 'market_checked_at')) {
            $cut = now()->subHours(max(0, $recheckHours));
            $q->where(function ($w) use ($cut) {
                $w->whereNull('market_checked_at')->orWhere('market_checked_at', '<', $cut);
            });
            $q->orderByRaw('market_checked_at IS NOT NULL, market_checked_at ASC');
        }

        return $q->limit($limit)->get();
    }

    /**
     * Executa uma rodada.
     *
     * @return array{total:int,ok:int,fail:int,blocked:int,winning:int,losing:int,aborted:bool,reason:?string}
     */
    public function run(int $companyId, array $opts = [], ?callable $onTick = null): array
    {
        $cfg = array_merge($this->config($companyId), array_filter($opts, fn ($v) => $v !== null && $v !== ''));
        $stats = ['total' => 0, 'ok' => 0, 'fail' => 0, 'blocked' => 0,
                  'winning' => 0, 'losing' => 0, 'aborted' => false, 'reason' => null];

        // Trava de segurança: coleta direta desligada por padrão.
        if (!($cfg['scraper_enabled'] ?? false) && !($opts['allow_disabled'] ?? false)) {
            $stats['aborted'] = true;
            $stats['reason'] = 'A coleta direta do site está desativada (a Netshoes bloqueia requisições de servidor). '
                . 'Use a importação de preços de mercado.';
            return $stats;
        }

        if ($this->eligibleCount($companyId) === 0) {
            $stats['aborted'] = true;
            $stats['reason'] = 'Nenhum produto tem SKU Netshoes preenchido. '
                . 'Rode antes a importação "Produtos Netshoes" (export Portal).';
            return $stats;
        }

        $ourSeller = NetshoesBuyBoxScraper::normalizeSeller($cfg['netshoes_seller_name'] ?? '');
        $items = $this->pending(
            $companyId,
            (int) ($cfg['batch_limit'] ?? 200),
            (int) ($cfg['recheck_hours'] ?? 12),
            !($opts['force'] ?? false)
        );

        $stats['total'] = $items->count();
        $delay = max(0, (int) ($cfg['delay_ms'] ?? 1500)) * 1000;
        $first = true;
        $blockStreak = 0;

        foreach ($items as $item) {
            if (!$first && $delay > 0) {
                usleep($delay);
            }
            $first = false;

            $r = $this->scraper->fetch($item->netshoes_sku, [
                'search_url' => $cfg['search_url'] ?? null,
                'timeout' => $cfg['timeout'] ?? null,
            ]);

            if (($r['status'] ?? null) === 'blocked') {
                $blockStreak++;
                $stats['blocked']++;
            } else {
                $blockStreak = 0;
            }

            $this->persist($companyId, $item->id, $r, $ourSeller, $stats);

            if ($onTick) {
                $onTick($stats);
            }

            if ($blockStreak >= self::BLOCK_STREAK_ABORT) {
                $stats['aborted'] = true;
                $stats['reason'] = "Interrompido: o site bloqueou {$blockStreak} requisições seguidas (HTTP 403). "
                    . 'Não insistimos — use um canal autorizado (relatório/planilha do Seller Center).';
                Log::warning('[netshoes-scraper] rodada abortada por bloqueio', ['company' => $companyId]);
                break;
            }
        }

        return $stats;
    }

    /** Persiste. Só grava preço quando a coleta foi realmente bem-sucedida. */
    private function persist(int $companyId, int $productId, array $r, string $ourSeller, array &$stats): void
    {
        $cols = Schema::getColumnListing('products');
        $has = fn ($c) => in_array($c, $cols, true);
        $payload = [];

        if (empty($r['ok'])) {
            $stats['fail']++;
            // NUNCA toca em market_price/market_source aqui — só registra o erro.
            if ($has('market_error')) {
                $status = $r['status'] ?? 'error';
                $http = $r['http'] ?? '—';
                $payload['market_error'] = substr("[{$status}] HTTP {$http}: " . (string) ($r['error'] ?? ''), 0, 290);
            }
            if ($has('market_checked_at')) {
                $payload['market_checked_at'] = now();
            }
            if ($payload) {
                DB::table('products')->where('id', $productId)->update($payload);
            }
            return;
        }

        $stats['ok']++;

        $winner = null;
        if ($ourSeller !== '' && !empty($r['seller'])) {
            $winner = NetshoesBuyBoxScraper::normalizeSeller($r['seller']) === $ourSeller;
            $winner ? $stats['winning']++ : $stats['losing']++;
        }

        // Atenção: $r['price'] é o preço do ANÚNCIO (highPrice). O lowPrice
        // (PIX) nunca entra como preço de mercado.
        if ($has('market_price')) $payload['market_price'] = $r['price'];
        if ($has('market_seller')) $payload['market_seller'] = $r['seller'];
        if ($has('market_url')) $payload['market_url'] = substr((string) $r['url'], 0, 590) ?: null;
        if ($has('market_offers_count')) $payload['market_offers_count'] = $r['offers'];
        if ($has('buybox_winner')) $payload['buybox_winner'] = $winner;
        if ($has('market_source')) $payload['market_source'] = 'scraper_netshoes';
        if ($has('market_checked_at')) $payload['market_checked_at'] = now();
        if ($has('market_error')) $payload['market_error'] = null;

        if ($payload) {
            DB::table('products')->where('id', $productId)->update($payload);
        }

        $this->snapshot($companyId, $productId, $r, $winner);
    }

    private function snapshot(int $companyId, int $productId, array $r, ?bool $winner): void
    {
        if (!Schema::hasTable('market_snapshots')) {
            return;
        }
        try {
            $our = DB::table('products')->where('id', $productId)
                ->selectRaw('COALESCE(NULLIF(promotional_price,0), NULLIF(sale_price,0), NULLIF(price,0), 0) as p')
                ->value('p');

            DB::table('market_snapshots')->insert([
                'company_id' => $companyId,
                'product_id' => $productId,
                'our_price' => $our,
                'market_price' => $r['price'],
                'market_seller' => $r['seller'],
                'buybox_winner' => $winner,
                'offers_count' => $r['offers'],
                'source' => 'scraper_netshoes',
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // histórico é best-effort
        }
    }
}
