<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesChannelReportService;
use App\Support\SalesChannels;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Substitui as planilhas manuais "Diário de Vendas" e "GERAL" que o cliente
 * mantinha por fora do sistema (ver App\Services\Sales\SalesChannelReportService
 * e SalesChannelDailyImportService). Mensal/Semanal/Diário/Conta ML são
 * todos calculados a partir de channel_sales_daily — nada aqui é reimportado
 * ou duplicado, só lido de formas diferentes.
 */
class SalesChannelPerformanceController extends Controller
{
    public function __construct(private SalesChannelReportService $report)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }
        $dailyChannel = $request->query('channel');
        if ($dailyChannel !== null && !SalesChannels::isValid($dailyChannel)) {
            $dailyChannel = null;
        }

        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();

        return Inertia::render('SalesChannel/Dashboard', [
            'year' => $year,
            'month' => $month,
            'monthly' => $this->report->monthlySummary($companyId, $year),
            'weekly' => $this->report->weeklySummary($companyId, $year, $month),
            'mlAccounts' => $this->report->mercadoLivreAccounts($companyId, $year),
            'goals' => $this->report->goalsFor($companyId, $year),
            'daily' => $this->report->daily($companyId, $dailyChannel, $monthStart->format('Y-m-d'), $monthStart->copy()->endOfMonth()->format('Y-m-d')),
            'dailyChannel' => $dailyChannel,
            'channels' => collect(SalesChannels::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function saveGoal(Request $request)
    {
        $data = $request->validate([
            'channel' => ['nullable', 'string'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'goal_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $companyId = Auth::user()->company_id;
        $channel = $data['channel'] ?? '';
        if ($channel !== '' && !SalesChannels::isValid($channel)) {
            return back()->with('error', 'Canal inválido.');
        }

        $this->report->saveGoal($companyId, $channel, (int) $data['year'], (int) $data['month'], (float) $data['goal_amount']);

        return back()->with('success', 'Meta salva.');
    }

    /** Exporta a visão pedida (view=monthly|weekly|daily|ml_accounts) em pdf|xlsx|csv. */
    public function export(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $view = $request->query('view', 'monthly');
        $format = $request->query('format', 'xlsx');
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }
        $channel = $request->query('channel');
        if ($channel !== null && !SalesChannels::isValid($channel)) {
            $channel = null;
        }

        [$title, $header, $rows] = match ($view) {
            'weekly' => $this->weeklyExportData($companyId, $year, $month),
            'daily' => $this->dailyExportData($companyId, $year, $month, $channel),
            'ml_accounts' => $this->mlAccountsExportData($companyId, $year),
            default => $this->monthlyExportData($companyId, $year),
        };

        $filename = 'desempenho-canais-' . $view . '-' . $year . ($view === 'weekly' || $view === 'daily' ? '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '') . '-' . now()->format('Ymd-His');

        return match ($format) {
            'csv' => $this->csvResponse($header, $rows, $filename . '.csv'),
            'pdf' => $this->pdfResponse($title, $header, $rows, $filename . '.pdf'),
            default => $this->xlsxResponse($header, $rows, $filename . '.xlsx'),
        };
    }

    /* ================= dados por visão (formato "longo": 1 linha por combinação) ================= */

    private function monthlyExportData(int $companyId, int $year): array
    {
        $summary = $this->report->monthlySummary($companyId, $year);
        $header = ['Canal', 'Mês', $year, $summary['prev_year'], 'Variação %', 'Pedidos'];
        $rows = [];
        $monthNames = $this->monthNames();

        foreach ($summary['channels'] as $key => $label) {
            for ($m = 1; $m <= 12; $m++) {
                $entry = $summary['months'][$m][$key] ?? null;
                if ($entry === null) {
                    continue;
                }
                $rows[] = [
                    $label,
                    $monthNames[$m],
                    $entry['current']['paid_value'] ?? null,
                    $entry['previous']['paid_value'] ?? null,
                    $entry['diff_pct'] !== null ? round($entry['diff_pct'] * 100, 2) . '%' : '—',
                    $entry['current']['orders_count'] ?? null,
                ];
            }
        }

        return ["Comparativo Mensal por Canal — {$year} x {$summary['prev_year']}", $header, $rows];
    }

    private function weeklyExportData(int $companyId, int $year, int $month): array
    {
        $summary = $this->report->weeklySummary($companyId, $year, $month);
        $header = array_merge(['Semana'], array_values($summary['channels']), ['Total', 'Pedidos (Total)', 'Acumulado']);
        $rows = [];

        foreach ($summary['weeks'] as $week) {
            $row = [$week['label']];
            foreach (array_keys($summary['channels']) as $chKey) {
                $row[] = $week['channels'][$chKey]['value'] ?? 0;
            }
            $row[] = $week['total']['value'];
            $row[] = $week['total']['orders'];
            $row[] = $week['accumulated']['value'];
            $rows[] = $row;
        }

        return ["Vendas Semanais — {$this->monthNames()[$month]}/{$year}", $header, $rows];
    }

    private function dailyExportData(int $companyId, int $year, int $month, ?string $channel): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $rows = $this->report->daily($companyId, $channel, $start->format('Y-m-d'), $start->copy()->endOfMonth()->format('Y-m-d'));

        $header = ['Data', 'Canal', 'Pedidos Efetuados', 'Pedidos Pagos', 'Pedidos Cancelados', 'Taxa Efetividade', 'Tarifas', 'Frete', 'Total Líquido', 'Nº Pedidos'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                Carbon::parse($r['sale_date'])->format('d/m/Y'),
                $r['channel_label'],
                $r['gross_value'],
                $r['paid_value'],
                $r['canceled_value'],
                $r['effectiveness_rate'] !== null ? round($r['effectiveness_rate'] * 100, 2) . '%' : '—',
                $r['fees'],
                $r['shipping_cost'],
                $r['net_value'],
                $r['orders_count'],
            ];
        }

        return ["Diário de Vendas — {$this->monthNames()[$month]}/{$year}", $header, $out];
    }

    private function mlAccountsExportData(int $companyId, int $year): array
    {
        $summary = $this->report->mercadoLivreAccounts($companyId, $year);
        $header = ['Conta', 'Mês', 'Pedidos Efetuados', 'Pedidos Pagos', 'Pedidos Cancelados', 'Tarifas', 'Frete', 'Total Líquido', 'Variação Mês Anterior'];
        $monthNames = $this->monthNames();
        $rows = [];

        foreach ($summary['accounts'] as $account) {
            for ($m = 1; $m <= 12; $m++) {
                $entry = $account['months'][$m];
                if ($entry['current'] === null) {
                    continue;
                }
                $c = $entry['current'];
                $rows[] = [
                    $account['label'], $monthNames[$m],
                    $c['gross_value'], $c['paid_value'], $c['canceled_value'],
                    $c['fees'], $c['shipping_cost'], $c['net_value'],
                    $entry['diff_pct'] !== null ? round($entry['diff_pct'] * 100, 2) . '%' : '—',
                ];
            }
        }

        return ["Mercado Livre — Desempenho por Conta ({$year})", $header, $rows];
    }

    private function monthNames(): array
    {
        return [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
            7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    }

    /* ================= formatos de saída ================= */

    private function csvResponse(array $header, array $rows, string $filename)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
            fputcsv($out, $header, ';');
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsxResponse(array $header, array $rows, string $filename)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sales_ch_export_');
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->close();

        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function pdfResponse(string $title, array $header, array $rows, string $filename)
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.sales-channel-performance', [
            'title' => $title,
            'header' => $header,
            'rows' => $rows,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }
}
