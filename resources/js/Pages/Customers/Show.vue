<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header with Back Button -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-10">
                <div class="flex items-center gap-6">
                    <Link :href="route('customers.index')" class="w-12 h-12 bg-white shadow-premium border border-slate-200 rounded-2xl flex items-center justify-center text-slate-400 hover:text-slate-900 hover:border-blue-500 transition-all shadow-xl active:scale-95">
                        <i class="fa-solid fa-chevron-left"></i>
                    </Link>
                    <div>
                        <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">{{ customer.customer_name }}</h1>
                        <p class="text-slate-500 font-black uppercase tracking-[0.2em] text-[10px] mt-1">{{ customer.billing_doc_number }}</p>
                    </div>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">LTV (Total Gasto)</p>
                    <h3 class="text-2xl font-black text-emerald-400 tracking-tighter">R$ {{ formatCurrency(stats.total_spent) }}</h3>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Frequência</p>
                    <h3 class="text-2xl font-black text-slate-900 tracking-tighter">{{ stats.total_orders }} Pedidos</h3>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Ticket Médio</p>
                    <h3 class="text-2xl font-black text-blue-400 tracking-tighter">R$ {{ formatCurrency(stats.avg_ticket) }}</h3>
                </div>
                <div class="bg-white shadow-premium border border-slate-200 p-6 rounded-3xl shadow-xl">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Primeira Compra</p>
                    <h3 class="text-lg font-black text-slate-300 tracking-tighter">{{ formatDate(stats.first_purchase) }}</h3>
                </div>
            </div>

            <!-- Order History -->
            <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                <div class="bg-black/20 p-6 border-b border-slate-800">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Histórico Completo de Pedidos</h3>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-black/10 text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-800">
                                <th class="p-6">Data</th>
                                <th class="p-6">Canal / ID</th>
                                <th class="p-6">Status</th>
                                <th class="p-6 text-right">Valor Total</th>
                                <th class="p-6 text-center">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            <tr 
                                v-for="order in orders" 
                                :key="order.id"
                                class="hover:bg-blue-500/5 transition-all group"
                            >
                                <td class="p-6">
                                    <span class="text-xs font-bold text-slate-300">{{ formatDate(order.date_created) }}</span>
                                </td>
                                <td class="p-6">
                                    <div class="flex items-center gap-3">
                                         <i class="fa-brands fa-laravel text-yellow-500 group-hover:animate-bounce"></i>
                                         <span class="text-xs font-mono text-slate-400 uppercase tracking-tighter">{{ order.external_id }}</span>
                                    </div>
                                </td>
                                <td class="p-6">
                                    <span :class="['px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border', order.status === 'paid' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-slate-800 text-slate-500 border-slate-700']">
                                        {{ order.status }}
                                    </span>
                                </td>
                                <td class="p-6 text-right">
                                    <span class="text-slate-900 font-black text-sm">R$ {{ formatCurrency(order.total_amount) }}</span>
                                </td>
                                <td class="p-6 text-center">
                                    <Link :href="route('orders.show', order.id)" class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center hover:bg-blue-500 hover:text-slate-900 transition-all shadow-lg mx-auto">
                                        <i class="fa-solid fa-eye text-[10px]"></i>
                                    </Link>
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
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    customer: Object,
    orders: Array,
    stats: Object
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('pt-BR');
};
</script>
