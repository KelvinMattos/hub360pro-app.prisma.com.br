<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Inteligência de <span class="bg-gradient-to-r from-emerald-400 to-cyan-500 bg-clip-text text-transparent">Inventário</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Giro, Curva ABC e Reposição Estratégica.</p>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-10">
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Investimento Nec.</p>
                    <h3 class="text-2xl font-black text-slate-900 tracking-tighter">R$ {{ formatCurrency(stats.total_investment) }}</h3>
                    <p class="text-[9px] text-emerald-400 font-bold mt-1 uppercase">Para 45 dias de estoque</p>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Ruptura Crítica</p>
                    <h3 class="text-2xl font-black text-red-500 tracking-tighter">{{ stats.critical_count }} SKUs</h3>
                    <p class="text-[9px] text-red-400/50 font-bold mt-1 uppercase">Reposição imediata</p>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Capital Imobilizado</p>
                    <h3 class="text-2xl font-black text-orange-500 tracking-tighter">R$ {{ formatCurrency(stats.immobilized) }}</h3>
                    <p class="text-[9px] text-orange-400/50 font-bold mt-1 uppercase">Estoque > 90 dias</p>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Faturamento (30d)</p>
                    <h3 class="text-2xl font-black text-blue-400 tracking-tighter">R$ {{ formatCurrency(stats.total_revenue) }}</h3>
                    <p class="text-[9px] text-blue-400/50 font-bold mt-1 uppercase">Receita projetada</p>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Faturamento Perdido</p>
                    <h3 class="text-2xl font-black text-rose-500 tracking-tighter">R$ {{ formatCurrency(stats.lost_money) }}</h3>
                    <p class="text-[9px] text-rose-400/50 font-bold mt-1 uppercase">Estimativa falta estoque</p>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                <div class="bg-black/20 p-6 border-b border-slate-800 flex justify-between items-center">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Planejamento de Carga & Reposição</h3>
                    <div class="flex gap-2">
                        <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 rounded-full text-[9px] font-black border border-emerald-500/20">A: {{ inventoryData.filter(i => i.curve === 'A').length }}</span>
                        <span class="px-3 py-1 bg-blue-500/10 text-blue-400 rounded-full text-[9px] font-black border border-blue-500/20">B: {{ inventoryData.filter(i => i.curve === 'B').length }}</span>
                        <span class="px-3 py-1 bg-slate-800 text-slate-500 rounded-full text-[9px] font-black border border-slate-700">C: {{ inventoryData.filter(i => i.curve === 'C').length }}</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-black/10 text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-800">
                                <th class="p-6">Curva</th>
                                <th class="p-6">Produto / Master SKU</th>
                                <th class="p-6 text-center">Saídas (30d)</th>
                                <th class="p-6 text-center">Estoque Atual</th>
                                <th class="p-6 text-center">DOC (Cobertura)</th>
                                <th class="p-6 text-center">Status Giro</th>
                                <th class="p-6 text-right">Sugestão Compra</th>
                                <th class="p-6 text-right">Investimento</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            <tr 
                                v-for="item in sortedInventory" 
                                :key="item.sku"
                                class="hover:bg-blue-500/5 transition-all group"
                            >
                                <td class="p-6">
                                    <div :class="['w-8 h-8 rounded-lg flex items-center justify-center font-black text-xs shadow-lg', curveClasses[item.curve]]">
                                        {{ item.curve }}
                                    </div>
                                </td>
                                <td class="p-6 min-w-[300px]">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 bg-white rounded-xl overflow-hidden p-1 shrink-0 shadow-md">
                                            <img :src="item.image" class="w-full h-full object-contain">
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-slate-900 font-bold text-sm truncate group-hover:text-blue-400 transition-colors">{{ item.title }}</p>
                                            <p class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-1">
                                                SKU: {{ item.sku }} 
                                                <span v-if="item.cannibal" class="ml-2 text-rose-500 animate-pulse"><i class="fa-solid fa-triangle-exclamation"></i> Conflito de Preço</span>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-6 text-center">
                                    <p class="text-slate-900 font-black text-base">{{ item.sales_30d }}</p>
                                    <p class="text-[9px] text-slate-500 font-bold uppercase">{{ item.frequency }}</p>
                                </td>
                                <td class="p-6 text-center">
                                    <p class="text-slate-900 font-black text-base">{{ item.stock }}</p>
                                    <p class="text-[9px] text-slate-500 font-bold uppercase">Unidades</p>
                                </td>
                                <td class="p-6 text-center">
                                    <div 
                                        :class="['text-base font-black tracking-tighter', 
                                            item.doc <= 7 ? 'text-red-500' : 
                                            item.doc <= 15 ? 'text-orange-400' : 
                                            item.doc > 90 ? 'text-indigo-400' : 'text-emerald-400'
                                        ]"
                                    >
                                        {{ item.doc === 999 ? '∞' : item.doc + ' dias' }}
                                    </div>
                                </td>
                                <td class="p-6 text-center">
                                    <span :class="['px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border', statusClasses[item.status]]">
                                        {{ item.status }}
                                    </span>
                                </td>
                                <td class="p-6 text-right">
                                    <div v-if="item.suggestion > 0">
                                        <p class="text-blue-400 font-black text-base leading-none">+{{ item.suggestion }}</p>
                                        <p class="text-[9px] text-slate-500 font-bold uppercase mt-1">Novas Unidades</p>
                                    </div>
                                    <span v-else class="text-slate-600 font-black text-xs uppercase">Estoque OK</span>
                                </td>
                                <td class="p-6 text-right">
                                    <p class="text-slate-900 font-black text-sm">R$ {{ formatCurrency(item.investment_needed) }}</p>
                                    <p class="text-[9px] text-slate-500 font-bold uppercase">Custo Est.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    inventoryData: Array,
    stats: Object
});

const curveClasses = {
    'A': 'bg-emerald-500 text-slate-900 shadow-emerald-500/20',
    'B': 'bg-blue-500 text-slate-900 shadow-blue-500/20',
    'C': 'bg-slate-700 text-slate-300 shadow-slate-900/20'
};

const statusClasses = {
    'critical': 'bg-red-500/10 text-red-500 border-red-500/20',
    'alert': 'bg-orange-500/10 text-orange-500 border-orange-500/20',
    'healthy': 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    'overstock': 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20',
    'stagnant': 'bg-slate-800 text-slate-500 border-slate-700'
};

const sortedInventory = computed(() => {
    return [...props.inventoryData].sort((a, b) => {
        if (a.curve !== b.curve) {
            return a.curve.localeCompare(b.curve);
        }
        return b.revenue_30d - a.revenue_30d;
    });
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
