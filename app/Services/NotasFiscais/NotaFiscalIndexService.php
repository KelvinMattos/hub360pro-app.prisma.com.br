<?php

namespace App\Services\NotasFiscais;

use App\Models\NotaFiscalCompra;
use App\Models\NotaFiscalPagina;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Indexação incremental dos PDFs de notas fiscais de compra: só reprocessa o
 * que é novo ou mudou de hash (nunca reprocessa tudo a cada rodada). Cada PDF
 * é isolado em try/catch — um arquivo corrompido vira `failed` e não derruba
 * o lote inteiro (mesmo princípio das importações Magazord/Netshoes).
 */
class NotaFiscalIndexService
{
    public function indexAll(int $companyId, bool $force = false, ?callable $onProgress = null): array
    {
        // O adapter local do Flysystem tenta CRIAR a raiz configurada assim que
        // é instanciado (ao resolver o disco) — se não conseguir (permissão,
        // caminho errado), derruba com uma exceção feia em vez de um erro
        // tratável. Isola a resolução do disco pra converter isso num retorno
        // gracioso, sem nunca afirmar sucesso que não aconteceu (CLAUDE.md §2.4).
        try {
            $disk = Storage::disk('notas_fiscais');
            $pastaExiste = $disk->exists('');
        } catch (Throwable $e) {
            $root = config('filesystems.disks.notas_fiscais.root');

            return ['ok' => false, 'error' => "Pasta de notas fiscais inacessível em \"{$root}\": {$e->getMessage()}", 'indexed' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
        }

        if (! $pastaExiste) {
            $root = config('filesystems.disks.notas_fiscais.root');

            return ['ok' => false, 'error' => "Pasta de notas fiscais não encontrada em \"{$root}\". Confirme o caminho (configurável via NOTAS_FISCAIS_PATH no .env).", 'indexed' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
        }

        $files = collect($disk->allFiles())
            ->filter(fn ($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf')
            ->values();

        $indexed = 0;
        $failed = 0;
        $skipped = 0;
        $total = $files->count();
        $done = 0;

        foreach ($files as $path) {
            $done++;
            if ($onProgress) {
                $onProgress($done, $total);
            }

            $nota = NotaFiscalCompra::where('company_id', $companyId)->where('path', $path)->first();
            $hash = hash_file('sha1', $disk->path($path)) ?: null;

            if ($nota && ! $force && $nota->status === 'indexed' && $nota->hash === $hash) {
                $skipped++;

                continue;
            }

            $nota ??= new NotaFiscalCompra([
                'company_id' => $companyId,
                'path' => $path,
                'filename' => basename($path),
            ]);
            $nota->filename = basename($path);
            $nota->hash = $hash;
            $nota->status = 'pending';
            $nota->save();

            try {
                $this->indexOne($nota, $disk->path($path));
                $nota->status === 'indexed' ? $indexed++ : $failed++;
            } catch (Throwable $e) {
                Log::error("NotaFiscalIndexService: falha ao indexar {$path}: {$e->getMessage()}");
                $nota->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $failed++;
            }
        }

        // Notas cujo arquivo sumiu do disco (não apaga o registro/histórico, só sinaliza).
        NotaFiscalCompra::where('company_id', $companyId)
            ->where('status', '!=', 'orphaned')
            ->whereNotIn('path', $files)
            ->update(['status' => 'orphaned']);

        return ['ok' => true, 'indexed' => $indexed, 'failed' => $failed, 'skipped' => $skipped, 'total' => $total];
    }

    private function indexOne(NotaFiscalCompra $nota, string $absolutePath): void
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($absolutePath);
        $pages = $pdf->getPages();

        $nota->paginas()->delete();

        $textLength = 0;
        foreach ($pages as $i => $page) {
            $text = trim($page->getText());
            $textLength += mb_strlen($text);
            NotaFiscalPagina::create([
                'nota_fiscal_compra_id' => $nota->id,
                'page_number' => $i + 1,
                'content' => $text !== '' ? $text : null,
            ]);
        }

        // Heurística simples: PDF com páginas mas quase sem texto extraível
        // é provavelmente uma digitalização/scan (sem camada de texto). OCR fica pra fase 2.
        $looksLikeScan = count($pages) > 0 && $textLength < (5 * count($pages));

        $nota->update([
            'pages_count' => count($pages),
            'status' => $looksLikeScan ? 'failed' : 'indexed',
            'error' => $looksLikeScan ? 'Não indexado — parece ser um documento escaneado (sem texto extraível). OCR ainda não suportado.' : null,
            'indexed_at' => now(),
        ]);
    }
}
