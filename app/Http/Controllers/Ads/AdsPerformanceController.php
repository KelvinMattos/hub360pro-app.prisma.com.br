<?php

namespace App\Http\Controllers\Ads;

use App\Http\Controllers\Controller;
use App\Services\Ads\AdsPerformanceService;
use App\Support\AdPlatforms;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Dashboard de ADS — cruza gasto (ad_spend_daily) com receita atribuída via
 * UTM (orders.utm_*). Pedido do cliente (04/08/2026): "monitorar a saude da
 * operação como um todo" cruzando venda real com gasto de anúncio.
 */
class AdsPerformanceController extends Controller
{
    public function __construct(private AdsPerformanceService $service)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $until = $request->filled('until') ? Carbon::parse($request->query('until')) : Carbon::now();
        $since = $request->filled('since') ? Carbon::parse($request->query('since')) : $until->copy()->subDays(29);

        return Inertia::render('Ads/Dashboard', [
            'since' => $since->toDateString(),
            'until' => $until->toDateString(),
            'overview' => $this->service->overview($companyId, $since, $until),
            'platforms' => collect(AdPlatforms::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }
}
