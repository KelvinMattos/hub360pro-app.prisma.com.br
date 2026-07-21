<?php

namespace App\Http\Controllers\Pricing;

use App\Http\Controllers\Controller;
use App\Services\ChannelConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Configuração de precificação por empresa (parâmetros globais + canais).
 * A mesma config alimenta Centro de Decisão, Calculadora de Canais e Cálculo Promo.
 */
class ChannelSettingsController extends Controller
{
    public function __construct(private ChannelConfigService $config)
    {
    }

    public function index()
    {
        return Inertia::render('Pricing/ChannelSettings', [
            'config' => $this->config->forCompany(Auth::user()?->company_id),
        ]);
    }

    public function update(Request $request)
    {
        $companyId = Auth::user()->company_id;

        $data = $request->validate([
            'imposto' => 'required|numeric',
            'mc' => 'required|numeric',
            'descAtualDefault' => 'required|numeric',
            'descEquilDefault' => 'required|numeric',
            'rounding' => 'required|numeric',
            'channels' => 'required|array|min:1',
            'channels.*.id' => 'required|string',
            'channels.*.label' => 'required|string',
            'channels.*.comissao' => 'required|numeric',
            'channels.*.temFaixa' => 'required|in:none,ml,shopee',
            'channels.*.markup' => 'required|numeric',
            'channels.*.descAtual' => 'required|numeric',
            'channels.*.descEquil' => 'required|numeric',
            'channels.*.active' => 'boolean',
            'channels.*.origem' => 'nullable|string',
            'channels.*.col' => 'nullable|string',
        ]);

        $this->config->updateGlobals($companyId, $data);
        $this->config->updateChannels($companyId, $data['channels']);

        return back()->with('success', 'Configuração de canais salva com sucesso.');
    }

    public function reset()
    {
        $this->config->resetToDefault(Auth::user()->company_id);
        return back()->with('success', 'Configuração restaurada para o padrão.');
    }
}
