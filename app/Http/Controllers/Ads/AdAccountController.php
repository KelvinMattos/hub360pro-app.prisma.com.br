<?php

namespace App\Http\Controllers\Ads;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Support\AdPlatforms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Cadastro de contas de ADS (ex.: "Google Ads - Conta Principal") — mesmo
 * padrão de SalesChannelAccountController: rótulo pra escolher no upload do
 * relatório de campanha, sem credencial nenhuma.
 */
class AdAccountController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;

        return Inertia::render('Ads/Accounts', [
            'accounts' => AdAccount::where('company_id', $companyId)
                ->orderBy('platform')->orderBy('label')->get(),
            'platforms' => collect(AdPlatforms::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'platform' => ['required', 'string'],
            'label' => ['required', 'string', 'max:120'],
            'external_account_id' => ['nullable', 'string', 'max:120'],
        ]);

        if (!AdPlatforms::isValid($data['platform'])) {
            return back()->with('error', 'Plataforma inválida.');
        }

        AdAccount::create([
            'company_id' => Auth::user()->company_id,
            'platform' => $data['platform'],
            'label' => $data['label'],
            'external_account_id' => $data['external_account_id'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', 'Conta cadastrada.');
    }

    public function toggle(AdAccount $account)
    {
        abort_unless($account->company_id === Auth::user()->company_id, 404);
        $account->update(['is_active' => !$account->is_active]);

        return back()->with('success', 'Conta atualizada.');
    }

    public function destroy(AdAccount $account)
    {
        abort_unless($account->company_id === Auth::user()->company_id, 404);
        $account->delete();

        return back()->with('success', 'Conta removida.');
    }
}
