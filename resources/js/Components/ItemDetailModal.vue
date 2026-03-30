<template>
    <div class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-md" @click="$emit('close')"></div>

        <!-- Modal Content -->
        <div class="bg-white border border-slate-200 rounded-3xl shadow-2xl w-full max-w-xl relative z-10 overflow-hidden transform transition-all scale-100">
            <!-- Modal Header -->
            <div class="bg-slate-50 p-8 border-b border-slate-100 flex justify-between items-start">
                <div class="flex items-start gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center border border-blue-100 shrink-0 shadow-sm">
                        <i class="fa-solid fa-microscope text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-black text-slate-900 leading-tight pr-8">{{ item.product_title }}</h3>
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mt-2 flex items-center gap-2">
                            <i class="fa-solid fa-barcode"></i> SKU: {{ item.sku }}
                        </p>
                    </div>
                </div>
                <button @click="$emit('close')" class="text-slate-400 hover:text-slate-600 transition-colors bg-slate-100 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                    <!-- Unit Economics -->
                    <div class="bg-slate-50 rounded-2xl p-6 border border-slate-100 space-y-4 font-mono">
                        <div class="flex justify-between items-center pb-2 border-b border-slate-100">
                            <span class="text-xs text-slate-400 font-bold uppercase">Preço de Venda</span>
                            <span class="text-slate-900 font-black">R$ {{ formatCurrency(item.unit_price) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-red-500">
                            <span class="text-xs font-bold uppercase">(-) Impostos & Taxas</span>
                            <span class="font-bold">- R$ {{ formatCurrency(item.unit_price * 0.22) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-orange-600">
                            <span class="text-xs font-bold uppercase">(-) Custo Produto</span>
                            <span class="font-bold">- R$ {{ formatCurrency(item.cost_price || 0) }}</span>
                        </div>
                        <div class="pt-2 border-t border-slate-200 flex justify-between items-center text-emerald-600">
                            <span class="text-xs font-black uppercase">Lucro Unitário</span>
                            <span class="text-xl font-black">R$ {{ formatCurrency(item.unit_price - (item.unit_price * 0.22) - (item.cost_price || 0)) }}</span>
                        </div>
                    </div>

                    <!-- Market Context -->
                    <div class="space-y-4">
                        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-6">
                            <h5 class="text-blue-600 font-black text-[10px] uppercase tracking-widest mb-2">Sugestão de Preço Ideal</h5>
                            <span class="text-slate-900 font-black text-3xl block tracking-tighter">
                                R$ {{ formatCurrency((item.cost_price || item.unit_price * 0.5) * 1.9) }}
                            </span>
                            <p class="text-[10px] text-blue-500/60 mt-2 font-bold uppercase tracking-wider">Markup para 30% lucro líquido</p>
                        </div>

                        <div class="bg-slate-50 border border-slate-100 rounded-2xl p-6">
                            <h5 class="text-slate-400 font-black text-[10px] uppercase tracking-widest mb-2">Markup Atual</h5>
                            <span class="text-slate-800 font-black text-2xl block tracking-tighter">
                                {{ item.cost_price > 0 ? ((item.unit_price / item.cost_price)).toFixed(2) : '--' }}x
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="flex justify-end gap-4 pt-4 border-t border-slate-100">
                    <Link :href="route('products.index', { search: item.sku })" class="px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black uppercase tracking-widest rounded-xl transition-all">
                        Ver Cadastro
                    </Link>
                    <button @click="$emit('close')" class="px-8 py-3 bg-blue-600 hover:bg-blue-500 text-white text-xs font-black uppercase tracking-widest rounded-xl transition-all shadow-lg shadow-blue-600/20">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    item: Object
});

defineEmits(['close']);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
