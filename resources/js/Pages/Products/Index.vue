<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Catálogo <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Mestre</span>
                    </h1>
                    <p class="text-slate-400 mt-2 font-medium text-lg italic">Gestão Unificada de Master SKUs & Multicontas.</p>
                </div>
                
                <div class="flex gap-3 w-full md:w-auto">
                    <div class="relative flex-1 md:w-80">
                        <input 
                            v-model="search" 
                            type="text" 
                            placeholder="Buscar SKU ou Nome..." 
                            class="w-full bg-white border border-slate-200 text-slate-900 rounded-2xl pl-12 pr-4 py-3 focus:outline-none focus:border-blue-500 transition-all font-medium shadow-sm"
                        >
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    </div>
                </div>
            </div>

            <!-- Product Table -->
            <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-premium min-h-[600px] flex flex-col">
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-100">
                                <th class="p-6">Master SKU</th>
                                <th class="p-6">Produto</th>
                                <th class="p-6 text-center">Anúncios</th>
                                <th class="p-6 text-center">Estoque Total</th>
                                <th class="p-6 text-right">Preço Médio</th>
                                <th class="p-6 text-center">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="product in products.data" 
                                :key="product.master_sku"
                                class="hover:bg-blue-50 transition-all group"
                            >
                                <td class="p-6">
                                    <span class="font-mono text-xs text-blue-600 font-black bg-blue-50 px-2 py-1 rounded-lg border border-blue-100 tracking-tighter">
                                        {{ product.master_sku }}
                                    </span>
                                </td>
                                <td class="p-6">
                                    <div class="flex items-center gap-4">
                                        <div class="w-10 h-10 bg-white rounded-lg overflow-hidden p-1 shadow-sm shrink-0 border border-slate-100">
                                            <img v-if="product.image_url" :src="product.image_url" class="w-full h-full object-contain">
                                            <i v-else class="fa-regular fa-image text-slate-300 text-xl"></i>
                                        </div>
                                        <p class="text-slate-900 font-bold text-sm truncate group-hover:text-blue-600 transition-colors">{{ product.title }}</p>
                                    </div>
                                </td>
                                <td class="p-6 text-center">
                                    <div class="inline-flex items-center gap-2 bg-slate-50 px-3 py-1 rounded-full border border-slate-100">
                                        <i class="fa-solid fa-link text-[10px] text-blue-500"></i>
                                        <span class="text-slate-900 font-black text-xs">{{ product.listings_count }}</span>
                                    </div>
                                </td>
                                <td class="p-6 text-center">
                                    <div :class="['text-base font-black tracking-tighter', product.total_stock <= 5 ? 'text-red-600' : 'text-emerald-600']">
                                        {{ product.total_stock }}
                                    </div>
                                    <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest leading-none mt-1">Unidades</p>
                                </td>
                                <td class="p-6 text-right">
                                    <p class="text-slate-900 font-black text-sm">R$ {{ formatCurrency(product.avg_price) }}</p>
                                </td>
                                <td class="p-6 text-center">
                                    <div class="flex justify-center gap-2">
                                        <Link :href="route('pricing.simulator', { search: product.master_sku })" class="w-8 h-8 rounded-lg bg-orange-500/10 text-orange-500 flex items-center justify-center hover:bg-orange-500 hover:text-white transition-all shadow-lg" title="Simular Preço">
                                            <i class="fa-solid fa-calculator text-[10px]"></i>
                                        </Link>
                                        <Link :href="route('meli.war_room', { search: product.master_sku })" class="w-8 h-8 rounded-lg bg-red-500/10 text-red-500 flex items-center justify-center hover:bg-red-500 hover:text-white transition-all shadow-lg" title="Modo Espião">
                                            <i class="fa-solid fa-radar text-[10px]"></i>
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="products.data.length === 0">
                                <td colspan="6" class="p-20 text-center text-slate-600">
                                    <i class="fa-solid fa-box-open text-6xl mb-6 opacity-20"></i>
                                    <p class="font-black uppercase tracking-[0.2em] text-xs">Nenhum Master SKU encontrado.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer / Pagination -->
                <div class="p-6 bg-slate-50 border-t border-slate-100">
                    <div class="flex flex-col sm:flex-row justify-between items-center gap-6">
                        <p class="text-[11px] text-slate-400 font-black uppercase tracking-widest leading-none">
                            {{ products.total }} Master SKUs Identificados
                        </p>
                        <Pagination :links="products.links" />
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
    products: Object,
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

watch(search, debounce((value) => {
    router.get(route('products.index'), { search: value }, { 
        preserveState: true, 
        replace: true,
        only: ['products']
    });
}, 300));
</script>
