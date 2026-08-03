<template>
    <Head :title="title ? `${title} · Hub360` : 'Hub360'" />
    <div class="flex h-screen overflow-hidden bg-[#F5F5F7] text-[#1D1D1F] font-sans selection:bg-blue-100 selection:text-blue-900">
        <!-- Sidebar macOS Style -->
        <aside 
            :class="[
                'fixed inset-y-0 left-0 z-50 w-72 glass-sidebar transform transition-transform duration-500 ease-[cubic-bezier(0.23,1,0.32,1)] lg:relative lg:translate-x-0 flex flex-col',
                isSidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Logo Section -->
            <div class="h-24 flex items-center px-10">
                <div class="flex items-center gap-3 group cursor-default">
                    <div class="w-10 h-10 bg-gradient-to-tr from-blue-600 to-blue-400 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20 group-hover:rotate-6 transition-transform">
                        <i class="fa-solid fa-layer-group text-white text-lg"></i>
                    </div>
                    <h2 class="text-xl font-bold tracking-tight text-slate-800 leading-none">
                        Hub360 <span class="font-normal text-slate-500 italic">Evolution</span>
                    </h2>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-4 py-2 space-y-8 overflow-y-auto custom-scrollbar">
                <!-- Sections -->
                <div v-for="section in navigation" :key="section.title" class="mb-8">
                    <p class="px-5 text-[11px] font-bold uppercase tracking-[0.15em] text-slate-600 mb-4 opacity-80 decoration-slate-300">{{ section.title }}</p>

                    <!-- Seções com submenus (grupos reais dentro da categoria) -->
                    <template v-if="section.groups">
                        <div v-for="group in section.groups" :key="group.label" class="mb-5 last:mb-0">
                            <p class="px-5 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">{{ group.label }}</p>
                            <div class="space-y-0.5">
                                <NavLink v-for="item in group.items" :key="item.label"
                                        :href="route(item.route, item.params || undefined)"
                                        :active="item.params ? route().current(item.route, item.params) : route().current(item.activePattern || item.route)"
                                        :icon="item.icon">
                                    {{ item.label }}
                                </NavLink>
                            </div>
                        </div>
                    </template>

                    <!-- Seções simples (sem submenu) -->
                    <div v-else class="space-y-0.5">
                        <NavLink v-for="item in section.items" :key="item.label"
                                :href="route(item.route, item.params || undefined)"
                                :active="item.params ? route().current(item.route, item.params) : route().current(item.activePattern || item.route)"
                                :icon="item.icon">
                            {{ item.label }}
                        </NavLink>
                    </div>
                </div>
            </nav>

            <!-- User Profile Section (Bottom) -->
            <div class="p-6 border-t border-black/[0.04]">
                <div class="bg-black/[0.03] p-4 rounded-2xl flex items-center gap-4 hover:bg-black/[0.05] transition-colors group cursor-pointer">
                    <div class="w-10 h-10 rounded-full bg-white shadow-sm flex items-center justify-center text-blue-600 font-bold border border-black/[0.02]">
                        {{ $page.props.auth?.user?.name?.[0] || 'U' }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-slate-800 truncate">{{ $page.props.auth?.user?.name || 'Usuário' }}</p>
                        <p class="text-[10px] text-slate-500 font-bold truncate tracking-tight">{{ $page.props.auth?.user?.company?.name || 'Companhia' }}</p>
                    </div>
                    <Link :href="route('logout')" method="post" as="button" class="text-slate-300 hover:text-red-500 transition-colors p-2">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
            <!-- Topbar (Mobile) -->
            <header class="h-20 flex items-center justify-between px-8 bg-white/80 backdrop-blur-xl border-b border-black/[0.05] lg:hidden">
                <h2 class="text-xl font-bold text-slate-900 italic">Hub360 Evolution</h2>
                <button @click="isSidebarOpen = !isSidebarOpen" class="text-slate-600 p-2">
                    <i :class="isSidebarOpen ? 'fa-solid fa-xmark' : 'fa-solid fa-bars'"></i>
                </button>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-y-auto custom-scrollbar relative z-10">
                <slot />
            </main>
            
            <!-- Background Decorative Blobs (Apple Style) -->
            <div class="absolute top-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-400/5 rounded-full blur-[120px] pointer-events-none"></div>
            <div class="absolute bottom-[-10%] left-[-10%] w-[30%] h-[30%] bg-purple-400/5 rounded-full blur-[100px] pointer-events-none"></div>
        </div>

        <!-- Notifications Layer -->
        <div class="fixed top-8 right-8 z-[100] flex flex-col gap-4 pointer-events-none">
            <TransitionGroup name="toast">
                <div v-if="flash.success" key="success" class="pointer-events-auto bg-white/90 backdrop-blur-xl border border-emerald-500/20 text-emerald-600 px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4">
                    <i class="fa-solid fa-circle-check text-xl"></i>
                    <span class="font-bold text-sm">{{ flash.success }}</span>
                </div>
                <div v-if="flash.error" key="error" class="pointer-events-auto bg-white/90 backdrop-blur-xl border border-red-500/20 text-red-600 px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4">
                    <i class="fa-solid fa-circle-exclamation text-xl"></i>
                    <span class="font-bold text-sm">{{ flash.error }}</span>
                </div>
            </TransitionGroup>
        </div>

        <!-- Mobile Overlay -->
        <div 
            v-if="isSidebarOpen" 
            @click="isSidebarOpen = false"
            class="fixed inset-0 bg-black/20 backdrop-blur-sm z-40 lg:hidden"
        ></div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';

// `title` não era declarado como prop — o Vue repassava o atributo pro elemento
// raiz do layout (fallthrough automático), virando um `title="..."` HTML na
// div que envolve a página inteira. Resultado: passar o mouse em QUALQUER
// elemento sem `title` próprio mostrava o tooltip da página (relatado pelo
// cliente em 01/08/2026 na tela de Reposição — "só aparece REPOSIÇÃO
// INTELIGENTE"). Declarar a prop e usá-la só no <Head> (título da aba do
// navegador) resolve os dois problemas de uma vez.
const props = defineProps({ title: { type: String, default: '' } });
const isSidebarOpen = ref(false);
const page = usePage();
const flash = computed(() => page.props.flash || {});

// Itens de menu atrás de feature flag (ver config/features.php) — some
// automaticamente quando a flag correspondente está desligada, sem apagar
// nada, só escondendo o atalho para uma rota que devolveria 404.
const FEATURE_BY_ROUTE = {
    'orders.index': 'orders',
    'orders.expedition': 'expedition',
    'marketplaces.questions.index': 'marketplaces_questions',
    'marketplaces.auto-reply.index': 'marketplaces_auto_reply',
    'marketplaces.listings.bulk': 'marketplaces_listings_bulk',
};

// Reorganizado em 03/08/2026 a pedido do cliente: cada item na sua categoria
// real de função (vendas em Vendas, estoque em Estoque, etc.) e tudo que é
// configuração ou importação de dados concentrado em "Ajustes & Importações",
// com submenus por tipo dentro dela — antes ficava tudo espalhado em seções
// soltas (ex: "Importar Preços de Mercado" morava em Monitoramento de Preços,
// "Config. de Canais" em Decisão & Precificação, "Aging de Estoque" fora de
// Estoque), o que não refletia a função real de cada tela.
const navigationConfig = [
    {
        title: 'Geral',
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: 'fa-solid fa-gauge-high' },
        ]
    },
    {
        title: 'Precificação',
        groups: [
            {
                label: 'Monitoramento de Mercado',
                items: [
                    { label: 'Dashboard de Competitividade', route: 'monitoring.dashboard', icon: 'fa-solid fa-satellite-dish' },
                    { label: 'Produtos Monitorados', route: 'monitoring.products', icon: 'fa-solid fa-crosshairs' },
                    { label: 'Otimizar Preços', route: 'monitoring.optimize', icon: 'fa-solid fa-wand-magic-sparkles' },
                    { label: 'Relatório de Buy Box', route: 'monitoring.report', icon: 'fa-solid fa-chart-column' },
                    { label: 'Repricing Automático', route: 'monitoring.repricing', icon: 'fa-solid fa-gauge-high' },
                    { label: 'Coleta Buy Box (Netshoes)', route: 'monitoring.scraper', icon: 'fa-solid fa-robot' },
                ],
            },
            {
                label: 'Decisão & Simulação',
                items: [
                    { label: 'Centro de Decisão', route: 'decision.index', icon: 'fa-solid fa-chess-king' },
                    { label: 'Segmentação de SKU', route: 'segmentation.index', icon: 'fa-solid fa-layer-group' },
                    { label: 'Calculadora de Canais', route: 'calculator.index', icon: 'fa-solid fa-calculator' },
                    { label: 'Cálculo Promo', route: 'pricing.calculo-promo', activePattern: 'pricing.calculo-promo', icon: 'fa-solid fa-tags' },
                    { label: 'Preços por Canal', route: 'pricing.channel-prices', icon: 'fa-solid fa-table-cells' },
                    { label: 'Simulador 360', route: 'pricing.simulator', icon: 'fa-solid fa-flask' },
                ],
            },
        ],
    },
    {
        title: 'Vendas',
        items: [
            { label: 'Central de Vendas', route: 'sales.index', icon: 'fa-solid fa-chart-simple' },
            { label: 'Desempenho por Canal', route: 'sales.channel-performance.index', icon: 'fa-solid fa-calendar-days' },
            { label: 'Pedidos & Etiquetas', route: 'orders.index', activePattern: 'orders.*', icon: 'fa-solid fa-truck-fast' },
            { label: 'Expedição Flash', route: 'orders.expedition', icon: 'fa-solid fa-barcode' },
            { label: 'Perguntas', route: 'marketplaces.questions.index', icon: 'fa-solid fa-comments' },
        ]
    },
    {
        title: 'Estoque',
        items: [
            { label: 'Produtos', route: 'products.index', activePattern: 'products.*', icon: 'fa-solid fa-box' },
            { label: 'Aging de Estoque', route: 'inventory.aging', icon: 'fa-solid fa-hourglass-half' },
            { label: 'Reposição Inteligente', route: 'inventory.planning', icon: 'fa-solid fa-boxes-packing' },
        ]
    },
    {
        title: 'Compras',
        items: [
            { label: 'Notas Fiscais de Compra', route: 'notas-fiscais.index', icon: 'fa-solid fa-file-invoice' },
        ]
    },
    {
        title: 'Financeiro',
        items: [
            { label: 'Painel CFO', route: 'financial.dashboard', icon: 'fa-solid fa-wallet' },
            { label: 'Visão DRE', route: 'financial.dre', icon: 'fa-solid fa-file-invoice-dollar' },
            { label: 'Custos Fixos', route: 'financial.fixed-expenses.index', icon: 'fa-solid fa-calculator' },
        ]
    },
    {
        title: 'Marketing',
        items: [
            { label: 'Visão Geral', route: 'marketing.dashboard', icon: 'fa-solid fa-bullseye' },
            { label: 'Campanhas (Kanban)', route: 'marketing.campaigns.index', activePattern: 'marketing.campaigns.*', icon: 'fa-solid fa-table-columns' },
            { label: 'Tarefas', route: 'marketing.tasks.index', icon: 'fa-solid fa-list-check' },
            { label: 'Calendário Comercial', route: 'marketing.calendar.index', icon: 'fa-solid fa-calendar-days' },
        ]
    },
    {
        title: 'Omnichannel',
        items: [
            { label: 'Dashboard Omni', route: 'marketplaces.dashboard', icon: 'fa-solid fa-chart-pie' },
            { label: 'Gestão de Anúncios', route: 'marketplaces.listings.index', icon: 'fa-solid fa-list-check' },
            { label: 'Edição em Massa', route: 'marketplaces.listings.bulk', icon: 'fa-solid fa-wand-magic-sparkles' },
            { label: 'Ads Intelligence', route: 'marketplaces.ads.index', icon: 'fa-solid fa-bullhorn' },
            { label: 'Price Race', route: 'marketplaces.price-rules.index', icon: 'fa-solid fa-bolt' },
        ]
    },
    {
        title: 'Inteligência',
        items: [
            { label: 'Relatórios & BI', route: 'reports.index', icon: 'fa-solid fa-chart-line' },
        ]
    },
    {
        title: 'Ajustes & Importações',
        groups: [
            {
                label: 'Configurações',
                items: [
                    { label: 'Conexões', route: 'settings.integrations', icon: 'fa-solid fa-plug' },
                    { label: 'Configurações do Sistema', route: 'settings.system', icon: 'fa-solid fa-gears' },
                    { label: 'Minha Conta', route: 'settings.account', icon: 'fa-solid fa-user-gear' },
                    { label: 'Config. de Canais', route: 'pricing.channels', icon: 'fa-solid fa-sliders' },
                ],
            },
            {
                label: 'Atendimento',
                items: [
                    { label: 'Regras de Resposta Automática', route: 'marketplaces.auto-reply.index', icon: 'fa-solid fa-robot' },
                ],
            },
            {
                label: 'Preços de Mercado',
                items: [
                    { label: 'Importar Preços de Mercado', route: 'monitoring.market.form', icon: 'fa-solid fa-file-arrow-up' },
                ],
            },
            {
                label: 'Importações de Vendas',
                items: [
                    { label: 'Vendas Diárias por Canal', route: 'sales.channel-import.show', icon: 'fa-solid fa-calendar-days' },
                ],
            },
            {
                label: 'Importações Magazord',
                items: [
                    { label: 'Importar Estoque', route: 'magazord.show', params: { type: 'estoque' }, icon: 'fa-solid fa-boxes-stacked' },
                    { label: 'Importar Custos de Produtos', route: 'magazord.show', params: { type: 'custos' }, icon: 'fa-solid fa-money-bill-trend-up' },
                    { label: 'Importar Preços de Venda', route: 'magazord.show', params: { type: 'precos' }, icon: 'fa-solid fa-tags' },
                    { label: 'Importar Produtos com Desconto', route: 'magazord.show', params: { type: 'descontos' }, icon: 'fa-solid fa-percent' },
                    { label: 'Importar Produtos & Datas', route: 'magazord.show', params: { type: 'produtos' }, icon: 'fa-solid fa-calendar-day' },
                    { label: 'Importar Vendas', route: 'magazord.show', params: { type: 'vendas' }, icon: 'fa-solid fa-cart-shopping' },
                    { label: 'Importar Vendas por Item', route: 'magazord.show', params: { type: 'vendas_itens' }, icon: 'fa-solid fa-boxes-packing' },
                    { label: 'Importar Detalhes do Pedido', route: 'magazord.show', params: { type: 'vendas_detalhes' }, icon: 'fa-solid fa-location-dot' },
                ],
            },
            {
                label: 'Importações Netshoes',
                items: [
                    { label: 'Importar Produtos Netshoes', route: 'netshoes.show', params: { type: 'produtos' }, icon: 'fa-solid fa-tags' },
                    { label: 'Importar Estoque Netshoes', route: 'netshoes.show', params: { type: 'estoque' }, icon: 'fa-solid fa-boxes-stacked' },
                    { label: 'Importar Preços Netshoes', route: 'netshoes.show', params: { type: 'precos' }, icon: 'fa-solid fa-tag' },
                    { label: 'Importar Vendas Netshoes', route: 'netshoes.show', params: { type: 'vendas' }, icon: 'fa-solid fa-cart-shopping' },
                ],
            },
        ],
    },
];

const filterItems = (items) => items.filter(item => {
    const flag = FEATURE_BY_ROUTE[item.route];
    return !flag || features.value[flag];
});

const features = computed(() => page.props.features || {});
const navigation = computed(() => navigationConfig
    .map(section => {
        if (section.groups) {
            const groups = section.groups
                .map(group => ({ ...group, items: filterItems(group.items) }))
                .filter(group => group.items.length > 0);
            return { ...section, groups };
        }
        return { ...section, items: filterItems(section.items) };
    })
    .filter(section => (section.groups ? section.groups.length > 0 : section.items.length > 0)));

// Auto-clear flash messages after 5 seconds
watch(flash, (newVal) => {
    if (newVal.success || newVal.error || newVal.warning) {
        setTimeout(() => {
            page.props.flash.success = null;
            page.props.flash.error = null;
            page.props.flash.warning = null;
        }, 5000);
    }
}, { deep: true });
</script>

<style>
.toast-enter-active, .toast-leave-active {
    transition: all 0.5s cubic-bezier(0.23, 1, 0.32, 1);
}
.toast-enter-from, .toast-leave-to {
    opacity: 0;
    transform: translateY(-20px) scale(0.95);
}
</style>
