<template>
    <div v-if="links.length > 3">
        <div class="flex flex-wrap -mb-1">
            <template v-for="(link, key) in links" :key="key">
                <div 
                    v-if="link.url === null" 
                    class="mr-1 mb-1 px-4 py-3 text-sm leading-4 text-slate-400 border border-slate-100 rounded-xl"
                    v-html="link.label" 
                />
                <Link 
                    v-else 
                    class="mr-1 mb-1 px-4 py-3 text-sm leading-4 border rounded-xl hover:bg-blue-600 hover:text-white focus:border-blue-500 focus:text-blue-500 transition-all font-bold" 
                    :class="{ 'bg-blue-600 border-blue-600 text-white shadow-lg shadow-blue-500/20': link.active, 'bg-white border-slate-200 text-slate-500 hover:bg-slate-50': !link.active }" 
                    :href="link.url" 
                    v-html="link.label" 
                />
            </template>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: Array,
});
</script>
