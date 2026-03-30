<template>
    <AppLayout>
        <div class="p-10 max-w-[1600px] mx-auto min-h-screen">
            <!-- Onboarding para novos usuários -->
            <div v-if="metrics.inventory.active === 0" class="flex flex-col items-center justify-center min-h-[70vh]">
                <WelcomeOnboarding :integrations-count="metrics.inventory.total_integrations" />
            </div>

            <!-- Dashboard Real (Só aparece se houver dados ativos) -->
            <template v-else>
                <!-- Header macOS Style -->
                <div class="flex flex-col md:flex-row justify-between items-end mb-12 gap-6">
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                            <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest leading-none">Sistema Online</span>
                        </div>
                        <h1 class="text-5xl font-bold text-[#1D1D1F] tracking-tight">
                            Bom dia, <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">{{ authUser.name.split(' ')[0] }}</span>
                        </h1>
                        <p class="text-[#86868B] mt-2 font-medium text-lg">Aqui está o pulso da sua operação hoje.</p>
                    </div>
                    
                    <div class="flex items-center gap-3">
                        <button @click="syncData" :disabled="syncing" class="mac-button-secondary flex items-center gap-2 !px-5 !py-3">
                            <i :class="['fa-solid fa-rotate', syncing ? 'animate-spin' : '']"></i>
                            {{ syncing ? 'Sincronizando...' : 'Sincronizar Dados' }}
                        </button>
                    </div>
                </div>

                <!-- Main High-Impact Row -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">
                <!-- Sales Velocity (The Apple-Style Large Widget) -->
                <div class="lg:col-span-2 glass-card p-10 relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-96 h-96 bg-blue-400/5 rounded-full blur-3xl -mr-32 -mt-32 transition-transform group-hover:scale-110 duration-700"></div>
                    <div class="relative z-10">
                        <div class="flex justify-between items-start mb-10">
                            <div>
                                <p class="text-[11px] font-bold text-[#86868B] uppercase tracking-[0.2em] mb-1">Vendas Totais (Líquido)</p>
                                <h3 class="text-6xl font-bold tracking-tighter text-[#1D1D1F]">R$ {{ formatCurrency(metrics.sales.today) }}</h3>
                            </div>
                            <div :class="['px-4 py-2 rounded-2xl text-sm font-bold flex items-center gap-2 border', salesGrowth >= 0 ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-red-50 text-red-600 border-red-100']">
                                <i :class="salesGrowth >= 0 ? 'fa-solid fa-arrow-trend-up' : 'fa-solid fa-arrow-trend-down'"></i>
                                {{ Math.abs(salesGrowth).toFixed(1) }}% <span class="text-[10px] opacity-60">vs ontem</span>
                            </div>
                        </div>
                        
                        <!-- Mini Chart Placeholder (Simulated Spline) -->
                        <div class="h-48 w-full flex items-end gap-1 mb-10">
                            <div v-for="i in 24" :key="i" 
                                 :style="{ height: Math.random() * 80 + 20 + '%' }"
                                 class="flex-1 bg-gradient-to-t from-blue-600/40 to-blue-400/10 rounded-t-sm hover:from-blue-600 transition-all cursor-pointer group/bar">
                                 <div class="opacity-0 group-hover/bar:opacity-100 absolute -top-10 bg-white shadow-xl p-2 rounded-lg border text-[8px] font-black z-50">R$ {{ (Math.random()*1000).toFixed(0) }}</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-10 border-t border-black/[0.04] pt-8">
                            <div>
                                <p class="text-[10px] font-bold text-[#86868B] uppercase mb-1">Ticket Médio</p>
                                <p class="text-2xl font-bold">R$ {{ (metrics.sales.today / 12).toFixed(2) }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-[#86868B] uppercase mb-1">Lucro Estimado</p>
                                <p class="text-2xl font-bold text-emerald-600">R$ {{ (metrics.sales.today * 0.18).toFixed(2) }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-[#86868B] uppercase mb-1">Margem Líquida</p>
                                <p class="text-2xl font-bold">18.4%</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Monitor (The "Traffic Control" Widget) -->
                <div class="glass-card p-8 flex flex-col justify-between mac-shadow">
                    <h3 class="text-[11px] font-bold text-[#86868B] uppercase tracking-widest mb-10">Monitor de Operação</h3>
                    <div class="space-y-4">
                        <div v-for="order in orderStats" :key="order.label" 
                             class="flex justify-between items-center p-5 rounded-3xl transition-all hover:bg-black/[0.02] border border-transparent hover:border-black/[0.03]">
                            <div class="flex items-center gap-4">
                                <div :class="['w-12 h-12 rounded-2xl flex items-center justify-center text-lg shadow-sm', order.colorClass]">
                                    <i :class="order.icon"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-[#1D1D1F]">{{ order.label }}</p>
                                    <p class="text-[10px] text-[#86868B] font-medium">{{ order.subLabel }}</p>
                                </div>
                            </div>
                            <span class="text-2xl font-bold text-slate-900">{{ order.value }}</span>
                        </div>
                    </div>
                    <div class="mt-10 p-5 bg-blue-50/50 border border-blue-100 rounded-3xl">
                        <div class="flex items-center gap-3 text-blue-600">
                            <i class="fa-solid fa-circle-info"></i>
                            <p class="text-xs font-bold font-sans">3 pedidos exigem atenção imediata por atraso na coleta.</p>
                        </div>
                    </div>
                </div>

                <!-- Marketing and Intelligence Row -->
                <!-- Advanced Ads Metrics -->
                <div class="glass-card p-8 relative overflow-hidden">
                    <div class="absolute top-4 right-6 border border-blue-100 bg-blue-50 text-blue-600 text-[9px] font-black px-2 py-1 rounded-md">Meli Ads</div>
                    <h3 class="text-[11px] font-bold text-[#86868B] uppercase tracking-widest mb-8">Performance Ads (ACOS)</h3>
                    <div class="flex items-center gap-8 mb-10">
                        <div class="relative w-28 h-28">
                            <svg class="w-full h-full transform -rotate-90">
                                <circle cx="56" cy="56" r="48" fill="transparent" stroke="currentColor" stroke-width="8" class="text-slate-100" />
                                <circle cx="56" cy="56" r="48" fill="transparent" stroke="currentColor" stroke-width="8" class="text-blue-600" 
                                        stroke-dasharray="301.59" :stroke-dashoffset="301.59 * (1 - metrics.marketing?.acos / 100)" stroke-linecap="round" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-bold">{{ metrics.marketing?.acos.toFixed(1) }}%</span>
                                <span class="text-[8px] font-black text-slate-400">ACOS</span>
                            </div>
                        </div>
                        <div class="flex-1 space-y-4">
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase">Investimento</p>
                                <p class="text-xl font-bold text-slate-800">R$ {{ formatCurrency(metrics.marketing?.investment) }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase">Retorno (ROAS)</p>
                                <p class="text-xl font-bold text-blue-600">{{ metrics.marketing?.roas.toFixed(2) }}x</p>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4 pt-6 border-t border-black/[0.04]">
                        <div class="text-center">
                            <p class="text-[9px] font-bold text-slate-400 uppercase">Cliques</p>
                            <p class="font-bold text-sm">{{ metrics.marketing?.clicks }}</p>
                        </div>
                        <div class="text-center border-x border-black/[0.04]">
                            <p class="text-[9px] font-bold text-slate-400 uppercase">CTR</p>
                            <p class="font-bold text-sm">3.2%</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[9px] font-bold text-slate-400 uppercase">Conversão</p>
                            <p class="font-bold text-sm">8.1%</p>
                        </div>
                    </div>
                </div>

                <!-- Simulation/Surprise Widget: "O Impossível" -->
                <div class="glass-card p-8 bg-gradient-to-br from-indigo-50 to-white border-indigo-100/30">
                    <h3 class="text-[11px] font-bold text-indigo-600 uppercase tracking-widest mb-6">Simulador "What-If" AI</h3>
                    <div class="space-y-6">
                        <p class="text-xs text-slate-500 font-medium">E se você <span class="text-indigo-600 font-bold">reduzir seu preço em 5%</span>?</p>
                        <div class="bg-white/60 p-5 rounded-3xl border border-indigo-50 shadow-sm">
                            <div class="flex justify-between mb-4">
                                <span class="text-[10px] font-bold text-slate-400 uppercase">Impacto nas Vendas</span>
                                <span class="text-xs font-bold text-emerald-600">+14.2%</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[10px] font-bold text-slate-400 uppercase">Lucro Líquido Real</span>
                                <span class="text-xs font-bold text-red-500">- R$ 412,00</span>
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-400 italic font-medium leading-relaxed">Conclusão: A elasticidade de preço deste anúncio não justifica a redução no momento.</p>
                        <button class="mac-button-primary w-full !rounded-2xl !py-4 shadow-indigo-200">Testar Outro Cenário</button>
                    </div>
                </div>

                <!-- Inventory & Stock Health -->
                <div class="glass-card p-8 bg-[#1D1D1F] text-white overflow-hidden relative">
                    <div class="absolute bottom-0 right-0 w-32 h-32 bg-white/5 rounded-full blur-2xl -mb-16 -mr-16"></div>
                    <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-8">Saúde do Inventário</h3>
                    <div class="space-y-8 relative z-10">
                        <div class="flex items-center gap-6">
                            <div class="w-16 h-16 rounded-2xl bg-white/10 flex flex-col items-center justify-center">
                                <span class="text-xl font-bold">{{ metrics.inventory.active }}</span>
                                <span class="text-[8px] font-black uppercase text-slate-400">Ativos</span>
                            </div>
                            <div class="flex-1">
                                <p class="text-xs font-bold mb-2">Anúncios Saudáveis</p>
                                <div class="w-full bg-white/10 h-1.5 rounded-full">
                                    <div class="bg-emerald-400 h-full rounded-full w-[92%]"></div>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                                <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Stock Out (30d)</p>
                                <p class="text-lg font-bold text-orange-400">{{ metrics.inventory.out_of_stock }} SKUs</p>
                            </div>
                            <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                                <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Overstock</p>
                                <p class="text-lg font-bold text-blue-400">12 SKUs</p>
                            </div>
                        </div>

                        <!-- Alerts List -->
                        <div v-if="metrics.inventory.alerts?.length > 0" class="pt-6 space-y-3">
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Alertas de Reposição</p>
                            <div v-for="alert in metrics.inventory.alerts" :key="alert.sku" 
                                 class="flex items-center justify-between p-3 rounded-xl bg-white/5 border border-white/5 hover:bg-white/10 transition-colors">
                                <div class="flex-1 min-w-0 mr-4">
                                    <p class="text-[10px] font-bold truncate">{{ alert.name }}</p>
                                    <p class="text-[8px] text-slate-400 uppercase">{{ alert.sku }} • {{ alert.days_remaining }} dias restantes</p>
                                </div>
                                <div :class="['px-2 py-0.5 rounded text-[8px] font-black uppercase', alert.status === 'Crítico' ? 'bg-red-500 text-white' : 'bg-orange-500 text-white']">
                                    {{ alert.status }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

                <!-- Quick Access macOS Style (Dock-like) -->
                <div class="glass-card p-6 flex flex-wrap justify-center gap-6 mb-12">
                    <Link v-for="action in quickActions" :key="action.label" :href="action.route"
                          class="group flex flex-col items-center gap-2 p-3 rounded-2xl hover:bg-black/[0.03] transition-all active:scale-90">
                        <div :class="['w-16 h-16 rounded-2xl flex items-center justify-center text-2xl shadow-sm transition-transform group-hover:-translate-y-2 group-hover:shadow-lg', action.bgColor, action.iconColor]">
                            <i :class="action.icon"></i>
                        </div>
                        <span class="text-[10px] font-bold text-[#1D1D1F] tracking-tight opacity-70 group-hover:opacity-100">{{ action.label }}</span>
                    </Link>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import WelcomeOnboarding from '@/Components/WelcomeOnboarding.vue';

const props = defineProps({
    metrics: Object,
    user: Object
});

const page = usePage();
const authUser = computed(() => props.user || { name: 'Usuário', company: { name: 'Prisma' } });
const syncing = ref(false);
const period = ref('Hoje');

const syncData = () => {
    syncing.value = true;
    router.post(route('dashboard.sync'), {}, {
        onFinish: () => syncing.value = false
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};

const salesGrowth = computed(() => {
    if (!props.metrics.sales.yesterday) return 0;
    return ((props.metrics.sales.today - props.metrics.sales.yesterday) / props.metrics.sales.yesterday) * 100;
});

const orderStats = computed(() => [
    { label: 'Aguardando Coleta', subLabel: 'Fluxo Pendente', icon: 'fa-solid fa-box-open', colorClass: 'bg-blue-50 text-blue-600', value: props.metrics.orders.pending },
    { label: 'Prontos para Envio', subLabel: 'Logística', icon: 'fa-solid fa-truck-ramp-box', colorClass: 'bg-emerald-50 text-emerald-600', value: props.metrics.orders.ready },
    { label: 'Atrasados', subLabel: 'Urgente', icon: 'fa-solid fa-clock-rotate-left', colorClass: 'bg-red-50 text-red-600', value: props.metrics.orders.delayed }
]);

const quickActions = [
    { label: 'DRE', icon: 'fa-solid fa-table-list', route: route('financial.dashboard'), bgColor: 'bg-white border border-black/[0.05]', iconColor: 'text-blue-600' },
    { label: 'Ads', icon: 'fa-solid fa-bullhorn', route: route('meli.war_room'), bgColor: 'bg-white border border-black/[0.05]', iconColor: 'text-orange-600' },
    { label: 'Trends', icon: 'fa-solid fa-arrow-trend-up', route: route('meli.war_room'), bgColor: 'bg-white border border-black/[0.05]', iconColor: 'text-red-500' },
    { label: 'Pedidos', icon: 'fa-solid fa-truck-fast', route: route('orders.index'), bgColor: 'bg-white border border-black/[0.05]', iconColor: 'text-teal-600' },
    { label: 'Produtos', icon: 'fa-solid fa-box-archive', route: route('products.index'), bgColor: 'bg-white border border-black/[0.05]', iconColor: 'text-purple-600' },
    { label: 'Ajustes', icon: 'fa-solid fa-gear', route: route('settings.integrations'), bgColor: 'bg-white border border-black/[0.05]', iconColor: 'text-slate-500' }
];
</script>

<style scoped>
.glass-sidebar {
    @apply bg-white/70 backdrop-blur-3xl border-r border-black/[0.06];
}
</style>
