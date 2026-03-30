<template>
    <div 
        :class="[
            'px-8 py-5 flex justify-between items-center transition-all border-b border-slate-100',
            type === 'primary' ? 'bg-blue-600/5' : '',
            type === 'highlight' ? 'bg-emerald-600/5 border-emerald-500/10' : 'hover:bg-slate-50'
        ]"
    >
        <span 
            :class="[
                'text-sm tracking-wide',
                type === 'primary' ? 'font-black text-slate-900' : 'font-medium text-slate-500',
                type === 'highlight' ? 'font-black text-slate-900 text-base' : ''
            ]"
        >
            {{ label }}
        </span>
        
        <span 
            :class="[
                'font-mono text-sm font-bold',
                type === 'primary' ? 'text-slate-900 text-lg' : '',
                type === 'highlight' ? 'text-emerald-600 text-xl' : '',
                value < 0 ? 'text-red-600' : (value > 0 && type !== 'primary' && type !== 'highlight' ? 'text-emerald-600' : 'text-slate-600')
            ]"
        >
            {{ value < 0 ? '- ' : '' }}R$ {{ formatCurrency(Math.abs(value)) }}
        </span>
    </div>
</template>

<script setup>
defineProps({
    label: String,
    value: [Number, String],
    type: {
        type: String,
        default: 'default' // default, primary, highlight
    }
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value || 0);
};
</script>
