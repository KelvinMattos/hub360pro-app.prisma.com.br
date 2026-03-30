<template>
    <AppLayout title="Market Share">
        <div class="p-8 max-w-7xl mx-auto">
            <div class="mb-10 text-center">
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                    Market <span class="bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent">Share</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Analise sua fatia de mercado e a concorrência.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-10">
                <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-premium flex flex-col items-center">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4">Sua Fatia</p>
                    <div class="relative w-32 h-32 flex items-center justify-center">
                        <svg class="w-full h-full transform -rotate-90">
                            <circle cx="64" cy="64" r="58" stroke="currentColor" stroke-width="12" fill="transparent" class="text-slate-100" />
                            <circle cx="64" cy="64" r="58" stroke="currentColor" stroke-width="12" fill="transparent" stroke-dasharray="364.4" stroke-dashoffset="310" class="text-emerald-500" />
                        </svg>
                        <span class="absolute text-2xl font-black text-slate-900">15%</span>
                    </div>
                    <p class="mt-4 text-xs text-slate-500 font-medium">MLB1648 - Informática</p>
                </div>

                <div class="md:col-span-2 bg-white p-8 rounded-3xl border border-slate-100 shadow-premium">
                    <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-6">Top Sellers na Categoria</h3>
                    <div class="space-y-4">
                        <div v-for="i in 3" :key="i" class="flex justify-between items-center p-4 bg-slate-50 rounded-2xl">
                            <div class="flex items-center gap-4">
                                <div class="w-8 h-8 bg-slate-200 rounded-full flex items-center justify-center font-bold text-slate-500">{{ i }}</div>
                                <span class="font-bold text-slate-900">Loja Competidora #{{ i }}</span>
                            </div>
                            <span class="text-sm font-black text-slate-400">~ 24.5% Share</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-indigo-900 text-white p-10 rounded-3xl flex flex-col md:flex-row justify-between items-center gap-6 shadow-2xl shadow-indigo-900/20">
                <div class="space-y-2 text-center md:text-left">
                    <h3 class="text-2xl font-black uppercase tracking-tight">Expandir sua Operação</h3>
                    <p class="text-indigo-200 font-medium">O Mercado Livre aponta crescimento de 12% nesta categoria para o próximo mês.</p>
                </div>
                <button class="px-8 py-4 bg-white text-indigo-900 rounded-2xl font-black uppercase tracking-widest hover:bg-indigo-50 transition-all shadow-lg">Descobrir Oportunidades</button>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>
