<template>
    <AppLayout title="Painel CFO Digital">
        <div class="p-8">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-6">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        CFO <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Digital</span>
                    </h1>
                    <p class="text-slate-400 mt-2 font-medium text-lg">{{ period.label || 'Inteligência financeira em tempo real.' }}</p>
                </div>
                <div class="flex items-center gap-3 bg-white p-2 rounded-2xl border border-slate-200 shadow-sm">
                    <input type="month" v-model="monthInput" @change="applyMonth"
                        class="text-sm border-none bg-transparent px-3 py-2 outline-none font-bold text-slate-700 cursor-pointer">
                    <div class="w-px h-4 bg-slate-200"></div>
                    <button @click="goToCurrentMonth" class="px-4 py-2 text-sm font-bold text-blue-600 hover:bg-blue-50 rounded-xl transition-colors">
                        Mês atual
                    </button>
                </div>
            </div>

            <!-- Aviso de ajuste automático de período -->
            <div v-if="autoFallback" class="mb-8 p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm font-medium flex items-center gap-3">
                <i class="fa-solid fa-circle-info text-amber-500"></i>
                Nenhum pedido confirmado neste mês ainda. Mostrando o último mês com dados: <b>{{ period.label }}</b>.
            </div>

            <!-- Main Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                <!-- Gross Revenue -->
                <div class="bg-white border border-slate-200 p-6 rounded-3xl relative overflow-hidden group shadow-premium">
                    <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i class="fa-solid fa-money-bill-trend-up text-5xl text-blue-600"></i>
                    </div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Faturamento Bruto</p>
                    <h3 class="text-3xl font-black text-slate-900">R$ {{ formatCurrency(stats.grossRevenue) }}</h3>
                    <div class="mt-4 flex items-center gap-2">
                        <span v-if="revenueGrowthPct !== null"
                              class="text-xs font-bold px-2 py-0.5 rounded-full border"
                              :class="revenueGrowthPct >= 0 ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-red-600 bg-red-50 border-red-100'">
                            {{ revenueGrowthPct >= 0 ? '+' : '' }}{{ revenueGrowthPct.toFixed(1) }}% vs mês ant.
                        </span>
                        <span v-else class="text-slate-400 text-xs font-medium bg-slate-50 px-2 py-0.5 rounded-full border border-slate-100" title="dados insuficientes">
                            — vs mês ant.
                        </span>
                    </div>
                </div>

                <!-- Fixed Expenses -->
                <div class="bg-white border border-slate-200 p-6 rounded-3xl relative overflow-hidden group shadow-premium">
                    <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i class="fa-solid fa-hand-holding-dollar text-5xl text-red-600"></i>
                    </div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Custos Fixos</p>
                    <h3 class="text-3xl font-black text-slate-900">R$ {{ formatCurrency(stats.fixedExpenses) }}</h3>
                    <div class="mt-4 flex items-center gap-2">
                        <span v-if="stats.grossRevenue > 0" class="text-slate-400 text-xs font-medium">
                            Equivale a {{ ((stats.fixedExpenses / stats.grossRevenue) * 100).toFixed(1) }}% da receita
                        </span>
                        <span v-else class="text-slate-400 text-xs font-medium" title="dados insuficientes">—</span>
                    </div>
                </div>

                <!-- Margin -->
                <div class="bg-white border border-slate-200 p-6 rounded-3xl relative overflow-hidden group shadow-premium">
                    <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i class="fa-solid fa-chart-pie text-5xl text-indigo-600"></i>
                    </div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Margem Contr.</p>
                    <h3 class="text-3xl font-black text-slate-900">{{ (stats.contributionMargin ?? 0).toFixed(1) }}%</h3>
                    <div class="mt-4 h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-indigo-600" :style="{ width: Math.min(Math.max(stats.contributionMargin ?? 0, 0), 100) + '%' }"></div>
                    </div>
                </div>

                <!-- Net Profit -->
                <div class="bg-gradient-to-br from-indigo-600 to-violet-700 p-6 rounded-3xl shadow-xl shadow-indigo-500/10 relative overflow-hidden">
                    <p class="text-indigo-100 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Lucro Líquido</p>
                    <h3 class="text-3xl font-black text-white">R$ {{ formatCurrency(stats.netProfit) }}</h3>
                    <div class="mt-4 flex items-center gap-2">
                        <span class="text-indigo-50 text-xs font-medium opacity-80">Saldo disponível p/ reinvestimento</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- History Table/Chart Placeholder -->
                <div class="lg:col-span-2 bg-white border border-slate-200 rounded-3xl p-8 shadow-premium">
                    <h4 class="text-lg font-bold text-slate-900 mb-6">Histórico de Performance</h4>
                    <div class="space-y-6">
                        <div v-for="item in history" :key="item.month" class="flex items-center gap-4">
                            <span class="w-12 text-sm font-bold text-slate-400 uppercase">{{ item.month }}</span>
                            <div class="flex-1 h-8 bg-slate-50 rounded-lg overflow-hidden flex items-center px-1 border border-slate-100">
                                <div 
                                    class="h-5 bg-blue-600/80 rounded-md transition-all duration-1000" 
                                    :style="{ width: (item.revenue / (Math.max(...history.map(h => h.revenue)) || 1) * 100) + '%' }"
                                ></div>
                            </div>
                            <span class="w-24 text-right text-sm font-black text-slate-900">R$ {{ formatCurrency(item.revenue) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Additional Info -->
                <div class="bg-white border border-slate-200 rounded-3xl p-8 shadow-premium">
                    <h4 class="text-lg font-bold text-slate-900 mb-6">Resumo de Atividade</h4>
                    <div class="space-y-6">
                        <div class="flex justify-between items-center py-4 border-b border-slate-100">
                            <div>
                                <p class="text-xs text-slate-400 font-bold uppercase">Volume de Pedidos</p>
                                <p class="text-xl font-bold text-slate-900">{{ stats.orderCount }}</p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                <i class="fa-solid fa-boxes-stacked text-blue-600"></i>
                            </div>
                        </div>
                        <div class="flex justify-between items-center py-4 border-b border-slate-100">
                            <div>
                                <p class="text-xs text-slate-400 font-bold uppercase">Ticket Médio</p>
                                <p class="text-xl font-bold text-slate-900">
                                    R$ {{ formatCurrency(stats.orderCount > 0 ? stats.grossRevenue / stats.orderCount : 0) }}
                                </p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                <i class="fa-solid fa-tag text-emerald-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 p-6 rounded-2xl border" :class="marginDeltaVsHistoryPct !== null ? 'bg-blue-50 border-blue-100' : 'bg-slate-50 border-slate-100'">
                        <p v-if="marginDeltaVsHistoryPct !== null" class="text-sm font-medium text-blue-700">
                            <i class="fa-solid fa-circle-info mr-2"></i>
                            Sua margem está {{ Math.abs(marginDeltaVsHistoryPct).toFixed(1) }} p.p. {{ marginDeltaVsHistoryPct >= 0 ? 'acima' : 'abaixo' }} da sua própria média dos últimos meses.
                        </p>
                        <p v-else class="text-sm font-medium text-slate-400" title="dados insuficientes">
                            <i class="fa-solid fa-circle-info mr-2"></i>
                            Dados insuficientes para comparar sua margem com o histórico.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: Object,
    history: Array,
    companyName: String,
    revenueGrowthPct: { type: Number, default: null },
    marginDeltaVsHistoryPct: { type: Number, default: null },
    period: { type: Object, default: () => ({ month: '', label: null }) },
    autoFallback: { type: Boolean, default: false },
});

const monthInput = ref(props.period.month);

const applyMonth = () => {
    if (!monthInput.value) return;
    router.get(route('financial.dashboard'), { month: monthInput.value }, { preserveScroll: true, preserveState: false });
};

const goToCurrentMonth = () => {
    router.get(route('financial.dashboard'), {}, { preserveScroll: true, preserveState: false });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
