<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Support\OrderImportChannels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Cadastro de contas por canal (ex.: "Mercado Livre - Loja A", "Mercado
 * Livre - Loja B") — pedido do cliente (03/08/2026) pra suportar múltiplas
 * contas do mesmo canal nos importadores nativos de Vendas. Puramente um
 * rótulo pra escolher no upload; não guarda credencial nenhuma.
 */
class SalesChannelAccountController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;

        return Inertia::render('SalesChannel/Accounts', [
            'accounts' => SalesChannelAccount::where('company_id', $companyId)
                ->orderBy('channel')->orderBy('label')->get(),
            'channels' => collect(OrderImportChannels::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'channel' => ['required', 'string'],
            'label' => ['required', 'string', 'max:120'],
            'external_identifier' => ['nullable', 'string', 'max:120'],
        ]);

        if (!OrderImportChannels::isValid($data['channel'])) {
            return back()->with('error', 'Canal inválido.');
        }

        SalesChannelAccount::create([
            'company_id' => Auth::user()->company_id,
            'channel' => $data['channel'],
            'label' => $data['label'],
            'external_identifier' => $data['external_identifier'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', 'Conta cadastrada.');
    }

    public function toggle(SalesChannelAccount $account)
    {
        abort_unless($account->company_id === Auth::user()->company_id, 404);
        $account->update(['is_active' => !$account->is_active]);

        return back()->with('success', 'Conta atualizada.');
    }

    public function destroy(SalesChannelAccount $account)
    {
        abort_unless($account->company_id === Auth::user()->company_id, 404);
        $account->delete();

        return back()->with('success', 'Conta removida.');
    }
}
