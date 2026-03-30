<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\PromotionAnalysis;
use Illuminate\Http\Request;

/**
 * Controller para o Dashboard de BI e Sugestões de Promoção.
 */
class BiDashboardController extends Controller
{
    /**
     * Exibe a análise de precificação multicanal.
     */
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $query = PromotionAnalysis::whereHas('product', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })->with(['product', 'feeConfig']);

        // Filtros (ex: somente deficitários)
        if ($request->has('deficitary')) {
            $query->where('is_deficitary', true);
        }

        $analyses = $query->paginate(20);

        return \Inertia\Inertia::render('Financial/BiDashboard', [
            'suggestions' => $analyses
        ]);
    }
}
