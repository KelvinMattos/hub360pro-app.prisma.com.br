<template>
    <AppLayout title="Tendências de Mercado">
        <div class="p-8 max-w-7xl mx-auto">
            <div class="mb-10 text-center">
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                    Market <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Trends</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Descubra o que o seu cliente está buscando agora.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <!-- Category Sidebar (Simulated or fetched) -->
                <div class="md:col-span-1 space-y-4">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest px-4">Categorias Populares</h3>
                    <div class="space-y-1">
                        <button 
                            v-for="cat in categories" 
                            :key="cat.id"
                            @click="selectCategory(cat)"
                            :class="['w-full text-left px-4 py-3 rounded-2xl font-bold text-sm transition-all', selectedCategory.id === cat.id ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'text-slate-600 hover:bg-slate-50']"
                        >
                            {{ cat.name }}
                        </button>
                    </div>
                </div>

                <!-- Trends View -->
                <div class="md:col-span-3">
                    <div v-if="isLoading" class="flex flex-col items-center justify-center p-20 text-slate-400">
                        <i class="fa-solid fa-circle-notch fa-spin text-4xl mb-4"></i>
                        <p class="font-bold uppercase tracking-widest text-xs">Capturando Tendências...</p>
                    </div>

                    <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div v-for="(trend, index) in trends" :key="index" class="bg-white border border-slate-200 p-6 rounded-3xl hover:border-indigo-500 transition-all group shadow-premium flex justify-between items-center cursor-pointer">
                            <div class="flex items-center gap-4">
                                <span class="text-2xl font-black text-slate-200 group-hover:text-indigo-100 transition-colors">#{{ index + 1 }}</span>
                                <span class="font-bold text-slate-900">{{ trend.keyword }}</span>
                            </div>
                            <i class="fa-solid fa-arrow-right-long text-slate-300 group-hover:text-indigo-500 transition-all"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const categories = [
    { id: 'MLB1648', name: 'Informática' },
    { id: 'MLB1051', name: 'Celulares' },
    { id: 'MLB1039', name: 'Câmeras' },
    { id: 'MLB1144', name: 'Games' },
    { id: 'MLB438474', name: 'Moda' },
];

const selectedCategory = ref(categories[0]);
const trends = ref([]);
const isLoading = ref(false);

const fetchTrends = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get(route('meli.trends.search'), {
            params: { category_id: selectedCategory.value.id }
        });
        trends.value = response.data;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const selectCategory = (cat) => {
    selectedCategory.value = cat;
    fetchTrends();
};

onMounted(() => {
    fetchTrends();
});
</script>
