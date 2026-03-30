<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Integration;
use App\Services\MercadoLivreApiService;
use Illuminate\Support\Facades\Log;

class MeliRefreshTokenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meli:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renova os tokens de todas as contas Mercado Livre que estão perto da expiração.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Iniciando renovação global de tokens Mercado Livre...");

        $integrations = Integration::where('platform', 'mercadolivre')
            ->whereNotNull('refresh_token')
            ->get();

        $count = 0;
        foreach ($integrations as $integration) {
            if ($integration->isNearExpiration()) {
                $this->info("Renovando token para: {$integration->account_nickname} (Empresa: {$integration->company_id})");
                
                try {
                    $service = new MercadoLivreApiService();
                    $service->forCompany($integration->company_id);
                    
                    if ($service->refreshToken()) {
                        $count++;
                        $this->info("Sucesso!");
                    } else {
                        $this->error("Falha na renovação.");
                    }
                } catch (\Exception $e) {
                    $this->error("Erro ao renovar token da conta {$integration->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Processo concluído. {$count} tokens renovados.");
    }
}
