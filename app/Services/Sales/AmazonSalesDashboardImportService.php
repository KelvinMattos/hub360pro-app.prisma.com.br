<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Importa o CSV do "Painel de Vendas" (Business Reports → Sales Dashboard)
 * do Seller Central da Amazon — pedido do cliente (05/08/2026), depois de o
 * arquivo `vendasamazon.txt` enviado antes ter se revelado, na prática, um
 * relatório de Listagens/Catálogo (SKU, preço, estoque, status do anúncio),
 * não de vendas (ver conversa) — este .csv aqui é a fonte certa.
 *
 * Formato real validado (linha a linha, CLAUDE.md §2.4): não é uma planilha
 * comum — vem com várias seções de metadado (título, filtro, panorama do
 * período inteiro) antes da série diária de fato, que está na seção
 * "Comparar vendas - Exibição de gráfico": cabeçalho começando em "Horário",
 * uma linha por dia com data ISO + "Vendas de produtos pedidos" (BRL,
 * formato BR) + "Unidades pedidas" (numérico, formato EN — ponto decimal,
 * não vírgula) do período selecionado, mais as mesmas duas métricas do
 * "mesmo período um ano atrás" (ignoradas aqui — não é o que a tela de
 * Desempenho por Canal precisa).
 *
 * Esse relatório é agregado por DIA, não por pedido — não tem Order ID,
 * comprador nem status. Por isso entra em `channel_sales_daily` (mesmo
 * destino do Diário de Vendas manual), canal `amazon`, não em `orders`.
 * Sem contagem de pedidos no arquivo (só de unidades, que não é a mesma
 * coisa — um pedido pode ter várias unidades) — `orders_count` fica 0 em
 * vez de inventar um número (CLAUDE.md §2.2: nunca grava dado que a fonte
 * não informou). Sem tarifas/frete/cancelamento no arquivo também — ficam
 * 0; `gross_value` e `paid_value` recebem o mesmo valor ("Vendas de
 * produtos pedidos" já é a venda líquida de cancelamento no relatório da
 * própria Amazon, não dá pra separar mais que isso a partir daqui).
 */
class AmazonSalesDashboardImportService
{
    private const CHANNEL = 'amazon';

    /** Primeira linha do arquivo real, usada pra detectar o formato antes de tentar processar. */
    public static function looksLikeThisFormat(string $firstLine): bool
    {
        return self::normalize($firstLine) === 'PAINEL DE VENDAS';
    }

    public function import(int $companyId, string $path, string $sourceFile): array
    {
        if (!Schema::hasTable('channel_sales_daily')) {
            return $this->fail('Tabela channel_sales_daily não existe — rode as migrations antes de importar.');
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return $this->fail('Não foi possível abrir o arquivo.');
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $rows = [];
        while (($cells = fgetcsv($fh, 0, ',')) !== false) {
            $rows[] = $cells;
        }
        fclose($fh);

        $periodTotal = $this->findPeriodTotal($rows);

        $dailyHeaderIdx = null;
        foreach ($rows as $i => $cells) {
            if (self::normalize((string) ($cells[0] ?? '')) === 'HORARIO') {
                $dailyHeaderIdx = $i;
                break;
            }
        }

        if ($dailyHeaderIdx === null) {
            return $this->fail('Arquivo reconhecido como Painel de Vendas da Amazon, mas a seção diária ("Horário...") não foi encontrada — envie o CSV original exportado do Seller Central, sem editar.');
        }

        $now = now();
        $upserts = [];
        $rowsRead = 0;
        $rowsSkipped = 0;

        for ($i = $dailyHeaderIdx + 1; $i < count($rows); $i++) {
            $cells = $rows[$i];
            $dateRaw = trim((string) ($cells[0] ?? ''));
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})T/', $dateRaw, $m)) {
                // Fim da série diária (linha em branco ou a seção seguinte, "Comparar vendas - Exibição de tabela").
                break;
            }
            $rowsRead++;

            $salesValue = $this->brCurrency($cells[1] ?? null);
            if ($salesValue === null) {
                $rowsSkipped++;
                continue;
            }

            $upserts[] = [
                'company_id' => $companyId,
                'channel' => self::CHANNEL,
                'sale_date' => $m[1],
                'gross_value' => $salesValue,
                'paid_value' => $salesValue,
                'canceled_value' => 0,
                'fees' => 0,
                'shipping_cost' => 0,
                'net_value' => $salesValue,
                'orders_count' => 0,
                'source_file' => $sourceFile,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($upserts)) {
            return $this->fail('Nenhuma linha diária válida encontrada na seção "Horário" do arquivo.');
        }

        foreach (array_chunk($upserts, 200) as $chunk) {
            DB::table('channel_sales_daily')->upsert(
                $chunk,
                ['company_id', 'channel', 'sale_date'],
                ['gross_value', 'paid_value', 'canceled_value', 'fees', 'shipping_cost', 'net_value', 'orders_count', 'source_file', 'updated_at']
            );
        }

        $msg = 'Vendas diárias Amazon: ' . count($upserts) . ' dia(s) gravados no canal Amazon'
            . ($rowsSkipped > 0 ? ", {$rowsSkipped} linha(s) com valor inválido ignorada(s)" : '')
            . '. Não grava número de pedidos — o relatório só traz valor e unidades, não pedidos.';
        if ($periodTotal !== null) {
            $msg .= " Total do período no arquivo (conferência): R$ " . number_format($periodTotal['sales'], 2, ',', '.')
                . ", {$periodTotal['units']} unidades, {$periodTotal['items']} itens de pedido.";
        }

        return [
            'ok' => true,
            'sheets_total' => 1,
            'sheets_recognized' => 1,
            'sheets_ignored' => [],
            'rows_imported' => count($upserts),
            'rows_skipped' => $rowsSkipped,
            'message' => $msg,
        ];
    }

    /**
     * Seção "Panorama de vendas" (linhas antes da série diária): totais do
     * período inteiro, usados só pra conferência na mensagem de resultado —
     * nunca gravados como se fossem de um único dia.
     */
    private function findPeriodTotal(array $rows): ?array
    {
        foreach ($rows as $i => $cells) {
            if (self::normalize((string) ($cells[0] ?? '')) === 'TOTAL DE ITENS DO PEDIDO') {
                $data = $rows[$i + 1] ?? null;
                if ($data === null) {
                    return null;
                }
                $sales = $this->brCurrency($data[2] ?? null);
                if ($sales === null) {
                    return null;
                }

                return [
                    'items' => trim((string) ($data[0] ?? '')),
                    'units' => trim((string) ($data[1] ?? '')),
                    'sales' => $sales,
                ];
            }
        }

        return null;
    }

    /** "R$ 104.125,22" / "R$ 0,00" -> float. */
    private function brCurrency(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $v = trim(str_replace(["R$", "\xC2\xA0", ' '], '', $raw));
        if ($v === '') {
            return null;
        }
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    private static function normalize(string $value): string
    {
        return mb_strtoupper(trim(Str::ascii($value)));
    }

    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'sheets_total' => 0, 'sheets_recognized' => 0, 'sheets_ignored' => [],
            'rows_imported' => 0, 'rows_skipped' => 0,
            'message' => $message,
        ];
    }
}
