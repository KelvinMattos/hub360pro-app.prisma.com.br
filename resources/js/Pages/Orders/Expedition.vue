<template>
    <AppLayout title="Expedição Flash">
        <div class="h-[calc(100vh-100px)] flex bg-[#F5F5F7]">
            <!-- Sidebar: Order List -->
            <div class="w-96 border-r border-black/[0.05] flex flex-col bg-white/50 backdrop-blur-xl">
                <div class="p-6 border-b border-black/[0.05]">
                    <h2 class="text-2xl font-black text-slate-900 tracking-tight">Expedição <span class="text-blue-600">Flash</span></h2>
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mt-1">Aguardando Conferência ({{ orders.length }})</p>
                    
                    <div class="mt-4 relative group">
                        <i class="fa-solid fa-barcode absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                        <input 
                            v-model="barcodeInput"
                            @keyup.enter="handleBarcode"
                            placeholder="Escanear Etiqueta..." 
                            class="w-full bg-slate-100 border-none rounded-2xl py-3 pl-12 pr-4 text-sm font-semibold focus:ring-2 focus:ring-blue-500/20 transition-all"
                            autofocus
                        />
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-3">
                    <div 
                        v-for="order in orders" 
                        :key="order.id"
                        @click="selectedOrder = order"
                        :class="[
                            'p-4 rounded-[1.5rem] border transition-all cursor-pointer group relative overflow-hidden',
                            selectedOrder?.id === order.id ? 'bg-white border-blue-500 shadow-premium scale-[1.02]' : 'bg-transparent border-transparent hover:bg-black/[0.02]'
                        ]"
                    >
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ order.external_id }}</span>
                            <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center">
                                <i class="fa-brands fa-laravel text-[10px] text-orange-500" v-if="order.platform === 'mercadolibre'"></i>
                            </div>
                        </div>
                        <p class="text-sm font-black text-slate-900 truncate">{{ order.customer_name || 'Cliente Final' }}</p>
                        <div class="flex gap-2 mt-2">
                            <span class="text-[9px] font-bold px-2 py-0.5 rounded-md bg-blue-50 text-blue-600">{{ order.items.length }} Itens</span>
                            <span class="text-[9px] font-bold px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-600">R$ {{ order.total_amount }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main: Selection Detail -->
            <div class="flex-1 flex flex-col relative overflow-hidden">
                <div v-if="selectedOrder" class="flex-1 flex flex-col p-12 overflow-y-auto custom-scrollbar animate-in fade-in slide-in-from-right-4 duration-500">
                    <div class="flex justify-between items-start mb-12">
                        <div>
                            <h3 class="text-4xl font-black text-slate-900 tracking-tighter">Pedido {{ selectedOrder.external_id }}</h3>
                            <p class="text-slate-400 font-bold text-lg mt-2">Conferência de Itens</p>
                        </div>
                        <div class="flex gap-4">
                            <button @click="packOrder(selectedOrder.id)" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-sm tracking-widest uppercase hover:scale-105 active:scale-95 transition-all shadow-xl shadow-slate-900/20 border-b-4 border-slate-700">
                                <i class="fa-solid fa-box-open mr-2"></i> Finalizar & Etiquetar
                            </button>
                        </div>
                    </div>

                    <!-- Items Checklist -->
                    <div class="space-y-6">
                        <div 
                            v-for="item in selectedOrder.items" 
                            :key="item.id"
                            class="bg-white/60 backdrop-blur-xl border border-white p-8 rounded-[3rem] flex items-center gap-8 shadow-premium"
                        >
                            <div class="w-24 h-24 bg-slate-100 rounded-[2rem] flex items-center justify-center p-4">
                                <img v-if="item.product?.image_url" :src="item.product.image_url" class="max-w-full max-h-full object-contain">
                                <i v-else class="fa-solid fa-image text-slate-300 text-3xl"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-[10px] font-black tracking-widest text-blue-600 uppercase mb-2">{{ item.product?.sku || 'SKU-PENDENTE' }}</p>
                                <h4 class="text-xl font-bold text-slate-900 leading-tight">{{ item.product?.title || 'Produto Indefinido' }}</h4>
                                <p class="text-slate-400 font-bold mt-1">EAN: {{ item.product?.ean || 'N/A' }}</p>
                            </div>
                            <div class="flex items-center gap-8">
                                <div class="text-center">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">QTD</p>
                                    <p class="text-4xl font-black text-slate-900">{{ item.quantity }}</p>
                                </div>
                                <div class="w-16 h-16 rounded-full border-4 border-slate-100 flex items-center justify-center text-slate-200 hover:text-emerald-500 hover:border-emerald-500 transition-all cursor-pointer">
                                    <i class="fa-solid fa-check text-2xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="flex-1 flex flex-col items-center justify-center text-center p-12">
                    <div class="w-32 h-32 bg-slate-200/50 rounded-[3rem] flex items-center justify-center mb-8 animate-pulse text-slate-400 text-5xl">
                        <i class="fa-solid fa-barcode"></i>
                    </div>
                    <h3 class="text-2xl font-black text-slate-900 tracking-tight">Aguardando bipagem...</h3>
                    <p class="text-slate-400 font-bold mt-2 max-w-xs">Selecione um pedido ou escaneie o código de barras da etiqueta.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    orders: Array
});

const selectedOrder = ref(null);
const barcodeInput = ref('');

const handleBarcode = () => {
    const order = props.orders.find(o => o.external_id === barcodeInput.value);
    if (order) {
        selectedOrder.value = order;
    } else {
        alert('Pedido não encontrado para o código: ' + barcodeInput.value);
    }
    barcodeInput.value = '';
};

const packOrder = (id) => {
    router.post(route('orders.pack', id), {}, {
        onSuccess: () => {
            selectedOrder.value = null;
        }
    });
};
</script>

<style scoped>
.shadow-premium {
    box-shadow: 0 10px 40px -10px rgba(0,0,0,0.05);
}
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.05);
    border-radius: 10px;
}
</style>
