<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Services\MarketplaceIntelligenceService;

class SuperAdminController extends Controller
{
    // Listar e Adicionar Chaves
    public function index()
    {
        // Segurança básica: só ID 1 ou campo is_master (ajuste conforme sua auth)
        if (Auth::id() !== 1)
            abort(403);

        $keys = DB::table('system_ai_keys')->orderBy('provider')->get();
        $rates = DB::table('marketplace_benchmark_rates')->get();

        return Inertia::render('Admin/AiConfig', [
            'keys' => $keys,
            'rates' => $rates
        ]);
    }

    public function storeKey(Request $request)
    {
        if (Auth::id() !== 1)
            abort(403);

        $request->validate(['api_key' => 'required', 'provider' => 'required']);

        DB::table('system_ai_keys')->insert([
            'provider' => $request->provider,
            'api_key' => $request->api_key,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return redirect()->back()->with('success', 'Chave de Redundância Adicionada!');
    }

    public function deleteKey($id)
    {
        if (Auth::id() !== 1)
            abort(403);
        DB::table('system_ai_keys')->delete($id);
        return redirect()->back()->with('success', 'Chave removida.');
    }

    // Botão para forçar a atualização manual agora
    public function forceUpdate(MarketplaceIntelligenceService $service)
    {
        if (Auth::id() !== 1)
            abort(403);

        $success = $service->updateRatesFromWeb();

        if ($success)
            return redirect()->back()->with('success', 'IA varreu a web e atualizou as taxas!');
        return redirect()->back()->with('error', 'Falha ao conectar com a IA. Verifique os logs.');
    }
}