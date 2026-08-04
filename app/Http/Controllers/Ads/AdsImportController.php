<?php

namespace App\Http\Controllers\Ads;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importação de gasto de campanha (Google Ads / Meta Ads) -> ad_spend_daily.
 * Mesmo desenho de OrderChannelImportController (TYPES + show/import/progress).
 *
 * IMPORTANTE (CLAUDE.md §2.4): diferente dos importadores de canal de venda
 * — todos validados linha a linha contra arquivo real do cliente — este
 * parser foi escrito de forma DEFENSIVA, com base no formato PADRÃO e
 * DOCUMENTADO dos relatórios de campanha do Google Ads e do Meta Ads
 * Manager (aceita .csv/.xlsx, procura a linha de cabeçalho em vez de supor
 * a posição, e reconhece as variações de nome de coluna mais comuns em
 * PT-BR/EN). Ainda não foi validado contra um export real do cliente — se
 * algum valor não bater ao testar, ajuste os aliases de coluna abaixo com
 * uma amostra real.
 *
 * Não coleta nada por conta própria (§2.6) e não grava credencial nenhuma
 * (§2.7) — é upload manual do relatório que o próprio Google Ads/Meta Ads
 * Manager exporta.
 */
class AdsImportController extends Controller
{
    public const TYPES = [
        'google_ads' => [
            'title' => 'Importar Gastos Google Ads',
            'icon' => 'fa-brands fa-google',
            'target' => 'ad_spend_daily',
            'key_label' => 'Campanha + Dia',
            'value_label' => 'Custo · Impressões · Cliques · Conversões',
            'description' => 'Relatório de campanha exportado do Google Ads (Relatórios → Campanhas, com quebra por dia). Aceita .csv ou .xlsx no formato padrão do Google Ads — procura a linha de cabeçalho automaticamente (o export do Google costuma trazer 1-2 linhas de título antes da tabela). Ainda não validado contra um export real — se algo não bater, envie uma amostra.',
            'columns' => ['Campaign', 'Day', 'Cost', 'Impr.', 'Clicks', 'Conversions'],
        ],
        'meta_ads' => [
            'title' => 'Importar Gastos Meta Ads',
            'icon' => 'fa-brands fa-meta',
            'target' => 'ad_spend_daily',
            'key_label' => 'Campanha + Dia',
            'value_label' => 'Valor usado · Impressões · Cliques · Resultados',
            'description' => 'Relatório de campanha exportado do Meta Ads Manager (Anúncios → Exportar → Exportar dados da tabela, com quebra por dia). Aceita .csv ou .xlsx. Ainda não validado contra um export real — se algo não bater, envie uma amostra.',
            'columns' => ['Nome da campanha', 'Dia', 'Valor usado (BRL)', 'Impressões', 'Cliques no link', 'Resultados'],
        ],
    ];

    /* ---------------- progresso ao vivo (cache de arquivo) ---------------- */
    private ?string $progressKey = null;
    private int $progTotal = 0;
    private int $progDone = 0;

    private function initProgress(?string $token): void
    {
        $this->progressKey = $token ? 'ads_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
        $this->progTotal = 0;
        $this->progDone = 0;
        $this->writeProgress('processing');
    }

    private function tick(): void
    {
        $this->progDone++;
        if ($this->progressKey && ($this->progDone % 200 === 0)) {
            $this->writeProgress('processing');
        }
    }

    private function writeProgress(string $status, array $extra = []): void
    {
        if (!$this->progressKey) return;
        try {
            Cache::store('file')->put($this->progressKey, array_merge([
                'status' => $status, 'done' => $this->progDone, 'total' => $this->progTotal,
            ], $extra), now()->addMinutes(30));
        } catch (\Throwable $e) {
            // progresso é best-effort; nunca derruba a importação
        }
    }

    public function progress(string $token)
    {
        $key = 'ads_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function show(string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $companyId = Auth::user()->company_id;

        return Inertia::render('Ads/Import', [
            'type' => $type,
            'config' => self::TYPES[$type],
            'allTypes' => collect(self::TYPES)->map(fn ($c, $k) => [
                'key' => $k, 'title' => $c['title'], 'icon' => $c['icon'],
            ])->values(),
            'accounts' => AdAccount::where('company_id', $companyId)
                ->where('platform', $type)->where('is_active', true)
                ->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function import(Request $request, string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
            'account_id' => ['required', 'integer'],
        ]);

        $companyId = Auth::user()->company_id;
        $account = AdAccount::where('id', $request->integer('account_id'))
            ->where('company_id', $companyId)->where('platform', $type)->first();
        if (!$account) {
            return redirect()->route('ads.import.show', ['type' => $type])
                ->with('error', 'Conta inválida — cadastre a conta em Contas de ADS antes de importar.');
        }

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return redirect()->route('ads.import.show', ['type' => $type])
                ->with('error', 'Formato inesperado (recebido: .' . ($ext ?: '?') . '). Envie o export original do relatório de campanha (.csv ou .xlsx).');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $path = $request->file('file')->getRealPath();
        $this->initProgress((string) $request->input('progress_token', '') ?: null);

        $summary = $this->importSpend($path, $ext, $companyId, $account->id, $type);

        $this->writeProgress('done', ['result' => $summary]);

        return redirect()->route('ads.import.show', ['type' => $type])
            ->with('importResult', $summary)
            ->with($summary['ok'] ? 'success' : 'error', $summary['message']);
    }

    private function importSpend(string $path, string $ext, int $companyId, int $accountId, string $platform): array
    {
        $rows = 0; $written = 0; $skipped = 0;

        DB::beginTransaction();
        try {
            foreach ($this->readReportRows($path, $ext) as $row) {
                $rows++; $this->tick();

                $dateRaw = $this->col($row, ['Day', 'Date', 'Dia', 'Data', 'Reporting starts']);
                $campaign = $this->col($row, ['Campaign', 'Campaign name', 'Campanha', 'Nome da campanha']);
                $date = $this->parseReportDate($dateRaw);

                if ($date === null || $campaign === null || stripos($campaign, 'total') === 0) {
                    $skipped++;
                    continue;
                }

                $spend = $this->num($this->col($row, [
                    'Cost', 'Custo', 'Custo (BRL)', 'Valor gasto', 'Valor usado (BRL)', 'Valor usado', 'Amount spent (BRL)', 'Amount spent',
                ])) ?? 0.0;
                $impressions = $this->num($this->col($row, ['Impr.', 'Impressions', 'Impressões']));
                $clicks = $this->num($this->col($row, ['Clicks', 'Cliques', 'Link clicks', 'Cliques no link']));
                $conversions = $this->num($this->col($row, ['Conversions', 'Conversões', 'Results', 'Resultados']));
                $campaignId = $this->col($row, ['Campaign ID', 'Campaign Id', 'Id da campanha']);

                DB::table('ad_spend_daily')->updateOrInsert(
                    ['ad_account_id' => $accountId, 'date' => $date, 'campaign_name' => mb_substr($campaign, 0, 190)],
                    [
                        'company_id' => $companyId,
                        'platform' => $platform,
                        'campaign_id' => $campaignId,
                        'spend' => $spend,
                        'impressions' => $impressions !== null ? (int) round($impressions) : null,
                        'clicks' => $clicks !== null ? (int) round($clicks) : null,
                        'conversions' => $conversions !== null ? (int) round($conversions) : null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $written++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->fail($e);
        }

        return [
            'ok' => true,
            'rows' => $rows,
            'created' => $written,
            'updated' => 0,
            'skipped' => $skipped,
            'message' => "Gastos importados: {$written} linhas gravadas, {$skipped} ignoradas (de {$rows} linhas lidas).",
        ];
    }

    /**
     * Detecta a linha de cabeçalho em vez de supor a posição — os exports de
     * relatório do Google Ads/Meta Ads costumam trazer 1-2 linhas de título
     * ("Campanhas relatório", período, etc.) antes da tabela, e às vezes uma
     * linha de "Total" no fim (ignorada em importSpend via nome de campanha).
     */
    private function readReportRows(string $path, string $ext): \Generator
    {
        if ($ext === 'xlsx') {
            yield from $this->readXlsxReportRows($path);
        } else {
            yield from $this->readCsvReportRows($path);
        }
    }

    private const HEADER_HINTS = ['campaign', 'campanha', 'day', 'date', 'dia', 'data'];

    private function looksLikeHeader(array $cells): bool
    {
        foreach ($cells as $c) {
            $v = mb_strtolower(trim((string) $c));
            if (in_array($v, self::HEADER_HINTS, true)) return true;
        }
        return false;
    }

    private function readXlsxReportRows(string $path): \Generator
    {
        $reader = new XlsxReader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    if ($header === null) {
                        if ($this->looksLikeHeader($cells)) {
                            $header = array_map(fn ($h) => trim((string) $h), $cells);
                        }
                        continue;
                    }
                    $assoc = [];
                    $empty = true;
                    foreach ($header as $i => $h) {
                        if ($h === '') continue;
                        $val = $cells[$i] ?? null;
                        if ($val !== null && $val !== '') $empty = false;
                        $assoc[$h] = $val;
                    }
                    if ($empty) continue;
                    yield $assoc;
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function readCsvReportRows(string $path): \Generator
    {
        $raw = file_get_contents($path);
        if ($raw === false) throw new \RuntimeException('Não foi possível abrir o arquivo.');
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $delimiter = (substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',')) ? ';' : ',';

        $header = null;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, $delimiter);
            if ($header === null) {
                if ($this->looksLikeHeader($cells)) {
                    $header = array_map(fn ($h) => trim((string) $h), $cells);
                }
                continue;
            }
            $assoc = [];
            $empty = true;
            foreach ($header as $i => $h) {
                if ($h === '') continue;
                $val = $cells[$i] ?? null;
                if ($val !== null && $val !== '') $empty = false;
                $assoc[$h] = $val;
            }
            if ($empty) continue;
            yield $assoc;
        }
    }

    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($row as $header => $value) {
                if (mb_strtolower(trim((string) $header)) === mb_strtolower($c)) {
                    if ($value === null) return null;
                    $s = is_string($value) ? $value : (string) $value;
                    return trim($s) === '' ? null : trim($s);
                }
            }
        }
        return null;
    }

    private function num($value): ?float
    {
        if ($value === null) return null;
        if (is_int($value) || is_float($value)) return (float) $value;
        $v = trim((string) $value);
        if ($v === '') return null;
        $v = str_replace(['R$', ' ', '%', "\xC2\xA0"], '', $v);
        $hasComma = str_contains($v, ',');
        $hasDot = str_contains($v, '.');
        if ($hasComma && $hasDot) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif ($hasComma) {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /** Aceita ISO (Y-m-d, padrão dos dois relatórios) e BR (d/m/Y) como fallback. */
    private function parseReportDate(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return substr($raw, 0, 10);
        }
        try {
            $dt = Carbon::createFromFormat('!d/m/Y', $raw);
            if ($dt !== false) return $dt->toDateString();
        } catch (\Throwable $e) {
            // tenta o próximo formato
        }
        return null;
    }

    private function fail(\Throwable $e): array
    {
        return [
            'ok' => false, 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
            'message' => 'Falha na importação (nada foi gravado): ' . $e->getMessage(),
        ];
    }
}
