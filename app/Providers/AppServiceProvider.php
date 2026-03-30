<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Product::observe(\App\Observers\ProductObserver::class);
        // 1. FORÇAR HORÁRIO DE BRASÍLIA (Correção do Timezone)
        date_default_timezone_set('America/Sao_Paulo');
        config(['app.timezone' => 'America/Sao_Paulo']);

        // 2. FORÇAR IDIOMA PORTUGUÊS
        config(['app.locale' => 'pt_BR']);
        Carbon::setLocale('pt_BR');
        setlocale(LC_ALL, 'pt_BR.utf-8', 'ptb', 'pt_BR');

        // 3. CORREÇÃO DE BANCO DE DADOS (Compatibilidade com MySQL antigo)
        Schema::defaultStringLength(191);

        // 4. FORÇAR HTTPS (Segurança em Produção ou se definido no .env)
        if (str_contains(config('app.url'), 'https://')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }
    }
}