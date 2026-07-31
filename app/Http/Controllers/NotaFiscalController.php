<?php

namespace App\Http\Controllers;

use App\Models\NotaFiscalCompra;
use App\Models\NotaFiscalPagina;
use App\Services\NotasFiscais\NotaFiscalIndexService;
use Illuminate\Http\Request;
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

    public function reindex(Request $request, NotaFiscalIndexService $service)
    {
        $companyId = $request->user()->company_id;
        $force = $request->boolean('force', true);

        $result = $service->indexAll($companyId, $force);

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', "Reindexação concluída: {$result['indexed']} indexadas, {$result['skipped']} ignoradas, {$result['failed']} falharam (de {$result['total']} PDFs).");
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
