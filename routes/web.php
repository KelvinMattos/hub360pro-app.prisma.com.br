<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\Inventory\ReplenishmentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\MeliIntelligenceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\Pricing\PricingSimulationController;
use App\Http\Controllers\Pricing\CalculoPromoController;
use App\Http\Controllers\Magazord\MagazordImportController;
use App\Http\Controllers\Netshoes\NetshoesImportController;
use App\Http\Controllers\Monitoring\MonitoringController;
use App\Http\Controllers\Monitoring\MarketPriceImportController;
use App\Http\Controllers\Monitoring\OptimizeController;
use App\Http\Controllers\Monitoring\MonitoringReportController;
use App\Http\Controllers\Monitoring\ScraperController;
use App\Http\Controllers\Monitoring\RepricingController;
use App\Http\Controllers\Financial\HealthDashboardController;
use App\Http\Controllers\Financial\FinancialDashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\Marketing\MarketingDashboardController;
use App\Http\Controllers\Marketing\CampaignController;
use App\Http\Controllers\Marketing\TaskController as MarketingTaskController;
use App\Http\Controllers\Marketing\CommercialDateController;
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

// Consulta de progresso de importação — polling via cache de arquivo.
// Usa o stack web normal (com sessão). O driver de sessão do sistema
// (database) NÃO usa lock bloqueante, então o POST de importação, mesmo
// longo, não trava este GET. NÃO remover o StartSession daqui: sem sessão,
// o VerifyCsrfToken quebra ao enfileirar o cookie XSRF-TOKEN, gerando o
// flood "Session store not set on request" no log.
Route::get('/imports/magazord/progress/{token}', [MagazordImportController::class, 'progress'])
    ->name('magazord.progress');

// Progresso de importação Netshoes — mesmo padrão (stack web normal, sem CSRF issue).
Route::get('/imports/netshoes/progress/{token}', [NetshoesImportController::class, 'progress'])
    ->name('netshoes.progress');

// Progresso de importação de preços de mercado (monitoramento).
Route::get('/monitoring/market/progress/{token}', [MarketPriceImportController::class, 'progress'])
    ->name('monitoring.market.progress');

// Progresso da coleta de Buy Box (scraper Netshoes).
Route::get('/monitoring/scraper/progress/{token}', [ScraperController::class, 'progress'])
    ->name('monitoring.scraper.progress');

// 3. Sistema Protegido (Middleware Higienizado)
Route::middleware(['auth'])->group(function () {

    // Dashboard Principal
    Route::get('/dashboard', [DashboardController::class , 'index'])->name('dashboard');

    // Centro de Decisão Gerencial — cockpit de precificação sobre dados reais
    Route::get('/decision', [\App\Http\Controllers\DecisionCenterController::class , 'index'])->name('decision.index');

    // Calculadora de Retorno por Canal (geral — substitui a calculadora só do ML)
    Route::get('/calculator', [\App\Http\Controllers\ChannelCalculatorController::class , 'index'])->name('calculator.index');
    Route::post('/calculator/compute', [\App\Http\Controllers\ChannelCalculatorController::class , 'compute'])->name('calculator.compute');

    // Segmentação de SKU — papel de precificação, ciclo de vida, saúde de estoque e posição competitiva
    Route::get('/segmentacao', [\App\Http\Controllers\SkuStrategyController::class , 'index'])->name('segmentation.index');

    // Financeiro Inteligente (Dashboard CFO & DRE)
    Route::prefix('financial')->name('financial.')->group(function () {
        Route::get('/dashboard', [FinancialDashboardController::class , 'index'])->name('dashboard');
        Route::get('/dre', [HealthDashboardController::class , 'dre'])->name('dre');
        
        // Gestão de Custos Fixos (Portabilidade Mercado Turbo)
        Route::get('/fixed-expenses', [\App\Http\Controllers\Financial\FixedExpenseController::class, 'index'])->name('fixed-expenses.index');
        Route::post('/fixed-expenses', [\App\Http\Controllers\Financial\FixedExpenseController::class, 'store'])->name('fixed-expenses.store');
        Route::delete('/fixed-expenses/{id}', [\App\Http\Controllers\Financial\FixedExpenseController::class, 'destroy'])->name('fixed-expenses.destroy');
    });

    // Módulo de Marketing — oportunidades (lançamento/mais vendido/liquidar),
    // campanhas em Kanban, tarefas do time e calendário de datas comerciais.
    Route::prefix('marketing')->name('marketing.')->group(function () {
        Route::get('/', [MarketingDashboardController::class, 'index'])->name('dashboard');

        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::post('/campaigns/from-opportunity', [CampaignController::class, 'createFromOpportunity'])->name('campaigns.from-opportunity');
        Route::get('/campaigns/products/search', [CampaignController::class, 'searchProducts'])->name('campaigns.products.search');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::patch('/campaigns/{campaign}/stage', [CampaignController::class, 'updateStage'])->name('campaigns.stage');
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
        Route::post('/campaigns/{campaign}/products', [CampaignController::class, 'attachProduct'])->name('campaigns.products.attach');
        Route::delete('/campaigns/{campaign}/products/{product}', [CampaignController::class, 'detachProduct'])->name('campaigns.products.detach');

        Route::get('/tasks', [MarketingTaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [MarketingTaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{task}', [MarketingTaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [MarketingTaskController::class, 'destroy'])->name('tasks.destroy');

        Route::get('/calendar', [CommercialDateController::class, 'index'])->name('calendar.index');
        Route::post('/calendar', [CommercialDateController::class, 'store'])->name('calendar.store');
        Route::delete('/calendar/{date}', [CommercialDateController::class, 'destroy'])->name('calendar.destroy');
        Route::post('/calendar/import', [CommercialDateController::class, 'import'])->name('calendar.import');
    });

        // Módulo 1: Motor de Precificação & Simulador 360 PRO
        Route::prefix('pricing')->name('pricing.')->group(function () {
            Route::get('/simulator', [PricingSimulationController::class , 'index'])->name('simulator');
            Route::post('/simulator/simulate', [PricingSimulationController::class , 'simulate'])->name('simulate');
            Route::post('/simulator/store', [PricingSimulationController::class , 'store'])->name('store');

            // Central de Cálculo Promocional — Todos os Canais (direto do banco)
            Route::get('/calculo-promo', [CalculoPromoController::class , 'index'])->name('calculo-promo');
            Route::get('/calculo-promo/export', [CalculoPromoController::class , 'export'])->name('calculo-promo.export');

            // Preços por Canal — preço de cada produto em cada canal, lado a lado
            Route::get('/channel-prices', [\App\Http\Controllers\Pricing\ChannelPricesController::class , 'index'])->name('channel-prices');

            // Configuração de canais (comissões, taxas, markup) por empresa
            Route::get('/channels', [\App\Http\Controllers\Pricing\ChannelSettingsController::class , 'index'])->name('channels');
            Route::post('/channels', [\App\Http\Controllers\Pricing\ChannelSettingsController::class , 'update'])->name('channels.update');
            Route::post('/channels/reset', [\App\Http\Controllers\Pricing\ChannelSettingsController::class , 'reset'])->name('channels.reset');
        });

        // Importações Magazord — alimenta o banco a partir dos modelos exportados pelo Magazord
        Route::prefix('imports/magazord')->name('magazord.')->group(function () {
            Route::get('/{type}', [MagazordImportController::class , 'show'])
                ->whereIn('type', ['estoque', 'custos', 'precos', 'descontos', 'produtos', 'vendas', 'vendas_itens'])->name('show');
            Route::post('/{type}', [MagazordImportController::class , 'import'])
                ->whereIn('type', ['estoque', 'custos', 'precos', 'descontos', 'produtos', 'vendas', 'vendas_itens'])->name('import');
        });

        // Importações Netshoes — só canal (netshoes_*), cruza pelo sku do produto
        Route::prefix('imports/netshoes')->name('netshoes.')->group(function () {
            Route::get('/{type}', [NetshoesImportController::class, 'show'])
                ->whereIn('type', ['produtos', 'estoque', 'precos', 'vendas'])->name('show');
            Route::post('/{type}', [NetshoesImportController::class, 'import'])
                ->whereIn('type', ['produtos', 'estoque', 'precos', 'vendas'])->name('import');
        });

        // Monitoramento de Preços (competitividade estilo Hooklab)
        Route::prefix('monitoring')->name('monitoring.')->group(function () {
            Route::get('/', [MonitoringController::class, 'dashboard'])->name('dashboard');
            Route::get('/produtos', [MonitoringController::class, 'products'])->name('products');
            Route::post('/produtos/{product}/mercado', [MonitoringController::class, 'setMarket'])
                ->whereNumber('product')->name('market.set');
            Route::get('/mercado/importar', [MarketPriceImportController::class, 'form'])->name('market.form');
            Route::post('/mercado/importar', [MarketPriceImportController::class, 'import'])->name('market.import');

            // Otimizar
            Route::get('/otimizar', [OptimizeController::class, 'index'])->name('optimize');
            Route::post('/otimizar/{product}/aplicar', [OptimizeController::class, 'apply'])
                ->whereNumber('product')->name('optimize.apply');

            // Relatório de competitividade / Buy Box
            Route::get('/relatorio', [MonitoringReportController::class, 'index'])->name('report');
            Route::get('/relatorio/exportar', [MonitoringReportController::class, 'export'])->name('report.export');

            // Coleta de Buy Box (scraper Netshoes)
            Route::get('/scraper', [ScraperController::class, 'index'])->name('scraper');
            Route::post('/scraper/config', [ScraperController::class, 'saveConfig'])->name('scraper.config');
            Route::post('/scraper/testar', [ScraperController::class, 'test'])->name('scraper.test');
            Route::post('/scraper/rodar', [ScraperController::class, 'run'])->name('scraper.run');

            // Repricing automático (desligado por padrão, dry-run por padrão)
            Route::get('/repricing', [RepricingController::class, 'index'])->name('repricing');
            Route::post('/repricing/config', [RepricingController::class, 'saveConfig'])->name('repricing.config');
            Route::post('/repricing/marca', [RepricingController::class, 'saveBrandMargin'])->name('repricing.brand');
            Route::post('/repricing/aplicar', [RepricingController::class, 'apply'])->name('repricing.apply');
            Route::post('/repricing/{batch}/desfazer', [RepricingController::class, 'rollback'])
                ->whereNumber('batch')->name('repricing.rollback');
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

        // Análise de Vendas (sobre pedidos importados)
        Route::get('/sales', [\App\Http\Controllers\SalesController::class , 'index'])->name('sales.index');

        // Pedidos e Etiquetas (atrás de feature flag — ver config/features.php)
        Route::middleware('feature:orders')->group(function () {
            Route::get('/orders', [OrderController::class , 'index'])->name('orders.index');
            Route::get('/orders/{id}', [OrderController::class , 'show'])->name('orders.show');
            Route::get('/orders/{id}/label', [OrderController::class , 'printLabel'])->name('orders.label');
            Route::post('/orders/{id}/sync', [OrderController::class , 'syncSingle'])->name('orders.sync_single');
        });
        Route::middleware('feature:expedition')->group(function () {
            Route::get('/expedition', [\App\Http\Controllers\ExpeditionController::class, 'index'])->name('orders.expedition');
            Route::post('/expedition/{id}/pack', [\App\Http\Controllers\ExpeditionController::class, 'pack'])->name('orders.pack');
        });

        // Inteligência 360
        // War Room removido — rota mantida como redirect para não quebrar links legados.
        Route::get('/meli/war-room', fn () => redirect()->route('decision.index'))->name('meli.war_room');
        Route::get('/meli/trends', [MeliIntelligenceController::class, 'trends'])->name('meli.trends');
        Route::get('/meli/market-share', [MeliIntelligenceController::class, 'marketShare'])->name('meli.market_share');
        Route::get('/inventory/planning', [ReplenishmentController::class, 'index'])->name('inventory.planning');
        Route::get('/inventory/planning/export', [ReplenishmentController::class, 'export'])->name('inventory.planning.export');
        Route::post('/inventory/planning/settings', [ReplenishmentController::class, 'updateSettings'])->name('inventory.planning.settings');
        Route::post('/inventory/planning/recompute', [ReplenishmentController::class, 'recompute'])->name('inventory.planning.recompute');
        Route::get('/inventory/aging', [InventoryController::class , 'aging'])->name('inventory.aging');
        // Calculadora ML antiga -> nova calculadora geral de canais
        Route::get('/meli/calculator', fn () => redirect()->route('calculator.index'))->name('meli.calculator');

        // Relatórios & BI
        Route::get('/reports', [ReportController::class , 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class , 'exportData'])->name('reports.export');

        // Busca de Inteligência (Ajax/Inertia)
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

            // Central de Perguntas (atrás de feature flag — ver config/features.php)
            Route::middleware('feature:marketplaces_questions')->group(function () {
                Route::get('/questions', [\App\Http\Controllers\MarketplaceQuestionController::class, 'index'])->name('questions.index');
                Route::post('/questions/sync', [\App\Http\Controllers\MarketplaceQuestionController::class, 'sync'])->name('questions.sync');
                Route::post('/questions/{question}/answer', [\App\Http\Controllers\MarketplaceQuestionController::class, 'answer'])->name('questions.answer');
            });

            // Automação de Perguntas (AI/Rules) — atrás de feature flag
            Route::middleware('feature:marketplaces_auto_reply')->prefix('auto-reply')->name('auto-reply.')->group(function () {
                Route::get('/', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'index'])->name('index');
                Route::post('/', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'store'])->name('store');
                Route::delete('/{id}', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'destroy'])->name('destroy');
                Route::post('/{id}/toggle', [\App\Http\Controllers\Marketplace\AutoReplyRuleController::class, 'toggle'])->name('toggle');
            });

            // Gestão de Anúncios (Listings)
            Route::get('/listings', [\App\Http\Controllers\MarketplaceListingController::class, 'index'])->name('listings.index');
            // Edição em massa — atrás de feature flag
            Route::middleware('feature:marketplaces_listings_bulk')->group(function () {
                Route::get('/listings/bulk', [\App\Http\Controllers\MarketplaceListingController::class, 'bulkEditor'])->name('listings.bulk');
                Route::post('/listings/bulk', [\App\Http\Controllers\MarketplaceListingController::class, 'bulkUpdate'])->name('listings.bulk_update');
            });
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

        // Configurações do Sistema (inclui Zona de Perigo — limpeza de emergência)
        Route::get('/settings/system', [\App\Http\Controllers\SystemSettingsController::class, 'index'])->name('settings.system');
        Route::post('/settings/system/reset-catalog', [\App\Http\Controllers\SystemSettingsController::class, 'resetCatalog'])->name('settings.system.reset_catalog');

        // Minha Conta
        Route::get('/settings/account', [UserController::class, 'edit'])->name('settings.account');
        Route::put('/settings/account', [UserController::class, 'update'])->name('settings.account.update');
        Route::put('/settings/account/password', [UserController::class, 'updatePassword'])->name('settings.account.password');

    });

// 4. APIs e Webhooks (Públicos)
Route::post('/api/webhooks/{source}', [\App\Http\Controllers\Webhooks\MarketplaceWebhookController::class , 'handle'])->name('webhooks.marketplace');
Route::post('/api/webhooks/asaas', [\App\Http\Controllers\AsaasWebhookController::class , 'handle']);