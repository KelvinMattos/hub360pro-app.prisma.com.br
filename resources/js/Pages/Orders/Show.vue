<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header Nav -->
            <div class="flex items-center gap-4 mb-8">
                <Link :href="route('orders.index')" class="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center text-slate-400 hover:bg-blue-600 hover:text-slate-900 transition group shadow-lg">
                    <i class="fa-solid fa-arrow-left"></i>
                </Link>
                <div class="h-8 w-px bg-slate-800 mx-1"></div>
                <div>
                    <div class="flex items-center gap-2">
                        <span :class="['w-10 h-10 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-lg', order.channel_icon?.color || 'text-slate-500']">
                            <i :class="['fa-brands', (order.channel_icon?.icon === 'fa-handshake' ? 'fa-solid fa-handshake' : order.channel_icon?.icon) || 'fa-solid fa-cart-shopping']"></i>
                        </span>
                        <h1 class="text-2xl font-black text-slate-900 leading-none">Venda <span class="text-blue-500">#{{ order.external_id }}</span></h1>
                    </div>
                    <p class="text-xs text-slate-500 font-mono mt-1 uppercase tracking-wider">
                        Criado em {{ order.date_created }}
                    </p>
                </div>
                
                <div class="ml-auto flex items-center gap-3">
                    <span :class="['px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border shadow-xl', order.status_color]">
                        {{ order.status_label }}
                    </span>
                    <button @click="syncOrder" :disabled="isSyncing" class="bg-blue-600 hover:bg-blue-500 text-slate-900 px-5 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 shadow-lg shadow-blue-600/20 active:scale-95 disabled:opacity-50">
                        <i class="fa-solid fa-sync" :class="{ 'fa-spin': isSyncing }"></i>
                        <span>SINC INTERNO</span>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left Column -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Customer Card -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 relative overflow-hidden group shadow-2xl">
                        <div class="absolute right-0 top-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                            <i class="fa-solid fa-user-circle text-9xl text-slate-400"></i>
                        </div>
                        <div class="flex items-center gap-6 relative z-10">
                            <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-slate-700 to-slate-800 flex items-center justify-center text-3xl font-black text-slate-900 border-2 border-slate-700 shadow-xl">
                                {{ order.safe_billing_name ? order.safe_billing_name[0] : 'U' }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-2xl font-black text-slate-900 truncate">{{ order.safe_billing_name }}</h3>
                                <div class="flex items-center gap-4 text-sm text-slate-400 mt-2">
                                    <span class="bg-slate-900/50 px-3 py-1 rounded-lg text-xs font-black border border-slate-700 tracking-wider">
                                        {{ order.safe_doc_type }}: {{ order.safe_doc_number }}
                                    </span>
                                    <span v-if="order.buyer_nickname" class="flex items-center gap-2 font-bold text-blue-400">
                                        <i class="fa-solid fa-at"></i> {{ order.buyer_nickname }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Logistics Card -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl">
                        <div class="flex gap-6">
                            <div class="w-14 h-14 rounded-2xl bg-blue-500/10 flex items-center justify-center text-blue-400 border border-blue-500/20 shrink-0 shadow-lg">
                                <i class="fa-solid fa-truck-fast text-2xl"></i>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-slate-900 font-black text-base mb-3 flex items-center gap-3">
                                    Entrega & Logística
                                    <span class="text-[9px] bg-blue-600 text-slate-900 px-2 py-0.5 rounded-full font-black uppercase tracking-wider">
                                        {{ order.logistic_type || 'Envio Padrão' }}
                                    </span>
                                </h4>
                                <p class="text-slate-400 text-sm leading-relaxed font-medium">
                                    {{ order.shipping_address_line }}, {{ order.shipping_number }}<br>
                                    {{ order.shipping_neighborhood }} — {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                                    <span class="text-blue-500/70 font-black text-xs tracking-widest mt-2 block">CEP: {{ order.shipping_zip }}</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl shadow-2xl overflow-hidden">
                        <div class="bg-black/20 px-8 py-4 border-b border-slate-800 flex justify-between items-center">
                            <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Itens da Carga</h3>
                            <span class="text-[10px] bg-slate-800 text-slate-400 px-3 py-1 rounded-full font-bold">{{ order.items.length }} UNIDADES</span>
                        </div>
                        
                        <div class="divide-y divide-slate-800/50">
                            <div v-for="item in order.items" :key="item.id" class="p-6 flex gap-6 hover:bg-blue-500/5 transition group">
                                <div class="w-20 h-20 bg-slate-900 rounded-2xl border border-slate-800 flex items-center justify-center overflow-hidden shrink-0 shadow-inner">
                                    <img v-if="item.product?.image_url" :src="item.product.image_url" class="w-full h-full object-cover">
                                    <i v-else class="fa-regular fa-image text-slate-700 text-3xl"></i>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <button 
                                        @click="showItemDetails(item)"
                                        class="text-left text-lg font-black text-slate-900 hover:text-blue-400 transition-colors line-clamp-1 leading-tight mb-2 tracking-tight"
                                    >
                                        {{ item.product_title }}
                                    </button>

                                    <div class="flex items-center gap-3 flex-wrap">
                                        <span class="text-[10px] font-black text-slate-400 bg-slate-900/50 px-2 py-1 rounded-lg border border-slate-800 tracking-wider">
                                            SKU: {{ item.sku }}
                                        </span>
                                        <span v-if="item.product" class="text-[9px] text-emerald-400 bg-emerald-400/10 px-2 py-1 rounded-full border border-emerald-500/20 font-black uppercase tracking-widest">
                                            <i class="fa-solid fa-link"></i> Vinculado
                                        </span>
                                        <span v-else class="text-[9px] text-red-400 bg-red-500/10 px-2 py-1 rounded-full border border-red-500/20 font-black uppercase tracking-widest">
                                            <i class="fa-solid fa-link-slash"></i> Pendente
                                        </span>
                                    </div>
                                </div>

                                <div class="text-right flex flex-col justify-center">
                                    <span class="block text-slate-900 font-black text-xl tracking-tighter">R$ {{ formatCurrency(item.unit_price) }}</span>
                                    <span class="block text-xs text-slate-500 font-bold">QTD: {{ item.quantity }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column (DRE) -->
                <div class="space-y-8">
                    <div class="bg-[#111827] border border-slate-700/50 rounded-3xl shadow-2xl relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-b from-blue-500/5 to-transparent"></div>
                        <div class="p-6 border-b border-slate-800 flex justify-between items-center relative z-10">
                            <h3 class="text-xs font-black text-slate-900 uppercase tracking-[0.2em] flex items-center gap-2">
                                <i class="fa-solid fa-calculator text-blue-500"></i> DRE Unitário
                            </h3>
                        </div>

                        <div class="p-8 space-y-4 font-mono relative z-10">
                            <div class="flex justify-between items-center text-emerald-400">
                                <span class="font-black text-xs uppercase tracking-widest">Receita Bruta</span>
                                <span class="font-black text-2xl tracking-tighter">R$ {{ formatCurrency(order.total_amount) }}</span>
                            </div>

                            <div class="space-y-2 pt-2 border-t border-slate-800/50">
                                <div class="flex justify-between text-[11px] text-red-400/80 font-bold uppercase">
                                    <span>(-) Impostos</span>
                                    <span>- R$ {{ formatCurrency(order.cost_tax_fiscal) }}</span>
                                </div>
                                <div class="flex justify-between text-[11px] text-red-400 font-black uppercase">
                                    <span>(-) Taxas Platform</span>
                                    <span>- R$ {{ formatCurrency(order.cost_tax_platform) }}</span>
                                </div>
                            </div>

                            <div class="flex justify-between items-center text-blue-400 pt-2 border-t border-slate-800/50 font-black uppercase tracking-tighter">
                                <span class="text-xs">(=) Margem Contr.</span>
                                <span>R$ {{ formatCurrency(order.contribution_margin) }}</span>
                            </div>

                            <div class="space-y-2">
                                <div class="flex justify-between text-[11px] text-orange-400/80 font-bold uppercase">
                                    <span>(-) Op. Logística</span>
                                    <span>- R$ {{ formatCurrency(order.cost_operational) }}</span>
                                </div>
                                <div class="flex justify-between text-[11px] text-orange-500 font-black uppercase">
                                    <span>(-) CMV (Produtos)</span>
                                    <span>- R$ {{ formatCurrency(order.cost_products) }}</span>
                                </div>
                            </div>

                            <div class="bg-slate-900/80 p-6 rounded-2xl border border-slate-700/50 mt-6 shadow-inner">
                                <div class="flex justify-between items-end mb-4">
                                    <div>
                                        <p class="text-[9px] text-slate-500 font-black uppercase tracking-[0.2em] mb-1">Lucro Líquido</p>
                                        <div :class="['text-3xl font-black tracking-tighter', order.net_profit > 0 ? 'text-emerald-400' : 'text-red-500']">
                                            R$ {{ formatCurrency(order.net_profit) }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div :class="['text-xs font-black uppercase tracking-widest', order.net_profit > 0 ? 'text-emerald-600' : 'text-red-700']">
                                             {{ order.total_amount > 0 ? ((order.net_profit / order.total_amount) * 100).toFixed(1) : 0 }}%
                                        </div>
                                    </div>
                                </div>
                                <div class="w-full bg-slate-950 h-2 rounded-full overflow-hidden">
                                    <div 
                                        :class="['h-full transition-all duration-1000', order.net_profit > 0 ? 'bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]' : 'bg-red-500 shadow-[0_0_10px_rgba(239,68,68,0.5)]']" 
                                        :style="{ width: Math.min(100, Math.max(0, (order.net_profit / order.total_amount) * 100)) + '%' }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Debug API -->
                    <button @click="showDebug = !showDebug" class="w-full text-center py-4 text-[10px] text-slate-600 hover:text-blue-400 transition-all font-black uppercase tracking-[0.3em]">
                        <i class="fa-solid fa-code mr-2"></i> Inspeção RAW JSON
                    </button>
                    
                    <div v-if="showDebug" class="bg-black/80 rounded-2xl p-6 text-[10px] font-mono text-emerald-500 h-96 overflow-auto border border-white/5 shadow-2xl backdrop-blur-md">
                        <pre>{{ JSON.stringify(order.json_order, null, 2) }}</pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Item Detail (Raio-X) -->
        <ItemDetailModal 
            v-if="selectedItem" 
            :item="selectedItem" 
            @close="selectedItem = null" 
        />
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemDetailModal from '@/Components/ItemDetailModal.vue';

const props = defineProps({
    order: Object
});

const isSyncing = ref(false);
const showDebug = ref(false);
const selectedItem = ref(null);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};

const syncOrder = () => {
    isSyncing.value = true;
    router.post(route('orders.sync_single', props.order.id), {}, {
        onFinish: () => isSyncing.value = false
    });
};

const showItemDetails = (item) => {
    selectedItem.value = item;
};
</script>
