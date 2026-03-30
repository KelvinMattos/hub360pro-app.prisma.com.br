<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="mb-10 flex justify-between items-end">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Price <span class="bg-gradient-to-r from-orange-400 to-red-500 bg-clip-text text-transparent">Race</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Automação inteligente de preços baseada na concorrência.</p>
                </div>
            </div>

            <!-- Rules Table -->
            <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-black/20 text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-800">
                            <th class="p-6">Produto</th>
                            <th class="p-6">Estratégia</th>
                            <th class="p-6 text-center">Limites (Min/Max)</th>
                            <th class="p-6 text-center">Status</th>
                            <th class="p-6 text-right">Última Execução</th>
                            <th class="p-6 text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="rule in rules.data" :key="rule.id" class="hover:bg-orange-500/5 transition-all group">
                            <td class="p-6">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-slate-800 rounded-lg flex items-center justify-center border border-slate-700">
                                        <i class="fa-solid fa-bolt text-orange-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-slate-900 font-bold text-sm">{{ rule.product?.title || 'Produto Removido' }}</p>
                                        <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">{{ rule.marketplace_item_id }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-6">
                                <span class="bg-slate-800 text-slate-300 text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded-full border border-slate-700">
                                    {{ formatStrategy(rule.strategy) }}
                                </span>
                            </td>
                            <td class="p-6 text-center">
                                <p class="text-slate-900 font-mono text-xs font-black">
                                    R$ {{ formatCurrency(rule.min_price) }} - R$ {{ formatCurrency(rule.max_price) }}
                                </p>
                            </td>
                            <td class="p-6 text-center">
                                <button @click="toggleRule(rule)" :class="[rule.is_active ? 'bg-emerald-500/10 text-emerald-500' : 'bg-red-500/10 text-red-500']" class="px-4 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border border-current transition-all">
                                    {{ rule.is_active ? 'Ativo' : 'Pausado' }}
                                </button>
                            </td>
                            <td class="p-6 text-right">
                                <p class="text-slate-400 text-xs font-medium">{{ formatDate(rule.last_applied_at) }}</p>
                            </td>
                            <td class="p-6 text-center">
                                <div class="flex justify-center gap-2">
                                    <button @click="deleteRule(rule)" class="w-8 h-8 rounded-lg bg-red-500/10 text-red-500 flex items-center justify-center hover:bg-red-500 hover:text-slate-900 transition-all shadow-lg">
                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rules.data.length === 0">
                            <td colspan="6" class="p-20 text-center text-slate-600">
                                <i class="fa-solid fa-gauge-high text-6xl mb-6 opacity-20"></i>
                                <p class="font-black uppercase tracking-[0.2em] text-xs">Nenhuma regra de preço configurada.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    rules: Object
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2 }).format(value || 0);
};

const formatDate = (date) => {
    if (!date) return 'Nunca exercutado';
    return new Date(date).toLocaleString('pt-BR');
};

const formatStrategy = (strategy) => {
    const map = {
        'follow_cheapest': 'Seguir Menor Preço',
        'fixed_difference': 'Diferença Fixa',
        'percentage_margin': 'Margem Alvo %'
    };
    return map[strategy] || strategy;
};

const toggleRule = (rule) => {
    router.post(route('marketplaces.price-rules.toggle', rule.id));
};

const deleteRule = (rule) => {
    if (confirm('Deseja realmente excluir esta regra?')) {
        router.delete(route('marketplaces.price-rules.destroy', rule.id));
    }
};
</script>
