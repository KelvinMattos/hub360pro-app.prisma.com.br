<template>
    <AppLayout title="Edição em Massa">
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-end mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tightest leading-none">
                        Bulk <span class="bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent italic">Editor</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Controle total sobre seus anúncios em escala global.</p>
                </div>
                
                <div class="flex gap-4">
                    <button @click="showRollbackModal = true; fetchHistory()" class="px-6 py-3 bg-white border border-slate-200 rounded-[1.5rem] text-sm font-bold text-slate-700 hover:bg-slate-50 hover:shadow-lg transition-all flex items-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left"></i> Histórico
                    </button>
                    <button 
                        @click="submitBulkEdit" 
                        :disabled="selectedIds.length === 0 || isSubmitting"
                        class="px-8 py-3 bg-slate-900 text-white rounded-[1.5rem] text-sm font-black uppercase tracking-widest hover:bg-black hover:shadow-2xl hover:shadow-black/20 transition-all disabled:opacity-50 disabled:cursor-not-allowed border-b-4 border-slate-700 active:border-b-0 active:translate-y-1"
                    >
                        {{ isSubmitting ? 'Sincronizando...' : 'Aplicar em Massa' }}
                    </button>
                </div>
            </div>

            <!-- Quick Actions Bar -->
            <div class="bg-white/70 backdrop-blur-xl border border-white p-8 rounded-[3rem] mb-12 shadow-premium flex flex-wrap items-end gap-8">
                <div class="flex-1 min-w-[200px]">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">O que deseja alterar?</p>
                    <select v-model="bulkAction.field" class="w-full bg-slate-100/50 border-none rounded-2xl py-4 px-6 font-bold text-slate-700 focus:ring-4 focus:ring-orange-500/10 transition-all">
                        <optgroup label="Valores">
                            <option value="price">Ajustar Preço</option>
                            <option value="stock">Ajustar Estoque</option>
                            <option value="status">Mudar Status</option>
                        </optgroup>
                        <optgroup label="Sincronização Master">
                            <option value="title">Igualar ao Mestre (Título)</option>
                            <option value="description">Igualar ao Mestre (Descrição)</option>
                            <option value="images">Igualar ao Mestre (Fotos)</option>
                            <option value="all_sync">Sincronização Total</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Template Selector -->
                <div v-if="['title', 'description', 'images', 'all_sync'].includes(bulkAction.field)" class="flex-[1.5] min-w-[300px] animate-in slide-in-from-left duration-500">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Anúncio Referência (Master)</p>
                    <select v-model="bulkAction.template_id" class="w-full bg-orange-50 border border-orange-200 rounded-2xl py-4 px-6 font-bold text-slate-900 focus:ring-4 focus:ring-orange-500/10 transition-all">
                        <option value="">Selecione o anúncio base...</option>
                        <option v-for="l in listings" :key="l.id" :value="l.id">
                            {{ l.title.substring(0, 45) }}... [{{ l.sku }}]
                        </option>
                    </select>
                </div>

                <div v-else class="flex-1 min-w-[200px]">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Valor ou Percentual (+/- %)</p>
                    <input 
                        v-model="bulkAction.value" 
                        type="text" 
                        placeholder="Ex: +10% ou 199.90"
                        class="w-full bg-slate-100/50 border-none rounded-2xl py-4 px-6 font-bold text-slate-900 placeholder:text-slate-300 focus:ring-4 focus:ring-orange-500/10 transition-all"
                    >
                </div>

                <div class="pb-4">
                    <div class="px-4 py-2 bg-slate-100 rounded-full text-[10px] font-black text-slate-500 uppercase tracking-tighter shadow-inner">
                        {{ selectedIds.length }} SKUs selecionados
                    </div>
                </div>
            </div>

            <!-- Listings Table -->
            <div class="bg-white/60 backdrop-blur-2xl rounded-[3rem] border border-white shadow-premium overflow-hidden animate-in fade-in duration-1000">
                <table class="w-full text-left">
                    <thead class="bg-slate-900 text-white/40 text-[10px] font-black tracking-widest uppercase">
                        <tr>
                            <th class="px-10 py-6 border-b border-white/5">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded-lg border-slate-700 bg-slate-800 text-orange-600 focus:ring-orange-500 transition-all">
                            </th>
                            <th class="px-10 py-6 border-b border-white/5">Anúncio / Plataforma</th>
                            <th class="px-10 py-6 border-b border-white/5 text-center">SKU</th>
                            <th class="px-10 py-6 border-b border-white/5 text-right">Preço de Venda</th>
                            <th class="px-10 py-6 border-b border-white/5 text-center">Disponível</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100/50">
                        <tr v-for="l in listings" :key="l.id" :class="['group transition-all duration-300', selectedIds.includes(l.id) ? 'bg-orange-50/40' : 'hover:bg-slate-50/50']">
                            <td class="px-10 py-6">
                                <input type="checkbox" v-model="selectedIds" :value="l.id" class="rounded-lg border-slate-200 text-orange-600 focus:ring-orange-500 transition-all">
                            </td>
                            <td class="px-10 py-6">
                                <div class="flex items-center gap-6">
                                    <div class="w-14 h-14 rounded-2xl bg-white shadow-sm flex items-center justify-center overflow-hidden border border-slate-100 group-hover:scale-110 transition-transform">
                                        <img v-if="l.thumbnail" :src="l.thumbnail" class="w-full h-full object-cover">
                                        <i v-else class="fa-solid fa-image text-slate-200"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-black text-slate-900 truncate tracking-tight">{{ l.title }}</p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-[9px] font-black px-2 py-0.5 rounded-md bg-blue-50 text-blue-500 uppercase tracking-widest">ML</span>
                                            <p class="text-[10px] font-bold text-slate-400">{{ l.platform_id }}</p>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-10 py-6 text-center">
                                <span class="text-[10px] font-black text-slate-500 bg-slate-100 px-3 py-1.5 rounded-xl border border-slate-200/50">{{ l.sku }}</span>
                            </td>
                            <td class="px-10 py-6 text-right font-black text-slate-900">
                                <p class="text-xs text-slate-300 font-bold mb-0.5 tracking-tighter">BRL</p>
                                R$ {{ formatCurrency(l.price) }}
                            </td>
                            <td class="px-10 py-6 text-center">
                                <div :class="['text-xs font-black px-4 py-2 rounded-2xl inline-block', l.stock > 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500']">
                                    {{ l.stock }} un
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Rollback Modal -->
        <div v-if="showRollbackModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300">
            <div class="bg-white w-full max-w-3xl rounded-[3rem] shadow-2xl overflow-hidden">
                <div class="p-10 border-b border-slate-50 flex justify-between items-center bg-slate-50/50">
                    <div>
                        <h3 class="text-2xl font-black text-slate-900 tracking-tight">Audit Log & Rollback</h3>
                        <p class="text-slate-400 text-sm font-bold mt-1">Histórico de alterações em lote.</p>
                    </div>
                    <button @click="showRollbackModal = false" class="w-12 h-12 rounded-full bg-white shadow-sm flex items-center justify-center text-slate-400 hover:text-red-500 transition-all active:scale-90">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="max-h-[60vh] overflow-y-auto custom-scrollbar">
                    <div v-if="history.length === 0" class="p-24 text-center">
                        <div class="w-20 h-20 bg-slate-100 rounded-[2rem] flex items-center justify-center mx-auto mb-6 text-slate-300">
                            <i class="fa-solid fa-history text-3xl"></i>
                        </div>
                        <p class="font-black text-slate-900 text-lg">Sem registros de auditoria</p>
                        <p class="text-slate-400 font-medium">As alterações em massa aparecerão aqui após processadas.</p>
                    </div>
                    <table v-else class="w-full text-left">
                        <thead class="bg-slate-50/50 sticky top-0 backdrop-blur-md">
                            <tr>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Timestamp</th>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Modificação</th>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="log in history" :key="log.id" class="group">
                                <td class="px-10 py-6 text-[11px] font-bold text-slate-400">{{ formatDate(log.created_at) }}</td>
                                <td class="px-10 py-6">
                                    <p class="text-xs font-black text-slate-900 truncate max-w-[200px]">{{ log.product?.title || 'Produto Removido' }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-[9px] font-black uppercase text-blue-500">{{ log.field }}</span>
                                        <i class="fa-solid fa-arrow-right text-[8px] text-slate-300"></i>
                                        <span class="text-[10px] font-bold text-slate-500">{{ log.new_value }}</span>
                                    </div>
                                </td>
                                <td class="px-10 py-6 text-right">
                                    <button @click="rollback(log.id)" class="px-6 py-2 rounded-full text-xs font-black bg-orange-50 text-orange-600 hover:bg-orange-600 hover:text-white transition-all shadow-sm">
                                        Reverter
                                    </button>
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
import { ref, computed, reactive, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    listings: {
        type: Array,
        default: () => []
    }
});

const selectedIds = ref([]);
const isSubmitting = ref(false);
const showRollbackModal = ref(false);
const history = ref([]);
const bulkAction = reactive({
    field: 'price',
    value: '',
    template_id: ''
});

const fetchHistory = async () => {
    try {
        const response = await axios.get(route('marketplaces.listings.history'));
        history.value = response.data;
    } catch (e) { console.error('History Fetch Error:', e); }
};

const rollback = (id) => {
    if (!confirm('Esta ação restaurará o valor anterior deste anúncio. Confirmar reversão?')) return;
    router.post(route('marketplaces.listings.rollback'), { history_id: id }, {
        onSuccess: () => fetchHistory()
    });
};

const formatDate = (date) => new Date(date).toLocaleString('pt-BR');

const allSelected = computed(() => {
    return selectedIds.value.length === props.listings.length && props.listings.length > 0;
});

const toggleAll = (e) => {
    selectedIds.value = e.target.checked ? props.listings.map(l => l.id) : [];
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2 }).format(value);
};

const submitBulkEdit = () => {
    if (!confirm(`Importante: Você está prestes a atualizar ${selectedIds.value.length} anúncios. Esta ação será auditada. Prosseguir?`)) return;
    
    isSubmitting.value = true;
    router.post(route('marketplaces.listings.bulk_update'), {
        ids: selectedIds.value,
        field: bulkAction.field,
        value: bulkAction.value,
        template_id: bulkAction.template_id
    }, {
        onFinish: () => {
            isSubmitting.value = false;
            selectedIds.value = [];
        }
    });
};

onMounted(() => {
    // Audit logs are fetched on modal open to save bandwidth
});
</script>

<style scoped>
.shadow-premium {
    box-shadow: 0 20px 80px -20px rgba(0,0,0,0.06);
}
.tracking-tightest {
    letter-spacing: -0.07em;
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
