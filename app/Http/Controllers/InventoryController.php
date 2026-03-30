<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\InventoryIntelligenceService;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    protected $service;

    public function __construct(InventoryIntelligenceService $service)
    {
        $this->service = $service;
    }

    public function planning(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id)
            return redirect()->route('dashboard');

        $inventoryData = $this->service->getAggregatedInventoryStats($user->company_id);
        
        $stats = [
            'total_investment' => $inventoryData->sum('investment_needed'),
            'critical_count' => $inventoryData->where('status', 'critical')->count(),
            'immobilized' => $inventoryData->sum('immobilized_value'),
            'total_revenue' => $inventoryData->sum('revenue_30d'),
            'lost_money' => $inventoryData->sum('lost_revenue')
        ];

        return \Inertia\Inertia::render('Inventory/Replenishment', compact('inventoryData', 'stats'));
    }

    public function warRoom()
    {
        // Placeholder funcional para a War Room
        return \Inertia\Inertia::render('Inventory/Planning', [
            'inventoryData' => collect([]),
            'stats' => ['total_investment' => 0, 'critical_count' => 0, 'immobilized' => 0, 'total_revenue' => 0, 'lost_money' => 0]
        ]);
    }

    public function calculator()
    {
        return redirect()->route('dashboard')->with('info', 'Calculadora em breve');
    }
}