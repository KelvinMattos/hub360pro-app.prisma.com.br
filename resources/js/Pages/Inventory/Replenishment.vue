<template>
    <AppLayout title="Inteligência de Reposição">
        <div class="p-8 max-w-7xl mx-auto">
            <div class="mb-12 animate-in fade-in slide-in-from-top-4 duration-700">
                <h1 class="text-5xl font-black text-slate-900 tracking-tightest">
                    Inteligência de <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Reposição</span>
                </h1>
                <p class="text-slate-400 mt-3 font-semibold text-lg">Evite rupturas. Antecipe a compra com base no seu ritmo de vendas.</p>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
                <div class="bg-white/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-white/60 shadow-premium">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-4">Investimento Necessário</p>
                    <h3 class="text-4xl font-black text-slate-900 tracking-tighter">R$ {{ stats.total_investment.toLocaleString() }}</h3>
                </div>
                <div class="bg-white/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-white/60 shadow-premium">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-4">Produtos em Alerta</p>
                    <h3 class="text-4xl font-black text-orange-500 tracking-tighter">{{ stats.critical_count }} SKUs</h3>
                </div>
                <div class="bg-slate-900 p-8 rounded-[2.5rem] border border-slate-800 shadow-premium">
                    <p class="text-white/40 text-[10px] font-black uppercase tracking-[0.2em] mb-4">Receita em Risco (30d)</p>
                    <h3 class="text-4xl font-black text-white tracking-tighter">R$ {{ stats.lost_money.toLocaleString() }}</h3>
                </div>
            </div>

            <!-- Replenishment Table -->
            <div class="bg-white/60 backdrop-blur-3xl rounded-[3rem] border border-white/80 shadow-premium overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-black/[0.02]">
                        <tr>
                            <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Produto / SKU</th>
                            <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Estoque Atual</th>
                            <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Giro (Dia)</th>
                            <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Duração</th>
                            <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right px-12">Sugestão de Compra</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/[0.05]">
                        <tr v-for="item in inventoryData" :key="item.id" class="group hover:bg-white/40 transition-colors">
                            <td class="px-8 py-6">
                                <p class="font-black text-slate-900 truncate max-w-xs">{{ item.name }}</p>
                                <p class="text-[10px] font-bold text-blue-600 mt-1 uppercase">{{ item.sku }}</p>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <span class="bg-slate-100 px-3 py-1 rounded-lg text-xs font-black text-slate-600">{{ item.stock }}</span>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <span class="text-sm font-bold text-slate-900">{{ item.velocity }}</span>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <div :class="[
                                    'inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest',
                                    item.days_remaining < 7 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600'
                                ]">
                                    <i class="fa-solid fa-clock"></i> {{ item.days_remaining }} dias
                                </div>
                            </td>
                            <td class="px-8 py-6 text-right px-12">
                                <div v-if="item.investment_needed > 0" class="inline-block text-right">
                                    <p class="text-lg font-black text-slate-900">+ {{ Math.ceil(item.investment_needed / 10) }} un</p>
                                    <p class="text-[10px] font-bold text-orange-500 uppercase">R$ {{ item.investment_needed.toLocaleString() }}</p>
                                </div>
                                <span v-else class="text-emerald-500 font-bold text-xs uppercase tracking-widest">Estoque OK</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    inventoryData: Array,
    stats: Object
});
</script>

<style scoped>
.shadow-premium {
    box-shadow: 0 10px 40px -10px rgba(0,0,0,0.05);
}
.tracking-tightest {
    letter-spacing: -0.05em;
}
</style>
