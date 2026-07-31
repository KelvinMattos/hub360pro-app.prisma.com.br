<template>
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <span :class="['w-8 h-8 rounded-xl flex items-center justify-center', toneClasses]"><i :class="icon"></i></span>
                <h3 class="font-bold text-slate-800">{{ title }}</h3>
                <span class="text-xs text-slate-400 font-mono">({{ items.length }})</span>
            </div>
            <button v-if="selectedIds.length" @click="createCampaign" class="text-xs font-bold bg-slate-900 text-white px-3 py-1.5 rounded-lg hover:bg-slate-700">
                Criar campanha ({{ selectedIds.length }})
            </button>
        </div>
        <div v-if="!items.length" class="text-xs text-slate-400 text-center py-6">{{ emptyText }}</div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="it in items" :key="it.product_id" class="border-b border-slate-50 last:border-0">
                        <td class="py-2 pr-2 w-6"><input type="checkbox" :value="it.product_id" @change="toggle(it.product_id)"></td>
                        <td class="py-2 pr-3">
                            <p class="font-semibold text-slate-700 truncate max-w-xs">{{ it.title || '—' }}</p>
                            <p class="text-[10px] text-slate-400 font-mono">{{ it.sku || '—' }}<span v-if="it.brand"> · {{ it.brand }}</span></p>
                        </td>
                        <td class="py-2 pr-3 text-xs text-slate-500 max-w-sm">{{ it.reason }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    icon: { type: String, required: true },
    tone: { type: String, default: 'slate' },
    items: { type: Array, default: () => [] },
    opportunity: { type: String, required: true },
    emptyText: { type: String, default: '' },
});
const emit = defineEmits(['create-campaign']);

const selectedIds = ref([]);

function toggle(id) {
    const i = selectedIds.value.indexOf(id);
    if (i >= 0) selectedIds.value.splice(i, 1); else selectedIds.value.push(id);
}
function createCampaign() {
    emit('create-campaign', { opportunity: props.opportunity, productIds: [...selectedIds.value] });
    selectedIds.value = [];
}

const toneClasses = computed(() => ({
    blue: 'text-blue-600 bg-blue-50', emerald: 'text-emerald-600 bg-emerald-50', red: 'text-red-600 bg-red-50',
}[props.tone] || 'text-slate-600 bg-slate-50'));
</script>
