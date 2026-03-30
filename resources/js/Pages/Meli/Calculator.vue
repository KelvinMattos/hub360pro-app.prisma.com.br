<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="mb-10">
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-calculator text-[#FFE600]"></i> 
                    Simulador <span class="bg-gradient-to-r from-yellow-400 to-amber-500 bg-clip-text text-transparent">360 PRO</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium text-lg italic">Defina o preço de venda ideal com precisão milimétrica.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <!-- Left Column: Inputs -->
                <div class="lg:col-span-7 space-y-6">
                    <!-- Product Costs -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl relative overflow-hidden group">
                        <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                            <i class="fa-solid fa-box-open text-6xl text-blue-500"></i>
                        </div>
                        <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                             Custos Direto do Produto
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-1">Custo de Aquisição (CMV)</label>
                                <div class="relative group/input">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">R$</span>
                                    <input type="number" v-model="form.cost" step="0.01" class="w-full bg-slate-900 border border-slate-800 focus:border-blue-500 text-slate-900 rounded-2xl py-4 pl-12 pr-4 font-black text-xl transition-all outline-none shadow-inner">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-1">Custos Operacionais Unit.</label>
                                <div class="relative group/input">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">R$</span>
                                    <input type="number" v-model="form.operational" step="0.01" class="w-full bg-slate-900 border border-slate-800 focus:border-blue-500 text-slate-900 rounded-2xl py-4 pl-12 pr-4 font-black text-xl transition-all outline-none shadow-inner">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Marketplace Fees -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl relative overflow-hidden group">
                        <div class="absolute right-0 top-0 p-6 opacity-5 group-hover:opacity-10 transition-opacity">
                            <i class="fa-solid fa-percent text-6xl text-yellow-500"></i>
                        </div>
                        <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                             Taxas & Comissões (Mercado Livre)
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-1">Comissão ML (%)</label>
                                <div class="relative">
                                    <input type="number" v-model="form.fee_percent" class="w-full bg-slate-900 border border-slate-800 focus:border-blue-500 text-slate-900 rounded-2xl py-4 pl-4 pr-12 font-black text-xl transition-all outline-none">
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">%</span>
                                </div>
                                <div class="flex gap-2 mt-2">
                                    <button @click="form.fee_percent = 11" class="text-[9px] bg-slate-800 hover:bg-slate-700 text-slate-400 px-3 py-1.5 rounded-lg border border-slate-700 font-black tracking-tighter transition-all">CLÁSSICO (11%)</button>
                                    <button @click="form.fee_percent = 16" class="text-[9px] bg-slate-800 hover:bg-slate-700 text-slate-400 px-3 py-1.5 rounded-lg border border-slate-700 font-black tracking-tighter transition-all">PREMIUM (16%)</button>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-1">Imposto Fiscal (%)</label>
                                <div class="relative">
                                    <input type="number" v-model="form.tax_percent" class="w-full bg-slate-900 border border-slate-800 focus:border-blue-500 text-slate-900 rounded-2xl py-4 pl-4 pr-12 font-black text-xl transition-all outline-none">
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">%</span>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-1">Frete / Taxa Fixa</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">R$</span>
                                    <input type="number" v-model="form.fixed_costs" class="w-full bg-slate-900 border border-slate-800 focus:border-blue-500 text-slate-900 rounded-2xl py-4 pl-12 pr-4 font-black text-xl transition-all outline-none">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Desired Margin -->
                    <div class="bg-gradient-to-br from-emerald-600 to-teal-700 rounded-3xl p-8 shadow-2xl relative overflow-hidden group">
                        <div class="absolute right-0 top-0 p-6 opacity-20 group-hover:opacity-30 transition-opacity">
                            <i class="fa-solid fa-bullseye text-6xl text-slate-900"></i>
                        </div>
                        <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                             Margem de Lucro Desejada (%)
                        </h3>
                        <div class="flex items-center gap-8">
                            <div class="flex-1">
                                <input type="range" v-model="form.margin_percent" min="0" max="100" class="w-full h-3 bg-white/20 rounded-full appearance-none cursor-pointer accent-white shadow-lg">
                                <div class="flex justify-between mt-3 text-[10px] font-black text-slate-900/50 uppercase tracking-widest px-1">
                                    <span>Conservador</span>
                                    <span>Agressivo</span>
                                </div>
                            </div>
                            <div class="relative w-32 shrink-0">
                                <input type="number" v-model="form.margin_percent" class="w-full bg-white/10 border border-white/20 text-slate-900 rounded-2xl py-4 pl-4 pr-12 font-black text-2xl transition-all outline-none focus:bg-white/20">
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-900/50 font-black">%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Results -->
                <div class="lg:col-span-5">
                    <div class="sticky top-8 space-y-6">
                        <!-- Suggested Price Display -->
                        <div class="bg-[#111827] border border-slate-700/50 rounded-3xl p-10 text-center shadow-2xl relative overflow-hidden group">
                            <div class="absolute inset-0 bg-gradient-to-b from-blue-500/5 to-transparent"></div>
                            
                            <p class="text-slate-500 uppercase text-[10px] font-black tracking-[0.3em] mb-4">Preço de Venda Sugerido</p>
                            <div class="text-6xl font-black text-slate-900 mb-4 tracking-tighter">
                                R$ {{ formatCurrency(price) }}
                            </div>
                            <div v-if="price > 0" class="flex items-center justify-center gap-2">
                                <span class="text-xs font-bold text-slate-400">PARA UM LUCRO LÍQUIDO DE </span>
                                <span class="text-emerald-400 font-black text-lg">R$ {{ formatCurrency(profitValue) }}</span>
                            </div>
                        </div>

                        <!-- Financial Breakdown (DRE) -->
                        <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                            <div class="p-6 bg-black/20 border-b border-slate-800 flex justify-between items-center">
                                <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">Demonstrativo (DRE Unitário)</h4>
                                <i class="fa-solid fa-list-check text-blue-500"></i>
                            </div>
                            
                            <div class="p-8 space-y-4 font-mono">
                                <div class="flex justify-between text-slate-900 font-black text-lg pb-3 border-b border-slate-800/50 tracking-tighter">
                                    <span>RECEITA BRUTA</span>
                                    <span>R$ {{ formatCurrency(price) }}</span>
                                </div>
                                
                                <div class="flex justify-between text-[11px] text-red-400 font-bold uppercase">
                                    <span>(-) CUSTO CMV + OPS</span>
                                    <span>- R$ {{ formatCurrency(parseFloat(form.cost || 0) + parseFloat(form.operational || 0)) }}</span>
                                </div>

                                <div class="flex justify-between text-[11px] text-red-400 font-bold uppercase">
                                    <span>(-) COMISSÃO ML ({{ form.fee_percent }}%)</span>
                                    <span>- R$ {{ formatCurrency(price * (form.fee_percent/100)) }}</span>
                                </div>

                                <div class="flex justify-between text-[11px] text-red-500/80 font-bold uppercase">
                                    <span>(-) IMPOSTOS ({{ form.tax_percent }}%)</span>
                                    <span>- R$ {{ formatCurrency(price * (form.tax_percent/100)) }}</span>
                                </div>

                                <div class="flex justify-between text-[11px] text-red-500/80 font-bold uppercase">
                                    <span>(-) FRETE / TAXAS FIXAS</span>
                                    <span>- R$ {{ formatCurrency(form.fixed_costs) }}</span>
                                </div>

                                <div class="pt-4 mt-2 border-t border-slate-800 flex justify-between items-center text-emerald-400 font-black">
                                    <span class="text-xs uppercase tracking-widest">LUCRO LÍQUIDO</span>
                                    <span class="text-2xl tracking-tighter">R$ {{ formatCurrency(profitValue) }}</span>
                                </div>
                                
                                <div class="flex justify-between items-center text-slate-500">
                                    <span class="text-[10px] font-bold uppercase tracking-widest">Mark-up Praticado</span>
                                    <span class="text-sm font-black">{{ form.cost > 0 ? (price / form.cost).toFixed(2) : '0' }}x</span>
                                </div>
                            </div>
                        </div>

                        <!-- Alerts -->
                        <div v-if="price < 79 && price > 0" class="bg-blue-600/10 border border-blue-500/20 rounded-2xl p-6 flex gap-4 items-start shadow-xl animate-pulse">
                            <i class="fa-solid fa-circle-info text-blue-400 mt-1"></i>
                            <div class="text-[11px] text-blue-300 font-medium leading-relaxed">
                                <strong class="text-slate-900 block mb-1 uppercase tracking-widest">Aviso Regulatório</strong>
                                Abaixo de R$ 79,00 o Mercado Livre aplica taxas fixas por venda em vez de coparticipação em frete. Verifique o campo 'Frete/Taxa Fixa'.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const form = reactive({
    cost: '',
    operational: 0,
    fee_percent: 11,
    tax_percent: 4,
    fixed_costs: 6.00,
    margin_percent: 20,
});

const price = computed(() => {
    const cost = parseFloat(form.cost) || 0;
    const operational = parseFloat(form.operational) || 0;
    const fixedCosts = parseFloat(form.fixed_costs) || 0;
    
    // Divisor = 1 - (Comissão% + Imposto% + Margem%)
    const deductionsPercent = (parseFloat(form.fee_percent) + parseFloat(form.tax_percent) + parseFloat(form.margin_percent)) / 100;

    if (deductionsPercent >= 1) return 0;

    // Formula: (Custos Diretos + Custos Fixos) / (1 - Deduções%)
    const calculatedPrice = (cost + operational + fixedCosts) / (1 - deductionsPercent);
    
    return calculatedPrice > 0 ? calculatedPrice : 0;
});

const profitValue = computed(() => {
    if (price.value === 0) return 0;
    const cost = parseFloat(form.cost) || 0;
    const operational = parseFloat(form.operational) || 0;
    const fixedCosts = parseFloat(form.fixed_costs) || 0;
    const deductions = price.value * ((parseFloat(form.fee_percent) + parseFloat(form.tax_percent)) / 100);
    
    return price.value - cost - operational - fixedCosts - deductions;
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>

<style scoped>
input[type=number]::-webkit-inner-spin-button, 
input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
</style>
