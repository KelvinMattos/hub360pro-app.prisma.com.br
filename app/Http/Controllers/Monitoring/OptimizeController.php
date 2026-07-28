<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\MarketOptimizerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/** Otimizar: oportunidades de preço e histórico de otimizações. */
class OptimizeController extends Controller
{
    public function __construct(private MarketOptimizerService $opt)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;
        $minMargin = (float) $request->query('min_margin', 10);

        return Inertia::render('Monitoring/Optimize', [
            'summary' => $this->opt->optimizeSummary($companyId),
            'oportunidades' => $this->opt->opportunities($companyId, $minMargin),
            'otimizacoes' => $this->opt->recentOptimizations($companyId),
            'min_margin' => $minMargin,
        ]);
    }

    /** Aplica o preço sugerido no produto (grava em promotional_price/sale_price). */
    public function apply(Request $request, int $product)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0.01'],
        ]);

        $q = DB::table('products')->where('id', $product);
        if (Schema::hasColumn('products', 'company_id')) {
            $q->where('company_id', $user->company_id);
        }

        $col = Schema::hasColumn('products', 'promotional_price') ? 'promotional_price'
            : (Schema::hasColumn('products', 'sale_price') ? 'sale_price' : null);

        if (!$col) {
            return back()->with('error', 'Não há coluna de preço para gravar.');
        }

        $q->update([$col => $data['price']]);

        return back()->with('success', 'Preço atualizado no sistema. Lembre-se de replicar no canal.');
    }
}
