<template>
    <AppLayout>
        <div class="p-8 max-w-6xl mx-auto">
            <div class="flex justify-between items-center mb-10">
                <div>
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Configurações do <span class="text-blue-500">Sistema</span></h1>
                    <p class="text-slate-500 font-medium italic mt-1">Gerenciamento de IA, taxas de mercado e redundância.</p>
                </div>
                <button @click="forceUpdate" class="bg-indigo-600 hover:bg-indigo-500 text-slate-900 px-6 py-3 rounded-2xl font-bold transition-all flex items-center gap-2 shadow-lg shadow-indigo-600/20 active:scale-95">
                    <i class="fa-solid fa-robot"></i>
                    IA: Atualizar Taxas
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- AI Keys Section -->
                <div class="lg:col-span-2 space-y-8">
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl">
                        <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-2">
                            <i class="fa-solid fa-key text-blue-500"></i> Chaves de Redundância (IA)
                        </h2>

                        <div class="space-y-4 mb-8">
                            <div v-for="key in keys" :key="key.id" class="flex items-center justify-between p-4 bg-black/20 rounded-2xl border border-slate-800">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-1">{{ key.provider }}</p>
                                    <p class="text-sm font-mono text-slate-300">••••••••••••{{ key.api_key.slice(-8) }}</p>
                                </div>
                                <button @click="deleteKey(key.id)" class="text-slate-600 hover:text-red-400 transition-colors p-2">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>

                        <form @submit.prevent="addKey" class="grid grid-cols-1 md:grid-cols-12 gap-4">
                            <div class="md:col-span-4">
                                <select v-model="newKey.provider" class="w-full bg-[#0F172A] border border-slate-700 text-slate-900 rounded-xl px-4 py-3 focus:border-blue-500 focus:outline-none">
                                    <option value="" disabled>Provedor</option>
                                    <option value="openai">OpenAI</option>
                                    <option value="anthropic">Anthropic</option>
                                    <option value="gemini">Google Gemini</option>
                                </select>
                            </div>
                            <div class="md:col-span-6">
                                <input v-model="newKey.api_key" type="password" placeholder="Nova API Key" class="w-full bg-[#0F172A] border border-slate-700 text-slate-900 rounded-xl px-4 py-3 focus:border-blue-500 focus:outline-none">
                            </div>
                            <div class="md:col-span-2">
                                <button type="submit" class="w-full h-full bg-blue-600 hover:bg-blue-500 text-slate-900 rounded-xl transition-all active:scale-95">
                                    <i class="fa-solid fa-plus font-bold"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Benchmark Rates -->
                <div>
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl">
                        <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-2">
                            <i class="fa-solid fa-chart-bar text-emerald-500"></i> Benchmarks
                        </h2>

                        <div class="space-y-6">
                            <div v-for="rate in rates" :key="rate.id" class="p-4 bg-emerald-500/5 rounded-2xl border border-emerald-500/10">
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-1">{{ rate.platform }}</p>
                                <div class="flex justify-between items-end">
                                    <span class="text-2xl font-black text-slate-900">{{ rate.rate }}%</span>
                                    <span class="text-[10px] text-slate-500 font-medium italic">Última att: {{ rate.updated_at ? new Date(rate.updated_at).toLocaleDateString() : 'Nesc' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    keys: Array,
    rates: Array
});

const newKey = reactive({
    provider: '',
    api_key: ''
});

const addKey = () => {
    if (!newKey.provider || !newKey.api_key) return;
    router.post(route('admin.keys.store'), newKey, {
        onSuccess: () => {
            newKey.provider = '';
            newKey.api_key = '';
        }
    });
};

const deleteKey = (id) => {
    if (confirm('Remover esta chave de redundância?')) {
        router.delete(route('admin.keys.delete', id));
    }
};

const forceUpdate = () => {
    router.post(route('admin.force_update'), {}, {
        preserveScroll: true
    });
};
</script>
