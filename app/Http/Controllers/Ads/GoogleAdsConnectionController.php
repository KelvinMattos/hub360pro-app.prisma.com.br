<?php

namespace App\Http\Controllers\Ads;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Ações da tela de Conexões sobre contas de Google Ads já conectadas
 * (sincronizar agora, desconectar). O sync em si roda dentro do mesmo
 * comando artisan usado pelo cron (`google-ads:sync-spend`) — sem duplicar
 * lógica, só chamado de forma síncrona e escopado pra 1 empresa.
 */
class GoogleAdsConnectionController extends Controller
{
    public function syncNow(Integration $account)
    {
        abort_unless($account->company_id === Auth::user()->company_id, 404);
        abort_unless($account->platform === Integration::PLATFORM_GOOGLE_ADS, 404);

        Artisan::call('google-ads:sync-spend', ['--company' => $account->company_id]);

        $account->refresh();

        if ($account->last_sync_status === 'error') {
            return back()->with('error', 'Falha ao sincronizar: ' . $account->last_sync_error);
        }

        return back()->with('success', 'Sincronização concluída.');
    }
}
