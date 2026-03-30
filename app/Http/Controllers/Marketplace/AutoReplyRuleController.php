<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\AutoReplyRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AutoReplyRuleController extends Controller
{
    public function index()
    {
        $rules = AutoReplyRule::where('company_id', Auth::user()->company_id)
            ->orderBy('priority', 'desc')
            ->get();

        return Inertia::render('Marketplace/AutoReplyRules', [
            'rules' => $rules
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|exists:auto_reply_rules,id',
            'name' => 'required|string|max:255',
            'keywords' => 'required|string',
            'reply_text' => 'required|string',
            'priority' => 'required|integer|min:0',
            'is_active' => 'required|boolean'
        ]);

        AutoReplyRule::updateOrCreate(
            ['id' => $validated['id'], 'company_id' => Auth::user()->company_id],
            $validated
        );

        return redirect()->back()->with('success', 'Regra de automação salva.');
    }

    public function destroy($id)
    {
        $rule = AutoReplyRule::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $rule->delete();

        return redirect()->back()->with('success', 'Regra excluída.');
    }

    public function toggle($id)
    {
        $rule = AutoReplyRule::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $rule->is_active = !$rule->is_active;
        $rule->save();

        return redirect()->back()->with('success', 'Status da regra atualizado.');
    }
}
