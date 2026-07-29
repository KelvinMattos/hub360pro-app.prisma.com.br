<template>
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-3">{{ title }}</h3>
        <div class="flex flex-wrap gap-1.5">
            <button v-for="item in items" :key="item.key"
                @click="$emit('select', activeValue === item.key ? null : item.key)"
                :class="['px-2.5 py-1 rounded-lg text-xs font-bold border transition',
                    activeValue === item.key ? 'bg-slate-900 text-white border-slate-900' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100']">
                {{ item.label }} ({{ item.count }})
            </button>
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: { type: String, required: true },
    items: { type: Array, default: () => [] },
    activeValue: { type: String, default: null },
});
defineEmits(['select']);
</script>
