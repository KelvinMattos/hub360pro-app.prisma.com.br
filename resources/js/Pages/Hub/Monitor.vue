<script setup>
import { computed } from 'vue';
import { useForm, Link, Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// 1. Definição das Props (Recebidas via Inertia)
const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            processedToday: 0,
            pending: 0,
            failed: 0
        })
    },
    logs: {
        type: Array,
        default: () => []
    }
});

// 2. Formatação de Data
const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
};

// 3. Estilos de Status (Badges)
const getStatusClasses = (status) => {
    switch (status) {
        case 'processed':
            return 'bg-emerald-50 text-emerald-600 border-emerald-100 shadow-sm';
        case 'pending':
        case 'processing':
            return 'bg-amber-50 text-amber-600 border-amber-100 shadow-sm';
        case 'failed':
            return 'bg-rose-50 text-rose-600 border-rose-100 shadow-sm';
        default:
            return 'bg-slate-50 text-slate-600 border-slate-100';
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'processed': return 'Sucesso';
        case 'pending': return 'Pendente';
        case 'processing': return 'Processando';
        case 'failed': return 'Falha';
        default: return status;
    }
};

const getSourceIcon = (source) => {
    const s = source?.toLowerCase() || '';
    if (s.includes('mercadolibre')) return 'fa-brands fa-laravel text-yellow-600';
    if (s.includes('shopee')) return 'fa-solid fa-bag-shopping text-orange-600';
    if (s.includes('amazon')) return 'fa-brands fa-amazon text-slate-900';
    return 'fa-solid fa-satellite-dish text-primary';
};
</script>

<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            
            <!-- Header Monitor (Elite) -->
            <header class="mb-12 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-4xl font-black text-slate-900 tracking-tighter italic uppercase font-outfit">
                        Hub <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Monitor</span>
                    </h1>
                    <p class="text-slate-400 mt-2 font-medium flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        Fluxo de dados em tempo real (Webhooks & Background Jobs)
                    </p>
                </div>

                <div class="flex items-center gap-3">
                     <button @click="$inertia.reload()" class="bg-white hover:bg-slate-50 text-slate-700 px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border border-slate-200 shadow-sm active:scale-95 flex items-center gap-2">
                        <i class="fa-solid fa-rotate"></i> Atualizar Agora
                    </button>
                </div>
            </header>

            <!-- Linha 1: Status Cards (Elegant) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
                
                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 flex items-center gap-6 shadow-premium relative overflow-hidden group">
                    <div class="absolute -right-4 -top-4 bg-emerald-500/5 w-24 h-24 rounded-full blur-2xl group-hover:bg-emerald-500/10 transition-all"></div>
                    <div class="w-16 h-16 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-3xl border border-emerald-100">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                    <div>
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] block mb-1">Processados Hoje</span>
                        <div class="text-4xl font-black text-slate-900 tracking-tighter">{{ props.stats.processedToday }}</div>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 flex items-center gap-6 shadow-premium relative overflow-hidden group">
                    <div class="absolute -right-4 -top-4 bg-amber-500/5 w-24 h-24 rounded-full blur-2xl group-hover:bg-amber-500/10 transition-all"></div>
                    <div class="w-16 h-16 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center text-3xl border border-amber-100">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] block mb-1">Pendente / Fila</span>
                        <div class="text-4xl font-black text-slate-900 tracking-tighter">{{ props.stats.pending }}</div>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 flex items-center gap-6 shadow-premium relative overflow-hidden group border-b-rose-500/30">
                    <div class="absolute -right-4 -top-4 bg-rose-500/5 w-24 h-24 rounded-full blur-2xl group-hover:bg-rose-500/10 transition-all"></div>
                    <div class="w-16 h-16 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center text-3xl border border-rose-100">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <span class="text-[9px] font-black text-rose-600/50 uppercase tracking-[0.2em] block mb-1">Falhas (Alertas)</span>
                        <div class="text-4xl font-black text-rose-600 tracking-tighter">{{ props.stats.failed }}</div>
                    </div>
                </div>
            </div>

            <!-- Linha 2: Tabela de Logs (Elegant Light) -->
            <div class="bg-white rounded-[3rem] border border-slate-200 overflow-hidden shadow-premium">
                <div class="p-10 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 italic tracking-tight">Histórico de Eventos</h3>
                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Últimas 50 notificações capturadas pelo motor Prisma.</p>
                    </div>
                    <div class="flex items-center gap-2 px-4 py-2 bg-slate-50 rounded-xl border border-slate-100">
                        <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest">Auto-Refresh:</span>
                        <span class="text-xs font-bold text-emerald-600">ATIVO</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Timestamp</th>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Origem Dados</th>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Evento / Payload</th>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Status</th>
                                <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="log in props.logs" :key="log.id" class="hover:bg-slate-50 transition-colors group">
                                <td class="px-10 py-6">
                                    <span class="text-xs font-bold text-slate-400 font-mono">{{ formatDate(log.created_at) }}</span>
                                </td>
                                <td class="px-10 py-6">
                                    <div class="flex items-center gap-4">
                                        <div class="w-10 h-10 bg-slate-50 rounded-xl flex items-center justify-center text-sm border border-slate-100 group-hover:border-primary/30 transition-all">
                                            <i :class="getSourceIcon(log.source)"></i>
                                        </div>
                                        <span class="text-[10px] font-black text-slate-900 uppercase tracking-widest italic">{{ log.source }}</span>
                                    </div>
                                </td>
                                <td class="px-10 py-6">
                                    <div>
                                        <p class="text-xs font-bold text-slate-700">{{ log.event_type }}</p>
                                        <p class="text-[9px] text-slate-400 font-mono mt-1 opacity-50 group-hover:opacity-100 transition-opacity truncate max-w-[250px]">
                                            {{ JSON.stringify(log.payload) }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-10 py-6">
                                    <div :class="['inline-flex items-center px-4 py-1.5 rounded-full border text-[9px] font-black uppercase tracking-widest shadow-sm', getStatusClasses(log.status)]">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2 animate-pulse" v-if="log.status === 'processing'"></span>
                                        {{ getStatusLabel(log.status) }}
                                    </div>
                                </td>
                                <td class="px-10 py-6 text-right">
                                    <button class="w-10 h-10 bg-slate-50 rounded-xl text-slate-400 hover:text-white hover:bg-primary transition-all active:scale-90 border border-slate-100">
                                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="props.logs.length === 0">
                                <td colspan="5" class="px-10 py-28 text-center">
                                    <div class="flex flex-col items-center gap-6 opacity-20">
                                        <i class="fa-solid fa-satellite-dish text-7xl animate-bounce text-slate-400"></i>
                                        <p class="text-sm font-black uppercase tracking-[0.5em] text-slate-900">Escaneando rede Prisma...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
