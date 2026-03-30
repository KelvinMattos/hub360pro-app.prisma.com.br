<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\FixedExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class FixedExpenseController extends Controller
{
    public function index()
    {
        $expenses = FixedExpense::where('company_id', Auth::user()->company_id)
            ->orderBy('expense_date', 'desc')
            ->get();

        return Inertia::render('Financial/FixedExpenses', [
            'expenses' => $expenses
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'category' => 'nullable|string|max:100',
            'expense_date' => 'required|date',
        ]);

        FixedExpense::create([
            'company_id' => Auth::user()->company_id,
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'category' => $validated['category'],
            'expense_date' => $validated['expense_date'],
        ]);

        return redirect()->back()->with('success', 'Despesa fixa adicionada com sucesso.');
    }

    public function destroy($id)
    {
        $expense = FixedExpense::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $expense->delete();

        return redirect()->back()->with('success', 'Despesa removida.');
    }
}
