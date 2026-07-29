<template>
    <AppLayout title="Market Share">
        <div class="p-8 max-w-7xl mx-auto">
            <div class="mb-10 text-center">
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                    Market <span class="bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent">Share</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Analise sua fatia de mercado e a concorrência.</p>
            </div>

            <div class="bg-white p-10 rounded-3xl border border-slate-100 shadow-premium text-center max-w-2xl mx-auto">
                <i class="fa-solid fa-chart-pie text-4xl text-slate-300 mb-4"></i>
                <h3 class="text-lg font-bold text-slate-700">Dados insuficientes</h3>
                <p class="mt-2 text-sm text-slate-500 font-medium" title="dados insuficientes">
                    Ainda não há uma fonte de dados real para participação de mercado por categoria —
                    isso depende de uma API oficial de seller (Mercado Livre/Magalu) ainda não integrada.
                    Nenhum número é exibido aqui até que essa fonte exista.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>
