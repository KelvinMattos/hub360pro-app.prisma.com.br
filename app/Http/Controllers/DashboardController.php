<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Services\Financial\FinancialProrationService;
use App\Services\MeliMarketingService;
use App\Services\InventoryIntelligenceService;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected $financialService;
    protected $marketingService;
    protected $inventoryService;

    public function __construct(
        FinancialProrationService $financialService,
        MeliMarketingService $marketingService,
        InventoryIntelligenceService $inventoryService
    ) {
        $this->financialService = $financialService;
        $this->marketingService = $marketingService;
        $this->inventoryService = $inventoryService;
    }

    public function index()
    {
        $user = Auth::user();

        \Illuminate\Support\Facades\Log::info("Dashboard Access attempt", [
            'user_id' => $user->id ?? 'null',
            'company_id' => $user->company_id ?? 'null'
        ]);

        if (!$user || !$user->company_id) {
            \Illuminate\Support\Facades\Log::warning("Dashboard redirect: Missing user or company_id", ['user' => $user]);
            return redirect()->route('login');
        }

        $companyId = $user->company_id;
        
        $today = Carbon::now()->startOfDay();
        $yesterday = Carbon::now()->subDay()->startOfDay();

        // 1. Vendas Hoje vs Ontem
        $salesToday = Order::where('company_id', $companyId)
            ->whereIn('status', ['paid', 'shipped', 'delivered', 'accredited'])
            ->where('created_at', '>=', $today)
            ->sum('total_amount') ?? 0;

        $salesYesterday = Order::where('company_id', $companyId)
            ->whereIn('status', ['paid', 'shipped', 'delivered', 'accredited'])
            ->whereBetween('created_at', [$yesterday, $today])
            ->sum('total_amount') ?? 0;
        
        // 2. Status Operacional de Pedidos
        $ordersPending = Order::where('company_id', $companyId)->where('status', 'paid')->count();
        $ordersReady = Order::where('company_id', $companyId)->where('status', 'shipped')->count();
        $ordersDelayed = Order::where('company_id', $companyId)
            ->whereIn('status', ['paid'])
            ->where('created_at', '<', now()->subDays(2))
            ->count();

        // 3. Saúde dos Anúncios e Inventário
        $productsActive = Product::where('company_id', $companyId)->where('status', 'active')->count();
        $productsPaused = Product::where('company_id', $companyId)->where('status', 'paused')->count();
        $productsOutOfStock = Product::where('company_id', $companyId)->where('stock_quantity', '<=', 0)->count();

        // 4. Previsão de Falta de Estoque (Inteligência)
        $inventoryAlerts = $this->inventoryService->forCompany($companyId)->getStockOutPredictions($companyId);

        // 5. Métricas de Marketing (Adivs)
        $marketingMetrics = $this->marketingService->forCompany($companyId)->getMetrics();

        return Inertia::render('Dashboard', [
            'metrics' => [
                'sales' => [
                    'today' => (float)$salesToday,
                    'yesterday' => (float)$salesYesterday
                ],
                'orders' => [
                    'pending' => $ordersPending,
                    'ready' => $ordersReady,
                    'delayed' => $ordersDelayed,
                ],
                'inventory' => [
                    'active' => $productsActive,
                    'paused' => $productsPaused,
                    'out_of_stock' => $productsOutOfStock,
                    'alerts' => $inventoryAlerts
                ],
                'marketing' => $marketingMetrics
            ],
            'user' => [
                'name' => $user->name,
                'company' => $user->company
            ]
        ]);
    }

    public function sync()
    {
        $user = Auth::user();
        $integration = \App\Models\Integration::where('company_id', $user->company_id)
            ->whereIn('platform', ['mercadolibre', 'mercadolivre'])
            ->first();

        if ($integration) {
            app(\App\Services\MarketplaceListingService::class)->syncListings($integration);
            return redirect()->back()->with('success', 'Sincronização iniciada com sucesso.');
        }

        return redirect()->back()->with('error', 'Nenhuma integração configurada.');
    }
}