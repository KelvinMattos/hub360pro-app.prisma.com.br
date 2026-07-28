<?php

namespace App\Services\Netshoes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orquestra a coleta de Buy Box: lê a config da empresa, percorre os produtos
 * com netshoes_sku, chama o scraper com pausa entre requisições e persiste o
 * resultado (produto + snapshot histórico).
 *
 * Determina `buybox_winner` comparando a loja vencedora com o nome da NOSSA
 * loja na Netshoes (configurável).
 */
class BuyBoxSyncService
{
    public const DEFAULT_CONFIG = [
        'netshoes_seller_name' => '',   // nome da nossa loja na Netshoes (ex.: "Sportime")
        'search_url' => NetshoesBuyBoxScraper::DEFAULTS['search_url'],
        'timeout' => 20,
        'delay_ms' => 1500,
        'batch_limit' => 200,           // produtos por rodada
        'recheck_hours' => 12,          // não recoletar antes disso
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

    /** Produtos elegíveis (com SKU Netshoes), priorizando os mais desatualizados. */
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
     * Executa uma rodada de coleta.
     *
     * @param callable|null $onTick  chamado a cada produto (para barra de progresso)
     */
    public function run(int $companyId, array $opts = [], ?callable $onTick = null): array
    {
        $cfg = array_merge($this->config($companyId), array_filter($opts, fn ($v) => $v !== null && $v !== ''));
        $ourSeller = NetshoesBuyBoxScraper::normalizeSeller($cfg['netshoes_seller_name'] ?? '');

        $items = $this->pending(
            $companyId,
            (int) ($cfg['batch_limit'] ?? 200),
            (int) ($cfg['recheck_hours'] ?? 12),
            !($opts['force'] ?? false)
        );

        $stats = ['total' => $items->count(), 'ok' => 0, 'fail' => 0, 'winning' => 0, 'losing' => 0];
        $delay = max(0, (int) ($cfg['delay_ms'] ?? 1500)) * 1000;
        $first = true;

        foreach ($items as $item) {
            if (!$first && $delay > 0) {
                usleep($delay); // coleta educada: intervalo entre requisições
            }
            $first = false;

            $r = $this->scraper->fetch($item->netshoes_sku, [
                'search_url' => $cfg['search_url'] ?? null,
                'timeout' => $cfg['timeout'] ?? null,
            ]);

            $this->persist($companyId, $item->id, $r, $ourSeller, $stats);

            if ($onTick) {
                $onTick($stats);
            }
        }

        return $stats;
    }

    /** Grava o resultado no produto + snapshot histórico. */
    private function persist(int $companyId, int $productId, array $r, string $ourSeller, array &$stats): void
    {
        $cols = Schema::getColumnListing('products');
        $has = fn ($c) => in_array($c, $cols, true);
        $payload = [];

        if (!$r['ok']) {
            $stats['fail']++;
            if ($has('market_error')) {
                $payload['market_error'] = substr((string) $r['error'], 0, 290);
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

        // Ganhamos a Buy Box? (só dá pra afirmar se a loja foi identificada e
        // temos o nome da nossa loja configurado)
        $winner = null;
        if ($ourSeller !== '' && !empty($r['seller'])) {
            $winner = NetshoesBuyBoxScraper::normalizeSeller($r['seller']) === $ourSeller;
            $winner ? $stats['winning']++ : $stats['losing']++;
        }

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
            // histórico é best-effort — nunca derruba a coleta
        }
    }
}
