<template>
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
                    <div class="space-y-0.5">
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
import { Link, usePage } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';

const isSidebarOpen = ref(false);
const page = usePage();
const flash = computed(() => page.props.flash || {});

const navigation = [
    {
        title: 'Geral',
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: 'fa-solid fa-gauge-high' },
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
        title: 'Vendas & Mercados',
        items: [
            { label: 'Pedidos & Etiquetas', route: 'orders.index', activePattern: 'orders.*', icon: 'fa-solid fa-truck-fast' },
            { label: 'Expedição Flash', route: 'orders.expedition', icon: 'fa-solid fa-barcode' },
            { label: 'Produtos', route: 'products.index', activePattern: 'products.*', icon: 'fa-solid fa-box' },
            { label: 'Edição em Massa', route: 'marketplaces.listings.bulk', icon: 'fa-solid fa-wand-magic-sparkles' },
            { label: 'Perguntas', route: 'marketplaces.questions.index', icon: 'fa-solid fa-comments' },
            { label: 'Regras de Resposta', route: 'marketplaces.auto-reply.index', icon: 'fa-solid fa-robot' },
        ]
    },
    {
        title: 'Omnichannel',
        items: [
            { label: 'Dashboard Omni', route: 'marketplaces.dashboard', icon: 'fa-solid fa-chart-pie' },
            { label: 'Gestão de Anúncios', route: 'marketplaces.listings.index', icon: 'fa-solid fa-list-check' },
            { label: 'Ads Intelligence', route: 'marketplaces.ads.index', icon: 'fa-solid fa-bullhorn' },
            { label: 'Price Race', route: 'marketplaces.price-rules.index', icon: 'fa-solid fa-bolt' },
        ]
    },
    {
        title: 'Inteligência',
        items: [
            { label: 'Relatórios & BI', route: 'reports.index', icon: 'fa-solid fa-chart-line' },
            { label: 'War Room Meli', route: 'meli.war_room', icon: 'fa-solid fa-tower-broadcast' },
            { label: 'Reposição Inteligente', route: 'inventory.planning', icon: 'fa-solid fa-boxes-packing' },
            { label: 'Simulador 360', route: 'pricing.simulator', icon: 'fa-solid fa-calculator' },
            { label: 'Cálculo Promo', route: 'pricing.calculo-promo', activePattern: 'pricing.calculo-promo', icon: 'fa-solid fa-tags' },
        ]
    },
    {
        title: 'Importações Magazord',
        items: [
            { label: 'Importar Estoque', route: 'magazord.show', params: { type: 'estoque' }, icon: 'fa-solid fa-boxes-stacked' },
            { label: 'Importar Custos de Produtos', route: 'magazord.show', params: { type: 'custos' }, icon: 'fa-solid fa-money-bill-trend-up' },
            { label: 'Importar Preços de Venda', route: 'magazord.show', params: { type: 'precos' }, icon: 'fa-solid fa-tags' },
            { label: 'Importar Vendas', route: 'magazord.show', params: { type: 'vendas' }, icon: 'fa-solid fa-cart-shopping' },
        ]
    },
    {
        title: 'Sistema',
        items: [
            { label: 'Conexões', route: 'settings.integrations', icon: 'fa-solid fa-plug' },
            { label: 'Minha Conta', route: 'settings.account', icon: 'fa-solid fa-user-gear' },
        ]
    }
];

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
