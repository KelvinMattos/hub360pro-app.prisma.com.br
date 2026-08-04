<?php

namespace App\Services\Imports;

use App\Http\Controllers\Ads\AdsImportController;
use App\Http\Controllers\Magazord\MagazordImportController;
use App\Http\Controllers\Netshoes\NetshoesImportController;
use App\Http\Controllers\Sales\OrderChannelImportController;
use App\Support\SalesChannels;
use Illuminate\Support\Str;

/**
 * Detecta automaticamente para qual tela de importação um arquivo pertence,
 * a partir do cabeçalho (CSV/XLSX) ou dos nomes das abas (planilha
 * multi-canal do Diário de Vendas).
 *
 * Pedido do cliente (05/08/2026): "não precisando eu ficar escolhendo qual
 * é" — uma caixa única de upload que reconhece o arquivo e direciona pro
 * campo certo. Como já existem ~20 tipos de importação espalhados em 6
 * controllers com nomes de coluna bem diferentes entre si, dá pra pontuar
 * por sobreposição de coluna em vez de pedir pro usuário escolher a tela.
 *
 * IMPORTANTE: as colunas de cada tipo aqui SEMPRE vêm direto da TYPES const
 * do respectivo controller — nunca uma cópia paralela. O PR #55 desta mesma
 * sprint quebrou a tela inteira porque um tipo novo foi adicionado num lugar
 * (TYPES) e esquecido em outro (rota); duplicar a lista de colunas aqui
 * criaria exatamente esse mesmo tipo de risco.
 */
class ImportDetectionService
{
    /** Score mínimo pra considerar uma detecção confiável o bastante pra pular a escolha manual. */
    private const MIN_CONFIDENT_SCORE = 0.55;

    /** Diferença mínima entre o 1º e o 2º colocado pra não tratar como ambíguo. */
    private const MIN_AMBIGUOUS_GAP = 0.12;

    /** Score mínimo pra sequer aparecer como candidato (abaixo disso é "não reconhecido"). */
    private const MIN_CANDIDATE_SCORE = 0.22;

    /** Catálogo de todo tipo de importação do sistema, com metadados de rota. */
    public function catalog(): array
    {
        $entries = [];

        foreach (MagazordImportController::TYPES as $type => $cfg) {
            $entries[] = [
                'source' => 'magazord', 'type' => $type,
                'title' => $cfg['title'], 'icon' => $cfg['icon'], 'description' => $cfg['description'],
                'columns' => $cfg['columns'],
                'show_route' => 'magazord.show', 'import_route' => 'magazord.import',
                'route_params' => ['type' => $type],
                'accept' => $type === 'inventario' ? '.xlsx' : '.csv,.txt',
                'needs_create_missing' => (bool) ($cfg['can_create'] ?? false),
                'needs_account' => false,
            ];
        }

        foreach (NetshoesImportController::TYPES as $type => $cfg) {
            $entries[] = [
                'source' => 'netshoes', 'type' => $type,
                'title' => $cfg['title'], 'icon' => $cfg['icon'], 'description' => $cfg['description'],
                'columns' => $cfg['columns'],
                'show_route' => 'netshoes.show', 'import_route' => 'netshoes.import',
                'route_params' => ['type' => $type],
                'accept' => '.xlsx',
                'needs_create_missing' => false,
                'needs_account' => false,
            ];
        }

        foreach (OrderChannelImportController::TYPES as $type => $cfg) {
            $entries[] = [
                'source' => 'order_channel', 'type' => $type,
                'title' => $cfg['title'], 'icon' => $cfg['icon'], 'description' => $cfg['description'],
                'columns' => $cfg['columns'],
                'show_route' => 'order-channel.show', 'import_route' => 'order-channel.import',
                'route_params' => ['type' => $type],
                'accept' => in_array($type, ['renner', 'mercado_livre', 'shopee'], true) ? '.xlsx' : '.csv',
                'needs_create_missing' => false,
                'needs_account' => true,
                'account_source' => 'sales_channel',
                'account_channel' => $type,
            ];
        }

        foreach (AdsImportController::TYPES as $type => $cfg) {
            $entries[] = [
                'source' => 'ads', 'type' => $type,
                'title' => $cfg['title'], 'icon' => $cfg['icon'], 'description' => $cfg['description'],
                'columns' => $cfg['columns'],
                'show_route' => 'ads.import.show', 'import_route' => 'ads.import.import',
                'route_params' => ['type' => $type],
                'accept' => '.csv,.xlsx',
                'needs_create_missing' => false,
                'needs_account' => true,
                'account_source' => 'ads',
                'account_channel' => $type,
            ];
        }

        // Diário de Vendas por Canal — identificado pelas ABAS da planilha, não pelas colunas
        // (o cabeçalho de cada aba é o formato livre do próprio cliente).
        $entries[] = [
            'source' => 'sales_channel', 'type' => null,
            'title' => 'Diário de Vendas por Canal',
            'icon' => 'fa-solid fa-table-list',
            'description' => 'Planilha manual com uma aba por canal (Site, Shopee, Mercado Livre, Netshoes, etc.).',
            'columns' => [],
            'sheet_based' => true,
            'show_route' => 'sales.channel-import.show', 'import_route' => 'sales.channel-import.import',
            'route_params' => [],
            'accept' => '.xlsx,.xls',
            'needs_create_missing' => false,
            'needs_account' => false,
        ];

        // Preço de Mercado / Buy Box — colunas mais genéricas (SKU/Preço/Vendedor), fica
        // como candidato de menor prioridade quando nada mais bate melhor.
        $entries[] = [
            'source' => 'market_price', 'type' => null,
            'title' => 'Preço de Mercado (Buy Box)',
            'icon' => 'fa-solid fa-magnifying-glass-dollar',
            'description' => 'Relatório de Buy Box / concorrência (Seller Center, Hooklab). Cruza por SKU ou SKU Netshoes.',
            'columns' => ['SKU', 'Preço Buy Box', 'Vendedor Buy Box', 'Link'],
            'show_route' => 'monitoring.market.form', 'import_route' => 'monitoring.market.import',
            'route_params' => [],
            'accept' => '.xlsx,.csv',
            'needs_create_missing' => false,
            'needs_account' => false,
        ];

        return $entries;
    }

    /** Catálogo completo, já no formato de saída (rota/ícone/descrição) — usado pela grade de seleção manual quando nada é detectado. */
    public function presentCatalog(): array
    {
        return array_map(fn ($entry) => $this->present($entry, 0, [], $entry['columns'] ?? []), $this->catalog());
    }

    /**
     * @param  string[]  $header      Nomes de coluna da primeira linha/aba do arquivo.
     * @param  string[]  $sheetNames  Nomes de TODAS as abas (só relevante pra .xlsx multi-aba).
     */
    public function detect(array $header, array $sheetNames = []): array
    {
        // 1) Caso especial: planilha multi-aba do Diário de Vendas por Canal — pelo menos
        // 2 abas reconhecidas como canal é um sinal forte o bastante pra não precisar
        // nem olhar cabeçalho de coluna (cada aba tem o layout livre do cliente).
        $recognizedSheets = array_values(array_filter(array_map(
            fn ($s) => SalesChannels::fromSheetName($s), $sheetNames
        )));
        if (count($sheetNames) > 1 && count($recognizedSheets) >= 2) {
            $entry = collect($this->catalog())->firstWhere('source', 'sales_channel');
            return [
                'status' => 'confident',
                'match' => $this->present($entry, 1.0, $sheetNames, []),
                'candidates' => [],
            ];
        }

        // 2) Casamento por coluna, ponderado pela raridade de cada coluna entre os tipos
        // (uma coluna que só aparece em 1 tipo vale muito mais que "Ativo" ou "SKU", que
        // aparecem em quase todos — sem isso, tipos genéricos ganhariam por volume).
        $normalizedHeader = array_values(array_unique(array_map([$this, 'normalize'], $header)));
        $catalog = array_values(array_filter($this->catalog(), fn ($e) => !($e['sheet_based'] ?? false)));

        $documentFrequency = [];
        foreach ($catalog as $entry) {
            foreach ($entry['columns'] as $col) {
                $n = $this->normalize($col);
                $documentFrequency[$n] = ($documentFrequency[$n] ?? 0) + 1;
            }
        }
        $totalEntries = max(1, count($catalog));

        $scored = [];
        foreach ($catalog as $entry) {
            if (empty($entry['columns'])) {
                continue;
            }
            $weightSum = 0.0;
            $matchedWeight = 0.0;
            $matched = [];
            $missing = [];
            foreach ($entry['columns'] as $col) {
                $n = $this->normalize($col);
                $weight = log(($totalEntries / ($documentFrequency[$n] ?? 1)) + 1) + 1;
                $weightSum += $weight;
                if (in_array($n, $normalizedHeader, true)) {
                    $matchedWeight += $weight;
                    $matched[] = $col;
                } else {
                    $missing[] = $col;
                }
            }
            $score = $weightSum > 0 ? $matchedWeight / $weightSum : 0.0;
            $scored[] = ['entry' => $entry, 'score' => $score, 'matched' => $matched, 'missing' => $missing];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        if (empty($scored) || $scored[0]['score'] < self::MIN_CANDIDATE_SCORE) {
            return ['status' => 'unknown', 'match' => null, 'candidates' => $this->presentAll(array_slice($scored, 0, 5))];
        }

        $top = $scored[0];
        $second = $scored[1] ?? null;
        $confident = $top['score'] >= self::MIN_CONFIDENT_SCORE
            && ($second === null || ($top['score'] - $second['score']) >= self::MIN_AMBIGUOUS_GAP);

        if ($confident) {
            return [
                'status' => 'confident',
                'match' => $this->present($top['entry'], $top['score'], $top['matched'], $top['missing']),
                'candidates' => [],
            ];
        }

        return [
            'status' => 'ambiguous',
            'match' => null,
            'candidates' => $this->presentAll(array_slice($scored, 0, 5)),
        ];
    }

    private function presentAll(array $scoredList): array
    {
        return array_map(fn ($s) => $this->present($s['entry'], $s['score'], $s['matched'], $s['missing']), $scoredList);
    }

    private function present(array $entry, float $score, array $matched, array $missing): array
    {
        return [
            'source' => $entry['source'], 'type' => $entry['type'],
            'title' => $entry['title'], 'icon' => $entry['icon'], 'description' => $entry['description'],
            'score' => round($score, 2),
            'matched_columns' => $matched, 'missing_columns' => $missing,
            'show_route' => $entry['show_route'], 'import_route' => $entry['import_route'],
            'route_params' => $entry['route_params'],
            'accept' => $entry['accept'],
            'needs_create_missing' => $entry['needs_create_missing'],
            'needs_account' => $entry['needs_account'],
            'account_source' => $entry['account_source'] ?? null,
            'account_channel' => $entry['account_channel'] ?? null,
            'account_manage_route' => match ($entry['account_source'] ?? null) {
                'sales_channel' => 'sales.channel-accounts.index',
                'ads' => 'ads.accounts.index',
                default => null,
            },
        ];
    }

    /** Normaliza nome de coluna pra comparação: sem acento, minúsculo, espaços colapsados. */
    private function normalize(string $s): string
    {
        $s = Str::ascii($s);
        $s = mb_strtolower(trim($s));
        return preg_replace('/\s+/', ' ', $s);
    }
}
