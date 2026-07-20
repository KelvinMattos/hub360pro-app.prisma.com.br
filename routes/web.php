<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\MeliIntelligenceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\Pricing\PricingSimulationController;
use App\Http\Controllers\Pricing\CalculoPromoController;
use App\Http\Controllers\Magazord\MagazordImportController;
use App\Http\Controllers\Financial\HealthDashboardController;
use App\Http\Controllers\Financial\FinancialDashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SuperAdminController;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\UserController;

// 1. Redirecionamento Inicial
Route::get('/', function () {
    return redirect()->route('login');
});

// 2. Autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class , 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class , 'login'])->name('login.process');
});

// 3. Sistema Protegido (Middleware Higienizado)
Route::middleware(['auth'])->group(function () {

    // Dashboard Principal
    Route::get('/dashboard', [DashboardController::class , 'index'])->name('dashboard');

    // Financeiro Inteligente (Dashboard CFO & DRE)
    Route::prefix('financial')->name('financial.')->group(function () {
        Route::get('/dashboard', [FinancialDashboardController::class , 'index'])->name('dashboard');
        Route::get('/dre', [HealthDashboardController::class , 'dre'])->name('dre');
        
        // Gestão de Custos Fixos (Portabilidade Mercado Turbo)
        Route::get('/fixed-expenses', [\App\Http\Controllers\Financial\FixedExpenseController::class, 'index'])->name('fixed-expenses.index');
        Route::post('/fixed-expenses', [\App\Http\Controllers\Financial\FixedExpenseController::class, 'store'])->name('fixed-expenses.store');
        Route::delete('/fixed-expenses/{id}', [\App\Http\Controllers\Financial\FixedExpenseController::class, 'destroy'])->name('fixed-expenses.destroy');
    });

        // Módulo 1: Motor de Precificação & Simulador 360 PRO
        Route::prefix('pricing')->name('pricing.')->group(function () {
            Route::get('/simulator', [PricingSimulationController::class , 'index'])->name('simulator');
            Route::post('/simulator/simulate', [PricingSimulationController::class , 'simulate'])->name('simulate');
            Route::post('/simulator/store', [PricingSimulationController::class , 'store'])->name('store');

            // Central de Cálculo Promocional — Todos os Canais (substitui a planilha CALCULO PROMO)
            Route::get('/calculo-promo', [CalculoPromoController::class , 'index'])->name('calculo-promo');
        });

        // Importações Magazord — alimenta o banco a partir dos modelos exportados pelo Magazord
        Route::prefix('imports/magazord')->name('magazord.')->group(function () {
            Route::get('/{type}', [MagazordImportController::class , 'show'])
                ->whereIn('type', ['estoque', 'custos', 'precos', 'vendas'])->name('show');
            Route::post('/{type}', [MagazordImportController::class , 'import'])
                ->whereIn('type', ['estoque', 'custos', 'precos', 'vendas'])->name('import');
        });

        // Hub 360 PRO: Monitor de Integrações
        Route::get('/hub/monitor', function () {
            // Logica temporária para o monitor enquanto o controller não é refinado
            return Inertia\Inertia::render('Hub/Monitor', [
            'stats' => [
            'processedToday' => \App\Models\WebhookLog::where('status', 'processed')->whereDate('created_at', today())->count(),
            'pending' => \App\Models\WebhookLog::whereIn('status', ['pending', 'processing'])->count(),
            'failed' => \App\Models\WebhookLog::where('status', 'failed')->count(),
            ],
            'logs' => \App\Models\WebhookLog::latest()->take(50)->get()
            ]);
        }
        )->name('hub.monitor');

        // Produtos
        Route::get('/products', [ProductController::class , 'index'])->name('products.index');
        Route::get('/products/sync', [ProductController::class , 'sync'])->name('products.sync');

        // Clientes
        Route::get('/customers', [CustomerController::class , 'index'])->name('customers.index');
        Route::get('/customers/{id}', [CustomerController::class , 'show'])->name('customers.show');

        // Pedidos e Etiquetas
        Route::get('/orders', [OrderController::class , 'index'])->name('orders.index');
        Route::get('/expedition', [\App\Http\Controllers\ExpeditionController::class, 'index'])->name('orders.expedition');
        Route::post('/expedition/{id}/pack', [\App\Http\Controllers\ExpeditionController::class, 'pack'])->name('orders.pack');
        Route::get('/orders/{id}', [OrderController::class , 'show'])->name('orders.show');
        Route::get('/orders/{id}/label', [OrderController::class , 'printLabel'])->name('orders.label');
        Route::post('/orders/{id}/sync', [OrderController::class , 'syncSingle'])->name('orders.sync_single');

        // Inteligência 360
        Route::get('/meli/war-room', [MeliIntelligenceController::class , 'warRoom'])->name('meli.war_room');
        Route::get('/meli/trends', [MeliIntelligenceController::class, 'trends'])->name('meli.trends');
        Route::get('/meli/market-share', [MeliIntelligenceController::class, 'marketShare'])->name('meli.market_share');
        Route::get('/inventory/planning', [InventoryController::class , 'planning'])->name('inventory.planning');
        Route::get('/meli/calculator', [MeliIntelligenceController::class , 'calculator'])->name('meli.calculator');

        // Relatórios & BI
        Route::get('/reports', [ReportController::class , 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class , 'exportData'])->name('reports.export');

        // Busca de Inteligência (Ajax/Inertia)
        Route::get('/meli/war-room/search', [MeliIntelligenceController::class , 'searchCompetitors'])->name('meli.war_room.search');
        Route::get('/meli/trends/search', [MeliIntelligenceController::class, 'getTrends'])->name('meli.trends.search');

        // OAuth & Conexões
        Route::get('/settings/integrations', [SettingsController::class , 'integrations'])->name('settings.integrations');
        Route::post('/settings/integrations/{platform}', [SettingsController::class , 'updateKeys'])->name('settings.update_keys');
        Route::post('/settings/finance', [SettingsController::class , 'updateFinance'])->name('settings.update_finance');

        Route::get('/settings/logs', function () {
            return redirect()->route('hub.monitor');
        }
        )->name('settings.logs');
        Route::get('/ml/connect', [SettingsController::class , 'redirectToMeli'])->name('ml.connect');
        Route::get('/ml/callback', [SettingsController::class , 'handleMeliCallback'])->name('ml.callback');

        // Módulo Hub 360 PRO — Marketplace & Omnichannel
        Route::prefix('marketplaces')->name('marketplaces.')->group(function () {
            // Dashboard Omnichannel
            Route::get('/dashboard', [\App\Http\Controllers\MarketplaceDashboardController::class, 'index'])->name('dashboard');

            // Gestão de Contas (Multi-account)
            Route::get('/accounts', [\App\Http\Controllers\MarketplaceAccountController::class, 'index'])->name('accounts.index');
            Route::patch('/accounts/{credential}/toggle', [\App\Http\Controllers\MarketplaceAccountController::class, 'toggle'])->name('accounts.toggle');
            Route::delete('/accounts/{credential}', [\App\Http\Controllers\MarketplaceAccountController::class, 'destroy'])->name('accounts.destroy');

            // Central de Perguntas
            Route::get('/questions', [\App\Http\Controllers\MarketplaceQuestionController::class, 'index'])->name('questions.index');
            Route::post('/questions/sync', [\App\Http\Controllers\MarketplaceQuestionController::class, 'sync'])->name('questions.sync');
            Route::post('/questions/{question}/answer', [\App\Http\Controllers\MarketplaceQuestionController::class, 'answer'])->name('questions.answer');

            // Automação de Perguntas (AI/Rules)
            Route::prefix('auto-reply')->name('auto-reply.')->group(function () {
                Route::get('/', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'index'])->name('index');
                Route::post('/', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'store'])->name('store');
                Route::delete('/{id}', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'destroy'])->name('destroy');
                Route::post('/{id}/toggle', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'toggle'])->name('toggle');
            });

            // Gestão de Anúncios (Listings)
            Route::get('/listings', [\App\Http\Controllers\MarketplaceListingController::class, 'index'])->name('listings.index');
            Route::get('/listings/bulk', [\App\Http\Controllers\MarketplaceListingController::class, 'bulkEditor'])->name('listings.bulk');
            Route::post('/listings/bulk', [\App\Http\Controllers\MarketplaceListingController::class, 'bulkUpdate'])->name('listings.bulk_update');
            Route::get('/listings/history', [\App\Http\Controllers\MarketplaceListingController::class, 'history'])->name('listings.history');
            Route::post('/listings/rollback', [\App\Http\Controllers\MarketplaceListingController::class, 'rollback'])->name('listings.rollback');
            Route::post('/listings/sync', [\App\Http\Controllers\MarketplaceListingController::class, 'sync'])->name('listings.sync');

            // Automação de Preços (Price Race)
            Route::get('/price-rules', [\App\Http\Controllers\PriceRuleController::class, 'index'])->name('price-rules.index');
            Route::post('/price-rules', [\App\Http\Controllers\PriceRuleController::class, 'store'])->name('price-rules.store');
            Route::post('/price-rules/{rule}/toggle', [\App\Http\Controllers\PriceRuleController::class, 'toggle'])->name('price-rules.toggle');
            Route::delete('/price-rules/{rule}', [\App\Http\Controllers\PriceRuleController::class, 'destroy'])->name('price-rules.destroy');

            // Marketing & Ads Intelligence
            Route::get('/ads', [\App\Http\Controllers\Marketplace\MarketplaceAdsController::class, 'index'])->name('ads.index');
        });

        // Logout
        Route::post('/logout', function () {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
            return redirect('/');
        }
        )->name('logout');

        // Painel Administrativo de IA (Redundância e Taxas)
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/keys', [SuperAdminController::class , 'index'])->name('keys');
            Route::post('/keys', [SuperAdminController::class , 'storeKey'])->name('keys.store');
            Route::delete('/keys/{id}', [SuperAdminController::class , 'deleteKey'])->name('keys.delete');
            Route::post('/force-update', [SuperAdminController::class , 'forceUpdate'])->name('force_update');
        }
        );

        // Minha Conta
        Route::get('/settings/account', [UserController::class, 'edit'])->name('settings.account');
        Route::put('/settings/account', [UserController::class, 'update'])->name('settings.account.update');
        Route::put('/settings/account/password', [UserController::class, 'updatePassword'])->name('settings.account.password');

    });

// 4. APIs e Webhooks (Públicos)
Route::post('/api/webhooks/{source}', [\App\Http\Controllers\Webhooks\MarketplaceWebhookController::class , 'handle'])->name('webhooks.marketplace');
Route::post('/api/webhooks/asaas', [\App\Http\Controllers\AsaasWebhookController::class , 'handle']);