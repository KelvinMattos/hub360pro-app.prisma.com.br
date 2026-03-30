<template>
    <Link 
        :href="href" 
        :class="[
            'flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 group',
            active 
                ? 'bg-blue-600/5 text-blue-600 font-bold' 
                : 'hover:bg-slate-50 text-slate-500 hover:text-slate-900'
        ]"
    >
        <div 
            :class="[
                'flex items-center justify-center w-8 h-8 rounded-xl transition-all duration-300',
                active 
                    ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' 
                    : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200 group-hover:text-slate-600'
            ]"
        >
            <i :class="[icon, 'text-sm']"></i>
        </div>
        <span class="text-sm tracking-wide">
            <slot />
        </span>

        <!-- Active Indicator -->
        <div 
            v-if="active" 
            class="ml-auto w-1.5 h-1.5 rounded-full bg-blue-600 shadow-[0_0_10px_rgba(37,99,235,0.3)]"
        ></div>
    </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: String,
    active: Boolean,
    icon: String,
});
</script>
