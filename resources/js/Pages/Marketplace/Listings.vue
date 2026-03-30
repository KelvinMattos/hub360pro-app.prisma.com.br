<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <div class="mb-10 flex justify-between items-end">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Gestão de <span class="bg-gradient-to-r from-emerald-400 to-teal-500 bg-clip-text text-transparent">Anúncios</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Sincronize e gerencie seus anúncios omnichannel em massa.</p>
                </div>
                <div class="flex gap-4">
                    <select v-model="selectedCredential" class="bg-[#1E293B] border border-slate-700 text-slate-900 text-xs font-black uppercase tracking-widest px-6 py-3 rounded-2xl focus:ring-emerald-500 transition-all">
                        <option value="">Selecionar Conta</option>
                        <option v-for="cred in credentials" :key="cred.id" :value="cred.id">{{ cred.account_nickname || cred.marketplace }}</option>
                    </select>
                    <button @click="syncListings" class="bg-emerald-500 hover:bg-emerald-600 text-slate-900 px-8 py-3 rounded-2xl font-black text-xs uppercase tracking-widest transition-all shadow-lg shadow-emerald-500/20 active:scale-95">
                        <i class="fa-solid fa-rotate mr-2"></i> Sincronizar Tudo
                    </button>
                </div>
            </div>

            <div class="bg-white shadow-premium border border-slate-200 rounded-3xl overflow-hidden shadow-2xl">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-black/20 text-slate-500 text-[10px] font-black uppercase tracking-[0.2em] border-b border-slate-800">
                            <th class="p-6">Produto / SKU</th>
                            <th class="p-6 text-center">Preço</th>
                            <th class="p-6 text-center">Estoque</th>
                            <th class="p-6 text-center">Raio-X</th>
                            <th class="p-6 text-center">Status Mktp</th>
                            <th class="p-6 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="product in listings.data" :key="product.id" class="hover:bg-emerald-500/5 transition-all group">
                            <td class="p-6">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-slate-800 rounded-xl overflow-hidden border border-slate-700">
                                        <img :src="product.image_url || 'https://via.placeholder.com/100'" class="w-full h-full object-cover">
                                    </div>
                                    <div>
                                        <p class="text-slate-900 font-bold text-sm leading-tight mb-1 group-hover:text-emerald-400 transition-colors">{{ product.title }}</p>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">{{ product.sku }}</span>
                                            <span class="text-[10px] bg-black/40 text-slate-400 px-2 py-0.5 rounded uppercase font-black">{{ product.json_data?.ml_item_id }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-6 text-center">
                                <p class="text-slate-900 font-mono text-sm font-black">R$ {{ product.sale_price }}</p>
                            </td>
                            <td class="p-6 text-center">
                                <span :class="product.stock_quantity > 10 ? 'text-emerald-500' : 'text-orange-500'" class="font-mono text-sm font-black">{{ product.stock_quantity }}</span>
                            </td>
                            <td class="p-6 text-center">
                                <!-- Raio-X Score -->
                                <div class="flex flex-col items-center">
                                    <span 
                                        :class="{
                                            'bg-emerald-500/20 text-emerald-500 border-emerald-500/30': product.quality_metrics.score >= 8,
                                            'bg-orange-500/20 text-orange-500 border-orange-500/30': product.quality_metrics.score >= 5 && product.quality_metrics.score < 8,
                                            'bg-red-500/20 text-red-500 border-red-500/30': product.quality_metrics.score < 5
                                        }"
                                        class="text-xs font-black px-3 py-1 rounded-lg border"
                                        :title="product.quality_metrics.improvements.join('\n')"
                                    >
                                        {{ product.quality_metrics.score }}/10
                                    </span>
                                    <span class="text-[8px] font-bold text-slate-400 mt-1 uppercase tracking-tighter">{{ product.quality_metrics.label }}</span>
                                </div>
                            </td>
                            <td class="p-6 text-center">
                                <span class="bg-emerald-500/10 text-emerald-500 text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded-full border border-emerald-500/20">
                                    {{ product.json_data?.ml_status || 'active' }}
                                </span>
                            </td>
                            <td class="p-6 text-right">
                                <div class="flex justify-end gap-2">
                                    <button class="w-10 h-10 rounded-xl bg-slate-800 text-slate-400 flex items-center justify-center hover:bg-emerald-500 hover:text-slate-900 transition-all">
                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    </button>
                                    <button class="w-10 h-10 rounded-xl bg-slate-800 text-slate-400 flex items-center justify-center hover:bg-blue-500 hover:text-slate-900 transition-all">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="p-6 border-t border-slate-800 flex justify-between items-center bg-black/10">
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">{{ listings.total }} Anúncios</span>
                    <!-- Add Pagination here if needed -->
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    listings: Object,
    credentials: Array
});

const selectedCredential = ref('');

const syncListings = () => {
    if (!selectedCredential.value) {
        alert('Selecione uma conta para sincronizar.');
        return;
    }
    router.post(route('marketplaces.listings.sync'), {
        credential_id: selectedCredential.value
    });
};
</script>
