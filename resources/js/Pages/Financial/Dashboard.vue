<template>
    <AppLayout>
        <div class="p-8">
            <!-- Header -->
            <div class="flex justify-between items-end mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        CFO <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Digital</span>
                    </h1>
                    <p class="text-slate-400 mt-2 font-medium text-lg">Inteligência financeira em tempo real.</p>
                </div>
                <div class="flex gap-3">
                    <button class="px-6 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all flex items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-download"></i> Exportar
                    </button>
                </div>
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
                        <span class="text-emerald-600 text-xs font-bold bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
                            +12.5% vs mês ant.
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
                        <span class="text-slate-400 text-xs font-medium">Equivale a {{ ((stats.fixedExpenses / stats.grossRevenue) * 100).toFixed(1) }}% da receita</span>
                    </div>
                </div>

                <!-- Margin -->
                <div class="bg-white border border-slate-200 p-6 rounded-3xl relative overflow-hidden group shadow-premium">
                    <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i class="fa-solid fa-chart-pie text-5xl text-indigo-600"></i>
                    </div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Margem Contr.</p>
                    <h3 class="text-3xl font-black text-slate-900">{{ stats.contributionMargin.toFixed(1) }}%</h3>
                    <div class="mt-4 h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-indigo-600" :style="{ width: stats.contributionMargin + '%' }"></div>
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

                    <div class="mt-8 p-6 bg-blue-50 border border-blue-100 rounded-2xl">
                        <p class="text-sm font-medium text-blue-700">
                            <i class="fa-solid fa-circle-info mr-2"></i>
                            Sua margem está 4% acima da média do setor este mês.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    stats: Object,
    history: Array,
    companyName: String
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
