<?php

namespace App\Http\Controllers;

use App\Models\NotaFiscalCompra;
use App\Models\NotaFiscalPagina;
use App\Services\NotasFiscais\NotaFiscalIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class NotaFiscalController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;

        $query = NotaFiscalCompra::with('supplier')->where('company_id', $companyId);

        if ($request->filled('data_inicio')) {
            $query->whereDate('data_emissao', '>=', $request->input('data_inicio'));
        }
        if ($request->filled('data_fim')) {
            $query->whereDate('data_emissao', '<=', $request->input('data_fim'));
        }
        if ($request->filled('fornecedor')) {
            $term = $request->input('fornecedor');
            $query->where(function ($q) use ($term) {
                $q->where('fornecedor', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $termo = $request->input('termo');
        $resultados = collect();
        if ($request->filled('termo')) {
            $resultados = $this->buscar($companyId, $termo);
        }

        $notas = $query->orderByDesc('data_emissao')->orderByDesc('id')->paginate(20)->withQueryString();

        return Inertia::render('NotasFiscais/Index', [
            'notas' => $notas,
            'resultados' => $resultados,
            'filtros' => $request->only(['data_inicio', 'data_fim', 'fornecedor', 'status', 'termo']),
        ]);
    }

    /**
     * Incidente (03/08/2026): com milhares de PDFs reais na pasta, indexar
     * ultrapassa em muito o `max_execution_time` padrão do PHP no cPanel
     * (tipicamente 30s) — o processo era morto no meio do laço e a requisição
     * voltava como 500, sempre "depois de alguns instantes". Faltava aqui o
     * mesmo `set_time_limit(0)` que toda outra importação longa do sistema já
     * usa (Magazord, Netshoes, scraper). Some a isso o corte de origem do
     * Cloudflare em ~100s (CLAUDE.md §6.3): mesmo sem limite de tempo do PHP,
     * um lote de milhares de PDFs não cabe numa única resposta HTTP. Por isso
     * agora segue o mesmo padrão de progresso em cache de arquivo + polling
     * já usado nas importações Magazord/Netshoes — com `ignore_user_abort`,
     * o processo continua rodando no servidor mesmo se o Cloudflare cortar a
     * conexão do navegador, e o resultado real chega pelo polling.
     */
    public function reindex(Request $request, NotaFiscalIndexService $service)
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $companyId = $request->user()->company_id;
        $force = $request->boolean('force', true);
        $token = (string) $request->input('progress_token', '') ?: null;
        $key = $this->progressKey($token);

        $onProgress = $key ? function (int $done, int $total) use ($key) {
            if ($done % 10 === 0 || $done === $total) {
                try {
                    Cache::store('file')->put($key, ['status' => 'processing', 'done' => $done, 'total' => $total], now()->addMinutes(30));
                } catch (\Throwable $e) {
                    // progresso é best-effort; nunca derruba a reindexação
                }
            }
        } : null;

        $result = $service->indexAll($companyId, $force, $onProgress);

        if ($key) {
            try {
                Cache::store('file')->put($key, ['status' => 'done', 'done' => $result['total'] ?? 0, 'total' => $result['total'] ?? 0, 'result' => $result], now()->addMinutes(30));
            } catch (\Throwable $e) {
                // idem — nunca derruba a reindexação por falha de cache
            }
        }

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', "Reindexação concluída: {$result['indexed']} indexadas, {$result['skipped']} ignoradas, {$result['failed']} falharam (de {$result['total']} PDFs).");
    }

    /** Endpoint de polling do progresso da reindexação (sem cache, JSON). */
    public function reindexProgress(string $token)
    {
        $data = Cache::store('file')->get($this->progressKey($token)) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private function progressKey(?string $token): ?string
    {
        return $token ? 'nf_reindex_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
    }

    public function view(Request $request, NotaFiscalCompra $nota)
    {
        abort_unless($nota->company_id === $request->user()->company_id, 404);

        $disk = Storage::disk('notas_fiscais');
        abort_unless($disk->exists($nota->path), 404, 'Arquivo não encontrado no disco.');

        return $disk->response($nota->path, $nota->filename, ['Content-Type' => 'application/pdf']);
    }

    /** Busca por texto livre (nome, EAN, código) nas páginas indexadas, agrupada por nota fiscal. */
    private function buscar(int $companyId, string $termo)
    {
        $paginas = NotaFiscalPagina::query()
            ->whereHas('notaFiscal', fn ($q) => $q->where('company_id', $companyId))
            ->whereFullText('content', $termo)
            ->with('notaFiscal.supplier')
            ->limit(100)
            ->get();

        return $paginas->map(function (NotaFiscalPagina $pagina) use ($termo) {
            return [
                'nota_fiscal_id' => $pagina->notaFiscal->id,
                'filename' => $pagina->notaFiscal->filename,
                'fornecedor' => $pagina->notaFiscal->nomeFornecedor(),
                'data_emissao' => $pagina->notaFiscal->data_emissao?->format('d/m/Y'),
                'page_number' => $pagina->page_number,
                'trecho' => $this->trecho($pagina->content ?? '', $termo),
            ];
        })->values();
    }

    private function trecho(string $content, string $termo): string
    {
        $pos = mb_stripos($content, $termo);

        if ($pos === false) {
            return e(mb_substr($content, 0, 220)).'…';
        }

        $start = max(0, $pos - 100);
        $before = mb_substr($content, $start, $pos - $start);
        $match = mb_substr($content, $pos, mb_strlen($termo));
        $after = mb_substr($content, $pos + mb_strlen($termo), 140);

        return ($start > 0 ? '…' : '').e($before).'<mark>'.e($match).'</mark>'.e($after).'…';
    }
}
