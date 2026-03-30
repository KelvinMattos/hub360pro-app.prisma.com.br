<template>
    <AppLayout>
        <div class="p-8">
            <!-- Header with Period Filter -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        DRE <span class="bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent">Executivo</span>
                    </h1>
                    <p class="text-slate-400 mt-2 font-medium text-lg">Demonstração do Resultado do Exercício - {{ indicators.period.label }}</p>
                </div>
                
                <div class="flex items-center gap-3 bg-white p-2 rounded-2xl border border-slate-200 shadow-premium">
                    <select v-model="filter.month" class="bg-transparent border-none text-sm font-bold text-slate-700 focus:ring-0 cursor-pointer">
                        <option v-for="(m, i) in months" :key="i" :value="i + 1">{{ m }}</option>
                    </select>
                    <div class="w-px h-4 bg-slate-200"></div>
                    <select v-model="filter.year" class="bg-transparent border-none text-sm font-bold text-slate-700 focus:ring-0 cursor-pointer">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button @click="applyFilter" class="ml-2 p-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition-colors">
                        <i class="fa-solid fa-sync"></i>
                    </button>
                </div>
            </div>

            <!-- DRE Structure Card -->
            <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-premium mb-10">
                <div class="p-8 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="text-xl font-bold text-slate-900">Análise de Performance</h3>
                    <div class="px-3 py-1 bg-emerald-50 text-emerald-600 text-[10px] font-black rounded-lg border border-emerald-100 uppercase tracking-widest">
                        Dados Reais
                    </div>
                </div>

                <div class="p-0">
                    <!-- Line Items -->
                    <DreItem label="(+) Receita Bruta de Vendas" :value="indicators.gross_revenue" type="primary" />
                    
                    <div class="px-8 py-4 bg-slate-50 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">
                        Custos Variáveis
                    </div>
                    
                    <DreItem label="(-) Custo de Mercadorias (CMV)" :value="-indicators.cost_products" />
                    <DreItem label="(-) Taxas e Comissões" :value="-indicators.cost_fees" />
                    <DreItem label="(-) Impostos e Tributos" :value="-indicators.cost_taxes" />
                    
                    <DreItem label="(=) Margem de Contribuição" :value="indicators.contribution_margin" type="highlight" />

                    <div class="px-8 py-4 bg-slate-50 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">
                        Custos Fixos & Operacionais
                    </div>
                    
                    <DreItem label="(-) Despesas Operacionais Fixas" :value="-indicators.fixed_costs" />
                    
                    <div class="p-8 bg-gradient-to-r from-emerald-600/5 to-teal-600/5 flex justify-between items-center border-t border-emerald-500/10">
                        <div class="flex flex-col">
                            <span class="text-lg font-black text-slate-900 uppercase tracking-wider">(=) Lucro Líquido Real</span>
                            <span class="text-xs text-emerald-600 font-bold">Margem Líquida: {{ indicators.margin_percent }}%</span>
                        </div>
                        <span class="text-4xl font-black text-emerald-600">R$ {{ formatCurrency(indicators.net_profit) }}</span>
                    </div>
                </div>
            </div>

            <!-- Tabular Comparison (Mercado Turbo Style) -->
            <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-premium mb-10">
                <div class="p-8 border-b border-slate-100">
                    <h3 class="text-xl font-bold text-slate-900">Evolução Mensal (DRE Colunar)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest min-w-[200px]">Indicador</th>
                                <th v-for="month in history" :key="month.period.label" class="p-6 text-[10px] font-black text-slate-900 uppercase tracking-widest text-right">
                                    {{ month.period.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr>
                                <td class="p-6 text-sm font-bold text-slate-700">Receita Bruta</td>
                                <td v-for="month in history" :key="month.period.label" class="p-6 text-sm font-black text-slate-900 text-right">
                                    R$ {{ formatCurrency(month.gross_revenue) }}
                                </td>
                            </tr>
                            <tr>
                                <td class="p-6 text-sm font-medium text-slate-500">CMV</td>
                                <td v-for="month in history" :key="month.period.label" class="p-6 text-sm font-bold text-red-500 text-right">
                                    - R$ {{ formatCurrency(month.cost_products) }}
                                </td>
                            </tr>
                            <tr>
                                <td class="p-6 text-sm font-medium text-slate-500">Taxas & Impostos</td>
                                <td v-for="month in history" :key="month.period.label" class="p-6 text-sm font-bold text-red-500 text-right">
                                    - R$ {{ formatCurrency(month.cost_fees + month.cost_taxes) }}
                                </td>
                            </tr>
                            <tr class="bg-emerald-50/30">
                                <td class="p-6 text-sm font-black text-emerald-700 uppercase">Margem Contr.</td>
                                <td v-for="month in history" :key="month.period.label" class="p-6 text-sm font-black text-emerald-600 text-right">
                                    R$ {{ formatCurrency(month.contribution_margin) }}
                                </td>
                            </tr>
                            <tr>
                                <td class="p-6 text-sm font-medium text-slate-500">Custos Fixos</td>
                                <td v-for="month in history" :key="month.period.label" class="p-6 text-sm font-bold text-slate-700 text-right">
                                    - R$ {{ formatCurrency(month.fixed_costs) }}
                                </td>
                            </tr>
                            <tr class="bg-slate-900">
                                <td class="p-6 text-sm font-black text-white uppercase">Lucro Líquido</td>
                                <td v-for="month in history" :key="month.period.label" class="p-6 text-sm font-black text-emerald-400 text-right">
                                    R$ {{ formatCurrency(month.net_profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Financial Health Indicators -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-premium">
                    <h4 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-6">Eficiência Operacional</h4>
                    <div class="space-y-4">
                        <div class="flex justify-between items-end">
                            <span class="text-sm font-medium text-slate-600">Margem Bruta</span>
                            <span class="text-slate-900 font-bold">{{ indicators.margin_percent }}%</span>
                        </div>
                        <div class="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500" :style="{ width: indicators.margin_percent + '%' }"></div>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-premium">
                    <h4 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-6">Break Even Point</h4>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-blue-50 flex items-center justify-center text-blue-600">
                            <i class="fa-solid fa-flag-checkered text-xl"></i>
                        </div>
                        <div>
                            <p class="text-slate-900 font-bold">R$ {{ formatCurrency(indicators.fixed_costs * 2.5) }}</p>
                            <p class="text-xs text-slate-400 font-medium">Faturamento necessário p/ lucro zero</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-premium">
                    <h4 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-6">Pedidos Processados</h4>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-50 flex items-center justify-center text-indigo-600">
                            <i class="fa-solid fa-cart-shopping text-xl"></i>
                        </div>
                        <div>
                            <p class="text-slate-900 font-bold">{{ indicators.order_count }} envios</p>
                            <p class="text-xs text-slate-400 font-medium">Volume total no período selecionado</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DreItem from '@/Components/DreItem.vue';

const props = defineProps({
    indicators: Object,
    history: Array,
    filters: Object
});

const filter = reactive({
    month: props.filters.month,
    year: props.filters.year
});

const months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const years = [2024, 2025, 2026];

const applyFilter = () => {
    router.visit(route('financial.dre'), {
        data: { month: filter.month, year: filter.year },
        preserveState: true
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
