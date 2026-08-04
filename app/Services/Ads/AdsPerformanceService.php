<?php

namespace App\Services\Ads;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cruza o gasto de campanha importado (ad_spend_daily) com a receita real
 * atribuída via UTM (orders.utm_source/utm_medium/utm_campaign, capturados
 * na importação de Vendas do Magazord — CLAUDE.md §... pedido do cliente
 * 04/08/2026: "monitorar a saude da operação como um todo").
 *
 * Duas granularidades, deliberadamente separadas pra nunca fabricar
 * atribuição que o dado não sustenta (CLAUDE.md §2.2):
 *  - Por PLATAFORMA/DIA: robusto — só depende de reconhecer o utm_source
 *    (ex.: "google"/"adwords" -> Google Ads, "facebook"/"instagram" -> Meta
 *    Ads). Sempre calculável quando as duas fontes (import de gasto + venda
 *    com UTM) existirem no período.
 *  - Por CAMPANHA: só casa quando o nome da campanha no relatório de ADS
 *    bate, exatamente (case-insensitive), com o utm_campaign gravado na
 *    venda. Campanhas sem correspondência aparecem separadas — nunca
 *    misturadas com as casadas — porque não temos base pra saber se são a
 *    mesma coisa ou não.
 */
class AdsPerformanceService
{
    private const SOURCE_TO_PLATFORM = [
        'google' => 'google_ads', 'adwords' => 'google_ads', 'google ads' => 'google_ads',
        'googleads' => 'google_ads', 'google_ads' => 'google_ads', 'gads' => 'google_ads',
        'facebook' => 'meta_ads', 'fb' => 'meta_ads', 'instagram' => 'meta_ads', 'ig' => 'meta_ads', 'meta' => 'meta_ads',
    ];

    public function schemaReady(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'utm_source')
            && Schema::hasTable('ad_spend_daily');
    }

    private function mapPlatform(?string $source): ?string
    {
        if (!$source) return null;
        return self::SOURCE_TO_PLATFORM[mb_strtolower(trim($source))] ?? null;
    }

    private function resolveDateColumn(): ?string
    {
        $cols = Schema::getColumnListing('orders');
        foreach (['date_created', 'order_date', 'created_at'] as $c) {
            if (in_array($c, $cols, true)) return $c;
        }
        return null;
    }

    public function overview(int $companyId, Carbon $since, Carbon $until): array
    {
        if (!$this->schemaReady()) {
            return $this->empty();
        }

        $dateCol = $this->resolveDateColumn();
        if (!$dateCol) {
            return $this->empty();
        }

        $spendRows = DB::table('ad_spend_daily')
            ->where('company_id', $companyId)
            ->whereBetween('date', [$since->toDateString(), $until->toDateString()])
            ->get(['platform', 'date', 'campaign_name', 'spend', 'impressions', 'clicks', 'conversions']);

        $orderRows = DB::table('orders')
            ->where('company_id', $companyId)
            ->whereBetween($dateCol, [$since->startOfDay(), $until->endOfDay()])
            ->whereIn('status', Order::CONFIRMED_STATUSES)
            ->whereNotNull('utm_source')
            ->get(["{$dateCol} as order_date", 'utm_source', 'utm_medium', 'utm_campaign', 'total_amount']);

        // ---- séries diárias por plataforma ----
        $spendByPlatformDay = [];
        $spendTotalsByPlatform = [];
        foreach ($spendRows as $r) {
            $day = (string) $r->date;
            $spendByPlatformDay[$r->platform][$day] = ($spendByPlatformDay[$r->platform][$day] ?? 0) + (float) $r->spend;
            $t = &$spendTotalsByPlatform[$r->platform];
            $t ??= ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0];
            $t['spend'] += (float) $r->spend;
            $t['impressions'] += (int) ($r->impressions ?? 0);
            $t['clicks'] += (int) ($r->clicks ?? 0);
            $t['conversions'] += (int) ($r->conversions ?? 0);
            unset($t);
        }

        $revenueByPlatformDay = [];
        $revenueTotalsByPlatform = [];
        $revenueBySourceRaw = [];
        $revenueByDevice = [];
        $unmappedSources = [];
        foreach ($orderRows as $o) {
            $platform = $this->mapPlatform($o->utm_source);
            $day = Carbon::parse($o->order_date)->toDateString();
            $amount = (float) $o->total_amount;

            $srcKey = mb_strtolower(trim($o->utm_source));
            $revenueBySourceRaw[$srcKey] = ($revenueBySourceRaw[$srcKey] ?? ['orders' => 0, 'revenue' => 0.0]);
            $revenueBySourceRaw[$srcKey]['orders']++;
            $revenueBySourceRaw[$srcKey]['revenue'] += $amount;

            if ($platform === null) {
                $unmappedSources[$srcKey] = ($unmappedSources[$srcKey] ?? 0) + 1;
                continue;
            }

            $revenueByPlatformDay[$platform][$day] = $revenueByPlatformDay[$platform][$day] ?? ['orders' => 0, 'revenue' => 0.0];
            $revenueByPlatformDay[$platform][$day]['orders']++;
            $revenueByPlatformDay[$platform][$day]['revenue'] += $amount;

            $t = &$revenueTotalsByPlatform[$platform];
            $t ??= ['orders' => 0, 'revenue' => 0.0];
            $t['orders']++;
            $t['revenue'] += $amount;
            unset($t);
        }

        $platforms = array_unique(array_merge(array_keys($spendTotalsByPlatform), array_keys($revenueTotalsByPlatform)));
        $daily = [];
        $byPlatform = [];
        foreach ($platforms as $platform) {
            $spend = $spendTotalsByPlatform[$platform] ?? ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0];
            $revenue = $revenueTotalsByPlatform[$platform] ?? ['orders' => 0, 'revenue' => 0.0];
            $byPlatform[] = [
                'platform' => $platform,
                'spend' => round($spend['spend'], 2),
                'impressions' => $spend['impressions'],
                'clicks' => $spend['clicks'],
                'conversions' => $spend['conversions'],
                'orders' => $revenue['orders'],
                'revenue' => round($revenue['revenue'], 2),
                'roas' => ($spend['spend'] > 0 && $revenue['revenue'] > 0) ? round($revenue['revenue'] / $spend['spend'], 2) : null,
                'cpa' => ($spend['spend'] > 0 && $revenue['orders'] > 0) ? round($spend['spend'] / $revenue['orders'], 2) : null,
                'ctr' => ($spend['impressions'] > 0 && $spend['clicks'] > 0) ? round(($spend['clicks'] / $spend['impressions']) * 100, 2) : null,
            ];

            $days = array_unique(array_merge(
                array_keys($spendByPlatformDay[$platform] ?? []),
                array_keys($revenueByPlatformDay[$platform] ?? [])
            ));
            sort($days);
            foreach ($days as $day) {
                $s = $spendByPlatformDay[$platform][$day] ?? 0.0;
                $r = $revenueByPlatformDay[$platform][$day]['revenue'] ?? 0.0;
                $daily[] = [
                    'platform' => $platform,
                    'date' => $day,
                    'spend' => round($s, 2),
                    'revenue' => round($r, 2),
                    'orders' => $revenueByPlatformDay[$platform][$day]['orders'] ?? 0,
                    'roas' => ($s > 0 && $r > 0) ? round($r / $s, 2) : null,
                ];
            }
        }

        return [
            'ready' => true,
            'since' => $since->toDateString(),
            'until' => $until->toDateString(),
            'by_platform' => $byPlatform,
            'daily' => $daily,
            'campaigns' => $this->campaignMatch($spendRows, $orderRows),
            'revenue_by_source' => collect($revenueBySourceRaw)->map(fn ($v, $k) => [
                'source' => $k, 'orders' => $v['orders'], 'revenue' => round($v['revenue'], 2),
            ])->sortByDesc('revenue')->values()->all(),
            'unmapped_sources' => collect($unmappedSources)->map(fn ($n, $k) => ['source' => $k, 'orders' => $n])->sortByDesc('orders')->values()->all(),
            'has_spend_data' => $spendRows->isNotEmpty(),
            'has_utm_data' => $orderRows->isNotEmpty(),
        ];
    }

    /** Casamento por CAMPANHA — só exato (case-insensitive/trim); nunca infere. */
    private function campaignMatch($spendRows, $orderRows): array
    {
        $spendByCampaign = [];
        foreach ($spendRows as $r) {
            $key = mb_strtolower(trim($r->campaign_name));
            $spendByCampaign[$key] ??= ['platform' => $r->platform, 'name' => $r->campaign_name, 'spend' => 0.0];
            $spendByCampaign[$key]['spend'] += (float) $r->spend;
        }

        $revenueByCampaign = [];
        foreach ($orderRows as $o) {
            if (!$o->utm_campaign) continue;
            $key = mb_strtolower(trim($o->utm_campaign));
            $revenueByCampaign[$key] ??= ['name' => $o->utm_campaign, 'orders' => 0, 'revenue' => 0.0];
            $revenueByCampaign[$key]['orders']++;
            $revenueByCampaign[$key]['revenue'] += (float) $o->total_amount;
        }

        $matched = [];
        $spendOnly = [];
        foreach ($spendByCampaign as $key => $s) {
            $rev = $revenueByCampaign[$key] ?? null;
            if ($rev) {
                $matched[] = [
                    'campaign' => $s['name'], 'platform' => $s['platform'],
                    'spend' => round($s['spend'], 2), 'orders' => $rev['orders'], 'revenue' => round($rev['revenue'], 2),
                    'roas' => $s['spend'] > 0 ? round($rev['revenue'] / $s['spend'], 2) : null,
                ];
            } else {
                $spendOnly[] = ['campaign' => $s['name'], 'platform' => $s['platform'], 'spend' => round($s['spend'], 2)];
            }
        }
        $revenueOnly = [];
        foreach ($revenueByCampaign as $key => $r) {
            if (!isset($spendByCampaign[$key])) {
                $revenueOnly[] = ['campaign' => $r['name'], 'orders' => $r['orders'], 'revenue' => round($r['revenue'], 2)];
            }
        }

        return [
            'matched' => collect($matched)->sortByDesc('spend')->values()->all(),
            'spend_only' => collect($spendOnly)->sortByDesc('spend')->values()->all(),
            'revenue_only' => collect($revenueOnly)->sortByDesc('revenue')->values()->all(),
        ];
    }

    private function empty(): array
    {
        return [
            'ready' => false, 'since' => null, 'until' => null, 'by_platform' => [], 'daily' => [],
            'campaigns' => ['matched' => [], 'spend_only' => [], 'revenue_only' => []],
            'revenue_by_source' => [], 'unmapped_sources' => [], 'has_spend_data' => false, 'has_utm_data' => false,
        ];
    }
}
