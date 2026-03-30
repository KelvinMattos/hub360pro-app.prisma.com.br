<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Base de <span class="bg-gradient-to-r from-emerald-400 to-cyan-500 bg-clip-text text-transparent">Clientes</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Inteligência de CRM & Fidelização.</p>
                </div>
                
                <div class="relative w-full md:w-80">
                    <input 
                        v-model="search" 
                        type="text" 
                        placeholder="Buscar por Nome ou CPF/CNPJ..." 
                        class="w-full bg-white shadow-premium border border-slate-200 text-slate-900 rounded-2xl pl-12 pr-4 py-3 focus:outline-none focus:border-blue-500 transition-all font-medium shadow-lg"
                    >
                    <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                </div>
            </div>

            <!-- Customers Table -->
            <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-black/20 text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-800">
                                <th class="p-6">Cliente / Identificação</th>
                                <th class="p-6 text-center">Total Pedidos</th>
                                <th class="p-6 text-center">Ticket Médio</th>
                                <th class="p-6 text-center">Última Compra</th>
                                <th class="p-6 text-right">Total Investido</th>
                                <th class="p-6 text-center">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            <tr 
                                v-for="customer in customers.data" 
                                :key="customer.billing_doc_number"
                                class="hover:bg-blue-500/5 transition-all group"
                            >
                                <td class="p-6">
                                    <div class="flex items-center gap-4">
                                        <div class="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center text-slate-500 font-black text-xs border border-slate-700 group-hover:bg-emerald-500 group-hover:text-slate-900 transition-all">
                                            {{ customer.customer_name?.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="text-slate-900 font-bold text-sm group-hover:text-emerald-400 transition-colors">{{ customer.customer_name }}</p>
                                            <p class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-0.5">{{ customer.billing_doc_number }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-6 text-center">
                                    <span class="text-slate-900 font-black text-sm">{{ customer.total_orders }}</span>
                                    <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest">Pedidos</p>
                                </td>
                                <td class="p-6 text-center">
                                    <span class="text-slate-300 font-bold text-sm">R$ {{ formatCurrency(customer.total_spent / customer.total_orders) }}</span>
                                </td>
                                <td class="p-6 text-center">
                                    <span class="text-xs font-mono text-slate-400">{{ formatDate(customer.last_purchase) }}</span>
                                </td>
                                <td class="p-6 text-right">
                                    <p class="text-slate-900 font-black text-base tracking-tighter">R$ {{ formatCurrency(customer.total_spent) }}</p>
                                </td>
                                <td class="p-6 text-center">
                                    <Link :href="route('customers.show', customer.billing_doc_number)" class="text-[10px] font-black text-blue-400 hover:text-slate-900 uppercase tracking-widest bg-blue-500/10 px-4 py-2 rounded-xl border border-blue-500/20 inline-block transition-all active:scale-95">
                                        Histórico
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="customers.data.length === 0">
                                <td colspan="6" class="p-20 text-center text-slate-600">
                                    <i class="fa-solid fa-users-slash text-6xl mb-6 opacity-20"></i>
                                    <p class="font-black uppercase tracking-[0.2em] text-xs">Nenhum cliente identificado.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer / Pagination -->
                <div class="p-6 bg-black/10 border-t border-slate-800">
                    <div class="flex flex-col sm:flex-row justify-between items-center gap-6">
                        <p class="text-[11px] text-slate-500 font-black uppercase tracking-widest leading-none">
                            {{ customers.total }} Clientes Ativos
                        </p>
                        <Pagination :links="customers.links" />
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
    customers: Object,
    filters: Object
});

const search = ref(props.filters?.search || '');

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

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('pt-BR');
};

watch(search, debounce((value) => {
    router.get(route('customers.index'), { search: value }, { 
        preserveState: true, 
        replace: true,
        only: ['customers']
    });
}, 300));
</script>
