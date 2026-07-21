<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1500px] mx-auto">
            <!-- Header -->
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-chart-simple text-teal-500"></i>
                        Análise de <span class="bg-gradient-to-r from-teal-500 to-emerald-600 bg-clip-text text-transparent">Vendas</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Faturamento por canal, status e tendência — sobre os pedidos importados do Magazord.</p>
                </div>
                <div class="flex gap-1 bg-white border border-slate-200 rounded-lg p-1">
                    <button v-for="d in [7, 30, 90, 365]" :key="d" @click="setDays(d)"
                        :class="['px-3 py-1.5 rounded-md text-sm font-semibold transition', days === d ? 'bg-teal-500 text-white' : 'text-slate-500 hover:bg-slate-50']">
                        {{ d === 365 ? '1 ano' : d + 'd' }}
                    </button>
                </div>
            </div>

            <div v-if="!has_data" class="bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Nenhuma venda no período. Importe o modelo <b>Vendas</b> em Importações Magazord para alimentar esta análise.
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="kpi"><div class="kpi-l">Faturamento</div><div class="kpi-v text-emerald-600">{{ money(kpis.faturamento) }}</div></div>
                <div class="kpi"><div class="kpi-l">Pedidos faturados</div><div class="kpi-v">{{ n(kpis.pedidos) }}</div></div>
                <div class="kpi"><div class="kpi-l">Ticket médio</div><div class="kpi-v">{{ money(kpis.ticket) }}</div></div>
                <div class="kpi kpi-warn"><div class="kpi-l">Cancelados</div><div class="kpi-v text-red-600">{{ n(kpis.cancelados) }}</div><div class="kpi-s">{{ money(kpis.cancelado_valor) }}</div></div>
            </div>

            <!-- Tendência diária -->
            <div v-if="por_dia.length" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Faturamento por dia</h3>
                <div class="flex items-end gap-1 h-40">
                    <div v-for="d in por_dia" :key="d.dia" class="flex-1 bg-teal-400/80 hover:bg-teal-500 rounded-t transition relative group"
                        :style="{ height: barH(d.total) + '%' }" :title="fmtDate(d.dia) + ' · ' + money(d.total)">
                        <div class="absolute -top-6 left-1/2 -translate-x-1/2 text-[10px] font-mono text-slate-600 opacity-0 group-hover:opacity-100 whitespace-nowrap bg-white px-1 rounded shadow-sm">{{ money(d.total) }}</div>
                    </div>
                </div>
                <div class="flex justify-between text-[10px] text-slate-400 mt-2 font-mono">
                    <span>{{ fmtDate(por_dia[0].dia) }}</span>
                    <span>{{ fmtDate(por_dia[por_dia.length - 1].dia) }}</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Por canal -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Por canal</h3>
                    <div v-if="!por_canal.length" class="text-slate-400 text-sm py-4 text-center">Sem dados de canal.</div>
                    <div v-else class="space-y-3">
                        <div v-for="c in por_canal" :key="c.canal" class="flex items-center gap-3">
                            <div class="w-36 shrink-0 text-sm font-semibold text-slate-600 truncate" :title="c.canal">{{ c.canal }}</div>
                            <div class="flex-1 bg-slate-100 rounded-lg h-6 overflow-hidden"><div class="h-full bg-teal-400 rounded-lg" :style="{ width: pct(c.total, maxCanal) + '%' }"></div></div>
                            <div class="w-40 shrink-0 text-right text-xs font-mono text-slate-500">{{ money(c.total) }} · {{ n(c.pedidos) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Por status -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Por status</h3>
                    <div v-if="!por_status.length" class="text-slate-400 text-sm py-4 text-center">Sem dados de status.</div>
                    <table v-else class="w-full text-sm">
                        <tbody>
                            <tr v-for="s in por_status" :key="s.status" class="border-b border-slate-100">
                                <td class="py-2"><span class="pill" :class="statusClass(s.status)">{{ statusLabel(s.status) }}</span></td>
                                <td class="py-2 text-right font-mono text-slate-500">{{ n(s.pedidos) }} ped.</td>
                                <td class="py-2 text-right font-mono text-slate-700">{{ money(s.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recentes -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Pedidos recentes</h3>
                <div v-if="!recentes.length" class="text-slate-400 text-sm py-4 text-center">Nenhum pedido no período.</div>
                <div v-else class="overflow-auto max-h-[50vh] border border-slate-200 rounded-xl">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-slate-50 z-10">
                            <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                                <th class="th-l">Pedido</th><th class="th-l">Cliente</th><th class="th-l">Canal</th><th class="th-l">Status</th><th class="th-r">Total</th><th class="th-r">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in recentes" :key="r.pedido" class="border-t border-slate-100 hover:bg-slate-50/60">
                                <td class="td-l font-mono">{{ r.pedido }}</td>
                                <td class="td-l max-w-[220px] truncate" :title="r.cliente">{{ r.cliente }}</td>
                                <td class="td-l">{{ r.canal }}</td>
                                <td class="td-l"><span class="pill" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span></td>
                                <td class="td-r font-semibold">{{ money(r.total) }}</td>
                                <td class="td-r text-slate-400">{{ fmtDateTime(r.data) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    days: { type: Number, default: 30 },
    kpis: { type: Object, default: () => ({}) },
    por_canal: { type: Array, default: () => [] },
    por_status: { type: Array, default: () => [] },
    por_dia: { type: Array, default: () => [] },
    recentes: { type: Array, default: () => [] },
    has_data: { type: Boolean, default: false },
});

const maxCanal = computed(() => Math.max(1, ...props.por_canal.map(c => c.total || 0)));
const maxDia = computed(() => Math.max(1, ...props.por_dia.map(d => d.total || 0)));

function setDays(d) { router.get(route('sales.index'), { days: d }, { preserveScroll: true, preserveState: false }); }
function pct(v, max) { return Math.round((v / max) * 100); }
function barH(v) { return Math.max(2, Math.round((v / maxDia.value) * 100)); }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? 'R$ 0,00' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) { if (!d) return '—'; const [y, m, day] = String(d).slice(0, 10).split('-'); return `${day}/${m}`; }
function fmtDateTime(d) { if (!d) return '—'; const s = String(d); const [date] = s.split(' '); const [y, m, day] = date.split('-'); return `${day}/${m}/${y}`; }

const STATUS = {
    approved: ['Aprovado', 'pill-green'], paid: ['Pago', 'pill-green'], delivered: ['Entregue', 'pill-blue'],
    shipped: ['Enviado', 'pill-blue'], cancelled: ['Cancelado', 'pill-red'], pending: ['Pendente', 'pill-idle'],
};
function statusLabel(s) { return STATUS[s]?.[0] || (s || '—'); }
function statusClass(s) { return STATUS[s]?.[1] || 'pill-idle'; }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl px-5 py-4 shadow-sm; }
.kpi-warn { @apply border-amber-200 bg-amber-50/40; }
.kpi-l { @apply text-slate-400 text-[11px] uppercase tracking-wide; }
.kpi-v { @apply font-mono text-xl font-bold text-slate-900 mt-1; }
.kpi-s { @apply text-slate-400 text-[10px] mt-0.5 font-mono; }
.th-l { @apply text-left font-semibold px-3 py-2 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-3 py-2 whitespace-nowrap; }
.td-l { @apply text-left px-3 py-1.5 whitespace-nowrap; }
.td-r { @apply text-right px-3 py-1.5 font-mono whitespace-nowrap; }
.pill { @apply text-[10px] font-bold px-2 py-0.5 rounded-full; }
.pill-green { @apply bg-emerald-100 text-emerald-700; }
.pill-blue { @apply bg-blue-100 text-blue-700; }
.pill-red { @apply bg-red-100 text-red-700; }
.pill-idle { @apply bg-slate-100 text-slate-500; }
</style>
