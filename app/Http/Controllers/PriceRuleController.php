<?php

namespace App\Http\Controllers;

use App\Models\PriceRule;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PriceRuleController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;
        
        $rules = PriceRule::with('product')
            ->where('company_id', $companyId)
            ->latest()
            ->paginate(20);

        return Inertia::render('Marketplace/PriceRules', [
            'rules' => $rules
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'marketplace_item_id' => 'required|string',
            'strategy' => 'required|in:follow_cheapest,fixed_difference,percentage_margin',
            'value' => 'required|numeric',
            'min_price' => 'required|numeric',
            'max_price' => 'required|numeric',
        ]);

        PriceRule::create(array_merge($validated, [
            'company_id' => Auth::user()->company_id,
            'is_active' => true
        ]));

        return redirect()->back()->with('success', 'Regra de precificação criada com sucesso.');
    }

    public function toggle(PriceRule $rule)
    {
        $this->authorize('update', $rule);
        $rule->update(['is_active' => !$rule->is_active]);
        return redirect()->back();
    }

    public function destroy(PriceRule $rule)
    {
        $this->authorize('delete', $rule);
        $rule->delete();
        return redirect()->back();
    }
}
