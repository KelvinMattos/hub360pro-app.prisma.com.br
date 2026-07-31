<template>
    <div v-if="!items.length" class="text-slate-400 text-sm py-4 text-center">{{ emptyText }}</div>
    <div v-else class="space-y-3">
        <div v-for="(it, idx) in items" :key="idx" class="flex items-center gap-3">
            <div class="w-32 lg:w-36 shrink-0 text-sm font-semibold text-slate-600 truncate" :title="it[labelKey]">{{ it[labelKey] }}</div>
            <div class="flex-1 bg-slate-100 rounded-lg h-6 overflow-hidden">
                <div class="h-full rounded-lg" :class="barColor" :style="{ width: pct(it[valueKey]) + '%' }"></div>
            </div>
            <div class="w-36 lg:w-40 shrink-0 text-right text-xs font-mono text-slate-500">
                {{ money(it[valueKey]) }}<span v-if="countKey"> · {{ n(it[countKey]) }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    labelKey: { type: String, required: true },
    valueKey: { type: String, required: true },
    countKey: { type: String, default: null },
    emptyText: { type: String, default: 'Sem dados no período.' },
    barColor: { type: String, default: 'bg-teal-400' },
});

const max = computed(() => Math.max(1, ...props.items.map(i => i[props.valueKey] || 0)));

function pct(v) { return Math.round(((v || 0) / max.value) * 100); }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? 'R$ 0,00' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>
