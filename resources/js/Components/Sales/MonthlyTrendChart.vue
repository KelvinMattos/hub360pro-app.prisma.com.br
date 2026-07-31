<template>
    <div v-if="!items.length" class="text-slate-400 text-sm py-4 text-center">Sem histórico mensal ainda.</div>
    <div v-else>
        <div class="flex items-end gap-1.5 h-48">
            <div v-for="m in items" :key="m.mes"
                class="flex-1 bg-teal-400/80 hover:bg-teal-500 rounded-t transition relative group"
                :style="{ height: barH(m.total) + '%' }">
                <div class="absolute -top-11 left-1/2 -translate-x-1/2 text-[10px] font-mono text-slate-600 opacity-0 group-hover:opacity-100 whitespace-nowrap bg-white px-2 py-1 rounded shadow-md border border-slate-100 z-10 text-center">
                    <div class="font-bold">{{ money(m.total) }}</div>
                    <div class="text-slate-400">{{ m.pedidos }} pedido(s)</div>
                </div>
            </div>
        </div>
        <div class="flex mt-2">
            <span v-for="m in items" :key="m.mes" class="flex-1 text-center text-[10px] text-slate-400 font-mono">{{ m.label }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ items: { type: Array, default: () => [] } });

const max = computed(() => Math.max(1, ...props.items.map(m => m.total || 0)));

function barH(v) { return Math.max(2, Math.round(((v || 0) / max.value) * 100)); }
function money(v) { return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>
