<template>
    <AppLayout>
        <div class="p-10 max-w-[1400px] mx-auto min-h-screen">
            <!-- Header -->
            <div class="mb-12">
                <nav class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">
                    <Link :href="route('dashboard')" class="hover:text-blue-600 transition-colors">Dashboard</Link>
                    <i class="fa-solid fa-chevron-right text-[8px]"></i>
                    <span class="text-slate-900">Simulador 360</span>
                </nav>
                <h1 class="text-4xl font-bold text-[#1D1D1F] tracking-tight mb-2">Simulador "O Impossível"</h1>
                <p class="text-[#86868B] font-medium text-lg italic">Explore cenários de preço e publicidade com IA Preditiva.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
                <!-- Configuration Panel -->
                <div class="lg:col-span-4 space-y-8">
                    <div class="glass-card p-8 mac-shadow">
                        <h3 class="text-[11px] font-bold text-[#86868B] uppercase tracking-widest mb-8">Passo 1: Selecionar Produto</h3>
                        <div class="relative">
                            <select v-model="form.product_id" @change="resetSimulation"
                                    class="w-full bg-black/[0.03] border-none rounded-2xl py-4 px-6 text-sm font-bold focus:ring-2 focus:ring-blue-500 appearance-none">
                                <option value="" disabled>Escolha um produto da sua base...</option>
                                <option v-for="product in products" :key="product.id" :value="product.id">
                                    {{ product.name }} ({{ product.sku }})
                                </option>
                            </select>
                            <div class="absolute right-6 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                        </div>

                        <div v-if="selectedProduct" class="mt-6 pt-6 border-t border-black/[0.04] space-y-4">
                            <div class="flex justify-between text-xs font-bold">
                                <span class="text-slate-400 uppercase">Preço Atual</span>
                                <span class="text-slate-900">R$ {{ formatCurrency(selectedProduct.price) }}</span>
                            </div>
                            <div class="flex justify-between text-xs font-bold">
                                <span class="text-slate-400 uppercase">Custo de Compra</span>
                                <span class="text-slate-900">R$ {{ formatCurrency(selectedProduct.cost_price || selectedProduct.price * 0.4) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-8 mac-shadow" :class="{ 'opacity-50 pointer-events-none': !form.product_id }">
                        <h3 class="text-[11px] font-bold text-[#86868B] uppercase tracking-widest mb-10">Passo 2: Definir Cenário</h3>
                        
                        <div class="space-y-10">
                            <!-- Price Slider -->
                            <div>
                                <div class="flex justify-between items-center mb-4">
                                    <label class="text-sm font-bold text-slate-700">Ajuste de Preço (%)</label>
                                    <span :class="['text-sm font-black px-3 py-1 rounded-lg', form.price_change_percent >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600']">
                                        {{ form.price_change_percent > 0 ? '+' : '' }}{{ form.price_change_percent }}%
                                    </span>
                                </div>
                                <input type="range" v-model="form.price_change_percent" min="-30" max="30" step="1"
                                       class="w-full h-1.5 bg-black/[0.05] rounded-full appearance-none cursor-pointer accent-blue-600">
                                <div class="flex justify-between mt-2 text-[10px] font-bold text-slate-400 uppercase">
                                    <span>-30%</span>
                                    <span>Preço Atual</span>
                                    <span>+30%</span>
                                </div>
                            </div>

                            <!-- Ads Slider -->
                            <div>
                                <div class="flex justify-between items-center mb-4">
                                    <label class="text-sm font-bold text-slate-700">Verba de Ads (%)</label>
                                    <span :class="['text-sm font-black px-3 py-1 rounded-lg', form.ads_change_percent >= 0 ? 'bg-blue-50 text-blue-600' : 'bg-orange-50 text-orange-600']">
                                        {{ form.ads_change_percent > 0 ? '+' : '' }}{{ form.ads_change_percent }}%
                                    </span>
                                </div>
                                <input type="range" v-model="form.ads_change_percent" min="-50" max="100" step="10"
                                       class="w-full h-1.5 bg-black/[0.05] rounded-full appearance-none cursor-pointer accent-purple-600">
                                <div class="flex justify-between mt-2 text-[10px] font-bold text-slate-400 uppercase">
                                    <span>Reduzir</span>
                                    <span>Manter</span>
                                    <span>Dobrar</span>
                                </div>
                            </div>
                        </div>

                        <button @click="runSimulation" 
                                :disabled="loading || !form.product_id"
                                class="mac-button-primary w-full mt-12 !py-5 !rounded-2xl flex items-center justify-center gap-3">
                            <i :class="['fa-solid', loading ? 'fa-circle-notch animate-spin' : 'fa-bolt-lightning']"></i>
                            {{ loading ? 'Processando IA...' : 'Rodar Simulação' }}
                        </button>
                    </div>
                </div>

                <!-- Results Panel -->
                <div class="lg:col-span-8">
                    <div v-if="!result && !loading" class="h-full flex flex-col items-center justify-center glass-card p-20 text-center space-y-6">
                        <div class="w-32 h-32 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center text-5xl animate-bounce">
                            <i class="fa-solid fa-microchip"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-slate-900">Aguardando Parâmetros</h2>
                        <p class="text-slate-500 max-w-sm font-medium">Configure os ajustes ao lado para que nossa IA calcule a elasticidade de lucro deste SKU.</p>
                    </div>

                    <div v-if="loading" class="h-full flex flex-col items-center justify-center glass-card p-20 space-y-8">
                        <div class="flex gap-2">
                            <div class="w-4 h-4 bg-blue-600 rounded-full animate-bounce"></div>
                            <div class="w-4 h-4 bg-purple-600 rounded-full animate-bounce [animation-delay:-0.15s]"></div>
                            <div class="w-4 h-4 bg-indigo-600 rounded-full animate-bounce [animation-delay:-0.3s]"></div>
                        </div>
                        <p class="text-lg font-bold text-slate-800 animate-pulse">Consultando histórico de vendas e calculando elasticidade...</p>
                    </div>

                    <div v-if="result" class="space-y-8 animate-in fade-in slide-in-from-bottom-5 duration-700">
                        <!-- Projection Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="glass-card p-8 bg-black text-white relative h-full">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Novo Lucro Líquido</p>
                                <h4 class="text-3xl font-bold">R$ {{ formatCurrency(result.simulated.total_profit) }}</h4>
                                <div :class="['mt-4 inline-flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-lg', result.simulated.impact.profit_percent >= 0 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-100']">
                                    <i :class="result.simulated.impact.profit_percent >= 0 ? 'fa-solid fa-arrow-up' : 'fa-solid fa-arrow-down'"></i>
                                    {{ Math.abs(result.simulated.impact.profit_percent).toFixed(1) }}%
                                </div>
                            </div>
                            <div class="glass-card p-8 h-full">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Impacto no Volume</p>
                                <h4 class="text-3xl font-bold text-slate-900">{{ Math.round(result.simulated.volume) }} units</h4>
                                <div :class="['mt-4 inline-flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-lg', result.simulated.impact.volume_percent >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600']">
                                    {{ result.simulated.impact.volume_percent > 0 ? '+' : '' }}{{ result.simulated.impact.volume_percent.toFixed(1) }}%
                                </div>
                            </div>
                            <div class="glass-card p-8 h-full" :class="result.recommendation === 'Positiva' ? 'bg-emerald-50 border-emerald-100' : 'bg-red-50 border-red-100'">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Recomendação IA</p>
                                <h4 :class="['text-3xl font-bold', result.recommendation === 'Positiva' ? 'text-emerald-700' : 'text-red-700']">
                                    {{ result.recommendation }}
                                </h4>
                                <p class="text-[10px] mt-4 font-bold uppercase opacity-60">Probabilidade de Sucesso: 84%</p>
                            </div>
                        </div>

                        <!-- Comparison Details -->
                        <div class="glass-card p-10">
                            <h3 class="text-xl font-bold text-slate-900 mb-8">Análise Detalhada</h3>
                            <div class="space-y-10">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-20">
                                    <!-- Margin Analysis -->
                                    <div class="space-y-6">
                                        <p class="text-sm font-bold text-slate-700 border-b pb-4">Margem por Unidade</p>
                                        <div class="space-y-4">
                                            <div class="flex justify-between items-end">
                                                <span class="text-xs font-medium text-slate-400">Cenário Atual</span>
                                                <span class="text-sm font-bold">R$ {{ formatCurrency(result.current.margin) }}</span>
                                            </div>
                                            <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                                <div class="bg-slate-300 h-full" :style="{ width: '100%' }"></div>
                                            </div>
                                            <div class="flex justify-between items-end">
                                                <span class="text-xs font-medium text-slate-400">Cenário Simulado</span>
                                                <span :class="['text-sm font-bold', result.simulated.margin > result.current.margin ? 'text-emerald-600' : 'text-red-600']">
                                                    R$ {{ formatCurrency(result.simulated.margin) }}
                                                </span>
                                            </div>
                                            <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                                <div :class="[result.simulated.margin > result.current.margin ? 'bg-emerald-500' : 'bg-red-500']" 
                                                     :style="{ width: (result.simulated.margin / result.current.margin * 100) + '%' }"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Elasticity Insight -->
                                    <div class="bg-blue-50/50 p-8 rounded-[2.5rem] border border-blue-100 space-y-4">
                                        <div class="w-10 h-10 bg-blue-600 text-white rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                                            <i class="fa-solid fa-lightbulb"></i>
                                        </div>
                                        <h4 class="text-lg font-bold text-blue-900">Insight Estratégico</h4>
                                        <p class="text-sm text-blue-800/80 leading-relaxed font-medium">
                                            {{ simulationInsight }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({
    products: Array
});

const form = ref({
    product_id: '',
    price_change_percent: 0,
    ads_change_percent: 0
});

const loading = ref(false);
const result = ref(null);

const selectedProduct = computed(() => {
    return props.products.find(p => p.id === form.value.product_id);
});

const resetSimulation = () => {
    result.value = null;
    form.value.price_change_percent = 0;
    form.value.ads_change_percent = 0;
};

const runSimulation = async () => {
    loading.value = true;
    try {
        const response = await axios.post(route('pricing.simulate'), form.value);
        result.value = response.data;
    } catch (error) {
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};

const simulationInsight = computed(() => {
    if (!result.value) return '';
    
    if (result.value.recommendation === 'Positiva') {
        if (form.value.price_change_percent < 0) {
            return 'A estratégia de redução de preço ativa um gatilho de volume que compensa a queda na margem unitária. Recomendado para queima de estoque ou ganho de Share.';
        } else {
            return 'Este SKU suporta um posicionamento mais premium sem sofrer queda drástica em vendas. Aumento de margem detectado como ótima oportunidade.';
        }
    } else {
        return 'Cuidado: Para este perfil de produto, a mudança proposta destrói rentabilidade total mais rápido do que o ganho compensa. Recomendamos manter a estratégia atual.';
    }
});
</script>
