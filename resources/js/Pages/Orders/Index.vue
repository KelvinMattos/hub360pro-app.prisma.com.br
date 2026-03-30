<template>
    <AppLayout>
        <div class="p-8">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8">
                <div>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Vendas & <span class="bg-gradient-to-r from-cyan-600 to-blue-600 bg-clip-text text-transparent">Pedidos</span></h1>
                    <p class="text-slate-400 mt-1 font-medium italic">Gestão centralizada de múltiplos canais.</p>
                </div>
                <div class="flex gap-3 w-full md:w-auto">
                    <div class="relative flex-1 md:w-80">
                        <input 
                            v-model="search" 
                            type="text" 
                            placeholder="Buscar por ID ou cliente..." 
                            class="w-full bg-white border border-slate-200 text-slate-900 rounded-2xl pl-12 pr-4 py-3 focus:outline-none focus:border-blue-500 transition-all font-medium"
                        >
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    </div>
                    <button @click="syncAll" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-2xl font-bold transition-all flex items-center gap-2 shadow-lg shadow-blue-600/20 active:scale-95">
                        <i class="fa-solid fa-sync" :class="{ 'fa-spin': isSyncing }"></i>
                        <span class="hidden sm:inline">Atualizar</span>
                    </button>
                </div>
            </div>

            <!-- Orders Table Container -->
            <div class="bg-[#1E293B] border border-slate-700/50 rounded-3xl shadow-2xl overflow-hidden min-h-[500px] flex flex-col">
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-100">
                                <th class="p-6 text-center">Canal</th>
                                <th class="p-6">Pedido / Data</th>
                                <th class="p-6">Cliente</th>
                                <th class="p-6 text-center">Status</th>
                                <th class="p-6 text-right">Valor</th>
                                <th class="p-6 text-right">Margem</th>
                                <th class="p-6 text-right">Lucro</th>
                                <th class="p-6 text-center">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="order in orders.data" 
                                :key="order.id"
                                @click="viewOrder(order.id)"
                                class="hover:bg-blue-50 transition-all cursor-pointer group"
                            >
                                <td class="p-6 text-center">
                                    <div :class="['w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center mx-auto text-lg transition-transform group-hover:scale-110', order.channel_icon?.color || 'text-slate-500']">
                                        <i :class="['fa-brands', (order.channel_icon?.icon === 'fa-handshake' ? 'fa-solid fa-handshake' : order.channel_icon?.icon) || 'fa-solid fa-cart-shopping']"></i>
                                    </div>
                                </td>
                                <td class="p-6">
                                    <span class="font-mono text-xs text-blue-600 font-black bg-blue-50 px-2 py-1 rounded-lg block w-fit mb-1 tracking-tighter">#{{ order.external_id }}</span>
                                    <span class="text-[11px] text-slate-400 font-bold uppercase">{{ order.date_created }}</span>
                                </td>
                                <td class="p-6">
                                    <div class="font-bold text-slate-900 text-sm group-hover:text-blue-600 transition-colors">{{ order.customer_name }}</div>
                                    <div class="text-[11px] text-slate-400 font-mono">{{ order.customer_doc }}</div>
                                </td>
                                <td class="p-6 text-center">
                                    <span :class="['px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border', order.status_color]">
                                        {{ order.status_label }}
                                    </span>
                                </td>
                                <td class="p-6 text-right font-black text-slate-900 text-sm">
                                    R$ {{ formatCurrency(order.total_amount) }}
                                </td>
                                <td class="p-6 text-right">
                                    <div class="text-[11px] text-blue-600 font-black tracking-tighter">R$ {{ formatCurrency(order.contribution_margin) }}</div>
                                </td>
                                <td class="p-6 text-right">
                                    <div class="text-emerald-600 font-extrabold text-base tracking-tighter">R$ {{ formatCurrency(order.net_profit) }}</div>
                                </td>
                                <td class="p-6 text-center">
                                    <Link :href="route('orders.show', order.id)" class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400 hover:text-white hover:bg-blue-600 transition-all">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="orders.data.length === 0">
                                <td colspan="8" class="p-20 text-center">
                                    <div class="flex flex-col items-center gap-4">
                                        <div class="w-20 h-20 bg-slate-800/50 rounded-full flex items-center justify-center text-slate-600">
                                            <i class="fa-solid fa-ghost text-4xl"></i>
                                        </div>
                                        <p class="text-slate-500 font-medium">Nenhum pedido encontrado no período.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer / Pagination -->
                <div class="p-6 bg-slate-50 border-t border-slate-100">
                    <div class="flex flex-col sm:flex-row justify-between items-center gap-6">
                        <p class="text-[11px] text-slate-400 font-black uppercase tracking-widest leading-none">
                            Exibindo {{ orders.from || 0 }} até {{ orders.to || 0 }} de {{ orders.total }} pedidos
                        </p>
                        <Pagination :links="orders.links" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    orders: Object
});

const search = ref('');
const isSyncing = ref(false);

const debounce = (fn, delay) => {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};

const viewOrder = (id) => {
    router.get(route('orders.show', id));
};

const syncAll = () => {
    isSyncing.value = true;
    router.visit(route('products.sync'), {
        onFinish: () => isSyncing.value = false
    });
};

watch(search, debounce((value) => {
    router.get(route('orders.index'), { search: value }, { 
        preserveState: true, 
        replace: true,
        only: ['orders']
    });
}, 300));
</script>
