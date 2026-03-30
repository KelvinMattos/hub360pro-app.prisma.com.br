<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        War <span class="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">Room</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Modo Espião: Monitore sua concorrência em tempo real.</p>
                </div>
                
                <div class="flex gap-3 w-full md:w-auto">
                    <div class="relative flex-1 md:w-96">
                        <input 
                            v-model="query" 
                            @keyup.enter="search"
                            type="text" 
                            placeholder="Termo de busca ou Link do Produto..." 
                            class="w-full bg-white shadow-premium border border-slate-200 text-slate-900 rounded-2xl pl-12 pr-4 py-3 focus:outline-none focus:border-red-500 transition-all font-medium"
                        >
                        <i class="fa-solid fa-bolt absolute left-4 top-1/2 -translate-y-1/2 text-red-500"></i>
                    </div>
                </div>
            </div>

            <!-- Spy Controls & Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                <div class="md:col-span-1 bg-white shadow-premium border border-slate-200 p-6 rounded-3xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-4">Seu Preço Atual</p>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">R$</span>
                        <input 
                            v-model="myPrice"
                            type="number" 
                            class="w-full bg-slate-900 border border-slate-700 text-slate-900 rounded-xl pl-12 pr-4 py-3 focus:outline-none focus:border-blue-500 font-black text-xl"
                        >
                    </div>
                    <button @click="search" :disabled="isLoading" class="w-full mt-4 bg-red-600 hover:bg-red-500 text-slate-900 py-3 rounded-xl font-black uppercase tracking-widest text-xs transition-all flex items-center justify-center gap-2 shadow-lg shadow-red-600/20 active:scale-95 disabled:opacity-50">
                        <i class="fa-solid fa-radar" :class="{ 'fa-spin': isLoading }"></i>
                        {{ isLoading ? 'Rastreando...' : 'Iniciar Varredura' }}
                    </button>
                </div>

                <div v-if="stats" class="md:col-span-3 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-slate-900/50 border border-slate-800 p-6 rounded-3xl flex flex-col justify-center">
                        <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Menor Preço Mercado</p>
                        <h3 class="text-3xl font-black text-slate-900">R$ {{ formatCurrency(stats.min_price) }}</h3>
                    </div>

                    <div :class="['p-6 rounded-3xl flex flex-col justify-center border transition-colors', statusClasses[stats.status].bg]">
                        <p class="text-slate-900/50 text-[10px] font-black uppercase tracking-[0.2em] mb-1">GAP de Competitividade</p>
                        <h3 class="text-3xl font-black text-slate-900">{{ stats.gap }}%</h3>
                        <p class="text-[10px] font-black uppercase mt-1 text-slate-900/80 tracking-widest">{{ statusClasses[stats.status].label }}</p>
                    </div>

                    <div class="bg-indigo-600/10 border border-indigo-500/20 p-6 rounded-3xl flex flex-col justify-center">
                        <p class="text-indigo-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Status de Vitrine</p>
                        <h3 class="text-2xl font-black text-slate-900 flex items-center gap-2">
                             <i :class="['fa-solid', stats.status === 'winning' ? 'fa-crown text-yellow-400' : 'fa-hand-fist text-slate-400']"></i>
                             {{ stats.status === 'winning' ? 'Líder Absoluto' : 'Em Disputa' }}
                        </h3>
                    </div>
                </div>
            </div>

            <!-- Competitors List -->
            <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                <div class="bg-black/20 p-6 border-b border-slate-800">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Top 10 Concorrentes (Relevância)</h3>
                </div>

                <div class="p-0">
                    <div v-for="item in competitors" :key="item.id" class="p-6 flex flex-col md:flex-row items-center gap-6 hover:bg-red-500/5 transition-all border-b border-slate-800/50 group">
                        <div class="w-16 h-16 bg-white rounded-xl overflow-hidden p-1 shrink-0 shadow-lg">
                            <img :src="item.thumbnail" class="w-full h-full object-contain">
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <a :href="item.permalink" target="_blank" class="text-slate-900 font-bold text-sm hover:text-red-400 transition-colors line-clamp-2 leading-tight mb-2">
                                {{ item.title }}
                            </a>
                            <div class="flex items-center gap-3">
                                <span class="text-[9px] bg-slate-800 px-2 py-0.5 rounded font-black text-slate-400 uppercase tracking-widest border border-slate-700">REP: {{ item.reputation }}</span>
                                <span v-if="item.free_shipping" class="text-[9px] bg-emerald-500/10 px-2 py-0.5 rounded font-black text-emerald-400 uppercase tracking-widest border border-emerald-500/20">Frete Grátis</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-8 px-6">
                            <div class="text-right">
                                <p class="text-[9px] text-slate-500 font-black uppercase tracking-widest mb-1">Vendas Est.</p>
                                <p class="text-slate-900 font-black">{{ item.sold_quantity }}+</p>
                            </div>
                            <div class="text-right min-w-[120px]">
                                <p class="text-[9px] text-slate-500 font-black uppercase tracking-widest mb-1">Preço Público</p>
                                <p class="text-2xl font-black text-slate-900 tracking-tighter">R$ {{ formatCurrency(item.price) }}</p>
                            </div>
                        </div>

                        <div class="flex-shrink-0">
                           <div v-if="myPrice > 0" :class="['px-3 py-1 rounded-lg font-black text-[10px] uppercase tracking-tighter', item.price < myPrice ? 'bg-red-500/10 text-red-500 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20']">
                                {{ item.price < myPrice ? 'Mais Barato' : 'Mais Caro' }}
                           </div>
                        </div>
                    </div>

                    <div v-if="competitors.length === 0" class="p-20 text-center text-slate-600">
                        <i class="fa-solid fa-satellite-dish text-6xl mb-6 opacity-20"></i>
                        <p class="font-black uppercase tracking-[0.2em] text-xs">Aguardando comando de rastreio...</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const query = ref('');
const myPrice = ref(0);
const competitors = ref([]);
const stats = ref(null);
const isLoading = ref(false);

const statusClasses = {
    winning: { bg: 'bg-emerald-600 border-emerald-500 shadow-lg shadow-emerald-500/20', label: 'Preço Vencedor', labelColor: 'text-emerald-100' },
    fighting: { bg: 'bg-blue-600 border-blue-500 shadow-lg shadow-blue-500/20', label: 'Competitivo', labelColor: 'text-blue-100' },
    losing: { bg: 'bg-red-600 border-red-500 shadow-lg shadow-red-500/20', label: 'Muito Caro', labelColor: 'text-red-100' },
    neutral: { bg: 'bg-slate-800 border-slate-700', label: 'Sem Parâmetro', labelColor: 'text-slate-400' }
};

const search = async () => {
    if (!query.value) return;
    
    isLoading.value = true;
    try {
        const response = await axios.get(route('meli.war_room.search'), {
            params: { q: query.value, my_price: myPrice.value }
        });
        competitors.value = response.data.competitors;
        stats.value = response.data.stats;
    } catch (e) {
        console.error(e);
        alert('Erro ao rastrear concorrentes.');
    } finally {
        isLoading.value = false;
    }
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
