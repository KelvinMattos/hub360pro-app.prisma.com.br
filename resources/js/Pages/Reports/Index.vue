<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header & Period Picker -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-12">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Business <span class="bg-gradient-to-r from-blue-400 to-indigo-500 bg-clip-text text-transparent">Intelligence</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Performance analítica: {{ label }}</p>
                </div>
                
                <div class="flex flex-wrap gap-2">
                    <button 
                        v-for="r in ranges" 
                        :key="r.id"
                        @click="changeRange(r.id)"
                        :class="[
                            'px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all border',
                            range === r.id ? 'bg-blue-600 border-blue-500 text-slate-900 shadow-lg shadow-blue-600/20' : 'bg-[#1E293B] border-slate-700/50 text-slate-500 hover:text-slate-900'
                        ]"
                    >
                        {{ r.label }}
                    </button>
                    <button @click="exportData" class="bg-emerald-600 hover:bg-emerald-500 text-slate-900 px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-600/20 active:scale-95 flex items-center gap-2 border border-emerald-500/20 ml-2">
                        <i class="fa-solid fa-file-excel"></i> Exportar
                    </button>
                </div>
            </div>

            <!-- KPI Row -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-12">
                <div class="bg-white shadow-premium border border-slate-200 p-8 rounded-[2.5rem] shadow-2xl relative overflow-hidden group">
                    <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i class="fa-solid fa-money-bill-trend-up text-6xl text-blue-500"></i>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-3">Faturamento Bruto</p>
                    <div class="flex items-end gap-3">
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter">R$ {{ formatCurrency(currentStats.revenue) }}</h3>
                        <span :class="['text-[10px] font-black mb-1', growth >= 0 ? 'text-emerald-400' : 'text-rose-500']">
                            <i :class="growth >= 0 ? 'fa-solid fa-caret-up' : 'fa-solid fa-caret-down'"></i>
                            {{ Math.abs(growth).toFixed(1) }}%
                        </span>
                    </div>
                </div>

                <div class="bg-white shadow-premium border border-slate-200 p-8 rounded-[2.5rem] shadow-2xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-3">Total de Pedidos</p>
                    <h3 class="text-3xl font-black text-slate-900 tracking-tighter">{{ currentStats.total_orders }}</h3>
                    <p class="text-[9px] text-slate-600 font-bold mt-2 uppercase">Conversão estável</p>
                </div>

                <div class="bg-white shadow-premium border border-slate-200 p-8 rounded-[2.5rem] shadow-2xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-3">Ticket Médio</p>
                    <h3 class="text-3xl font-black text-blue-400 tracking-tighter">R$ {{ formatCurrency(currentStats.ticket) }}</h3>
                    <p class="text-[9px] text-blue-400/30 font-bold mt-2 uppercase">Gasto médio por cliente</p>
                </div>

                <div class="bg-white shadow-premium border border-slate-200 p-8 rounded-[2.5rem] shadow-2xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-3">Lucro Líquido Est.</p>
                    <h3 class="text-3xl font-black text-emerald-400 tracking-tighter">R$ {{ formatCurrency(currentStats.profit) }}</h3>
                    <p class="text-[9px] text-emerald-400/30 font-bold mt-2 uppercase">Margem pós-despesas</p>
                </div>
            </div>

            <!-- Performance Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-12">
                <!-- Evolution Graph Placeholder -->
                <div class="lg:col-span-8 bg-white shadow-premium border border-slate-200 rounded-[3rem] p-10 shadow-2xl relative">
                    <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-10 flex items-center gap-2">
                        <i class="fa-solid fa-chart-area text-blue-500"></i> Evolução Diária de Vendas
                    </h3>
                    
                    <div class="h-64 flex items-end justify-between gap-1 px-4">
                        <div 
                            v-for="(val, idx) in chartValues" 
                            :key="idx"
                            class="flex-1 bg-gradient-to-t from-blue-600 to-indigo-400 rounded-t-lg transition-all hover:brightness-125 relative group"
                            :style="{ height: (val / Math.max(...chartValues, 1) * 100) + '%' }"
                        >
                            <div class="absolute -top-10 left-1/2 -translate-x-1/2 bg-white text-slate-900 px-2 py-1 rounded text-[9px] font-black opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap shadow-xl z-20">
                                R$ {{ formatCurrency(val) }}
                            </div>
                            <div class="absolute -bottom-8 left-1/2 -translate-x-1/2 text-[8px] font-black text-slate-500 uppercase tracking-tighter group-hover:text-slate-900 transition-colors">
                                {{ chartLabels[idx] }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Funnel -->
                <div class="lg:col-span-4 bg-white shadow-premium border border-slate-200 rounded-[3rem] p-10 shadow-2xl">
                    <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-10">Saúde do Fluxo</h3>
                    
                    <div class="space-y-6">
                        <div v-for="(val, status) in funnel" :key="status" class="space-y-2">
                            <div class="flex justify-between text-[10px] font-black uppercase tracking-widest">
                                <span class="text-slate-400">{{ statusLabels[status] }}</span>
                                <span class="text-slate-900">{{ val }}</span>
                            </div>
                            <div class="h-2 bg-slate-900 rounded-full overflow-hidden">
                                <div 
                                    :class="['h-full rounded-full transition-all duration-1000', statusColors[status]]"
                                    :style="{ width: (val / Math.max(Object.values(funnel).reduce((a, b) => a + b, 0), 1) * 100) + '%' }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Sections -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Top Products -->
                <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                    <div class="bg-black/20 p-6 border-b border-slate-800 flex justify-between items-center">
                        <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Top 10 Performance (Faturamento)</h3>
                    </div>
                    <div class="divide-y divide-slate-800/50">
                        <div v-for="(prod, idx) in topProducts" :key="idx" class="p-4 flex items-center justify-between hover:bg-white/5 transition-all">
                            <div class="flex items-center gap-4">
                                <div class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center text-[10px] font-black text-slate-500 border border-slate-700">
                                    #{{ idx + 1 }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-slate-900 truncate max-w-[200px]">{{ prod.title }}</p>
                                    <p class="text-[9px] text-slate-500 font-black uppercase tracking-widest">SKU: {{ prod.sku }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-emerald-400 font-black text-sm">R$ {{ formatCurrency(prod.total) }}</p>
                                <p class="text-[9px] text-slate-500 font-bold uppercase">{{ prod.qty }} Vendas</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Channel Mix -->
                <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl">
                     <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-10">Mix de Canais (Dominância)</h3>
                     <div class="space-y-8">
                        <div v-for="channel in channelStats" :key="channel.platform" class="flex items-center gap-6">
                            <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center text-xl">
                                <i :class="getChannelIcon(channel.platform)"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between mb-2">
                                    <span class="text-[10px] font-black text-slate-900 uppercase tracking-widest">{{ channel.platform }}</span>
                                    <span class="text-[10px] font-bold text-slate-500 italic">{{ ((channel.total / currentStats.revenue) * 100).toFixed(1) }}%</span>
                                </div>
                                <div class="h-1.5 bg-slate-900 rounded-full overflow-hidden">
                                     <div 
                                        class="h-full bg-blue-500 rounded-full"
                                        :style="{ width: ((channel.total / currentStats.revenue) * 100) + '%' }"
                                     ></div>
                                </div>
                            </div>
                            <div class="text-right min-w-[80px]">
                                <p class="text-slate-900 font-black text-xs leading-none">R$ {{ formatShortCurrency(channel.total) }}</p>
                            </div>
                        </div>
                     </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    range: String,
    label: String,
    currentStats: Object,
    growth: Number,
    chartLabels: Array,
    chartValues: Array,
    funnel: Object,
    channelStats: Array,
    topProducts: Array,
    noStock: Array
});

const ranges = [
    { id: 'today', label: 'Hoje' },
    { id: 'yesterday', label: 'Ontem' },
    { id: 'last_7_days', label: '7 Dias' },
    { id: 'this_month', label: 'Este Mês' },
    { id: 'last_month', label: 'Mês Passado' }
];

const statusLabels = {
    paid: 'Pagos / Confirmados',
    shipping: 'Em Trânsito / Separação',
    delivered: 'Entregues',
    cancelled: 'Cancelados'
};

const statusColors = {
    paid: 'bg-emerald-500',
    shipping: 'bg-blue-500',
    delivered: 'bg-indigo-500',
    cancelled: 'bg-rose-500'
};

const changeRange = (newRange) => {
    router.get(route('reports.index'), { range: newRange }, { preserveScroll: true });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value || 0);
};

const formatShortCurrency = (value) => {
    if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
    if (value >= 1000) return (value / 1000).toFixed(1) + 'K';
    return value.toFixed(0);
};

const getChannelIcon = (platform) => {
    const p = platform.toLowerCase();
    if (p.includes('mercado')) return 'fa-brands fa-laravel text-yellow-500';
    if (p.includes('shopee')) return 'fa-solid fa-bag-shopping text-orange-500';
    return 'fa-solid fa-store text-slate-400';
};

const exportData = () => {
    window.location.href = route('reports.export');
};
</script>
