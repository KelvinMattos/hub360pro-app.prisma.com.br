<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4 mb-8">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-satellite-dish"></i> Monitoramento de Preços
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">
                        Competitividade <span class="text-slate-400 font-bold">em tempo real</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Como seus preços se comparam ao mercado, por status e por canal.</p>
                </div>
                <!-- Filtro de período -->
                <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-xl p-1 shadow-sm">
                    <Link v-for="p in periods" :key="p.v" :href="route('monitoring.dashboard', { days: p.v })"
                        :class="['px-3 py-1.5 rounded-lg text-xs font-bold transition',
                            days === p.v ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-100']">
                        {{ p.l }}
                    </Link>
                </div>
            </div>

            <div v-if="!has_data" class="bg-white border border-slate-200 rounded-2xl p-10 text-center shadow-sm">
                <i class="fa-solid fa-satellite-dish text-4xl text-slate-300"></i>
                <h3 class="text-lg font-bold text-slate-700 mt-4">Nenhum produto monitorado ainda</h3>
                <p class="text-slate-500 mt-1 text-sm">Cadastre o preço de mercado dos produtos na tela
                    <Link :href="route('monitoring.products')" class="text-blue-600 font-semibold">Produtos monitorados</Link>
                    para começar a acompanhar a competitividade.</p>
            </div>

            <template v-else>
                <!-- KPIs -->
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
                    <div class="kpi"><div class="kpi-l">Monitorados</div><div class="kpi-v">{{ n(kpis.monitorados) }}</div></div>
                    <div class="kpi ring-emerald"><div class="kpi-l">Vendendo</div><div class="kpi-v text-emerald-600">{{ n(kpis.vendendo) }}</div></div>
                    <div class="kpi ring-red"><div class="kpi-l">Perdendo</div><div class="kpi-v text-red-600">{{ n(kpis.perdendo) }}</div></div>
                    <div class="kpi ring-amber"><div class="kpi-l">Alerta</div><div class="kpi-v text-amber-600">{{ n(kpis.alerta) }}</div></div>
                    <div class="kpi ring-slate"><div class="kpi-l">Desconhecido</div><div class="kpi-v text-slate-400">{{ n(kpis.desconhecido) }}</div></div>
                    <div class="kpi ring-blue"><div class="kpi-l">Oportunidades</div><div class="kpi-v text-blue-600">{{ n(kpis.oportunidades) }}</div></div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <!-- Distribuição (donut) -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Distribuição de status</h2>
                        <div class="flex items-center gap-6">
                            <svg viewBox="0 0 42 42" class="w-32 h-32 shrink-0 -rotate-90">
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#f1f5f9" stroke-width="6"/>
                                <circle v-for="seg in donut" :key="seg.label" cx="21" cy="21" r="15.915" fill="transparent"
                                    :stroke="seg.color" stroke-width="6"
                                    :stroke-dasharray="`${seg.pct} ${100 - seg.pct}`"
                                    :stroke-dashoffset="seg.offset" />
                            </svg>
                            <div class="flex-1 space-y-2">
                                <div v-for="d in distribuicao" :key="d.label" class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2 text-slate-600">
                                        <span class="w-2.5 h-2.5 rounded-full" :style="{ background: d.color }"></span>{{ d.label }}
                                    </span>
                                    <span class="font-mono font-bold text-slate-800">{{ n(d.value) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Estimativas -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Oportunidade de otimização</h2>
                        <div class="space-y-4">
                            <div>
                                <div class="text-3xl font-extrabold text-blue-600">{{ n(kpis.oportunidades) }}</div>
                                <div class="text-sm text-slate-500">produtos a ≤ 5% do mercado — ajuste pequeno para voltar a vender.</div>
                            </div>
                            <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                                <div>
                                    <div class="text-lg font-bold text-slate-800">{{ money(kpis.exposicao) }}</div>
                                    <div class="text-xs text-slate-400">valor exposto (perdendo)</div>
                                </div>
                                <div>
                                    <div class="text-lg font-bold" :class="kpis.gap_medio > 0 ? 'text-red-600' : 'text-emerald-600'">{{ kpis.gap_medio }}%</div>
                                    <div class="text-xs text-slate-400">gap médio vs. mercado</div>
                                </div>
                            </div>
                            <Link :href="route('monitoring.products', { filter: 'perdendo' })" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
                                Ver produtos perdendo <i class="fa-solid fa-arrow-right text-xs"></i>
                            </Link>
                        </div>
                    </div>

                    <!-- Evolução -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Faturamento no período</h2>
                        <div v-if="evolucao.length" class="flex items-end gap-1 h-32">
                            <div v-for="(d, i) in evolucao" :key="i" class="flex-1 bg-blue-400/80 hover:bg-blue-500 rounded-t transition relative group"
                                :style="{ height: barH(d.total) }">
                                <div class="absolute bottom-full mb-1 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition bg-slate-900 text-white text-[10px] rounded px-1.5 py-0.5 whitespace-nowrap">
                                    {{ fmtDate(d.dia) }} · {{ money(d.total) }}
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-slate-400">Sem pedidos no período.</p>
                        <div class="mt-2 text-xs text-slate-400">{{ n(kpis.recentes_24h) }} produtos verificados nas últimas 24h.</div>
                    </div>
                </div>

                <!-- Por canal -->
                <div v-if="por_canal.length" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Por marketplace</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                    <th class="py-2 pr-4 font-bold">Canal</th>
                                    <th class="py-2 px-4 font-bold text-right">Monitorados</th>
                                    <th class="py-2 px-4 font-bold text-right">Vendendo</th>
                                    <th class="py-2 px-4 font-bold text-right">Perdendo</th>
                                    <th class="py-2 pl-4 font-bold w-40">Competitividade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in por_canal" :key="c.canal" class="border-b border-slate-50 hover:bg-slate-50/60">
                                    <td class="py-2.5 pr-4 font-semibold text-slate-700">{{ c.canal }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-600">{{ n(c.total) }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono text-emerald-600">{{ n(c.vendendo) }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono text-red-600">{{ n(c.perdendo) }}</td>
                                    <td class="py-2.5 pl-4">
                                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden flex">
                                            <div class="bg-emerald-500 h-full" :style="{ width: pctOf(c.vendendo, c.total) }"></div>
                                            <div class="bg-red-500 h-full" :style="{ width: pctOf(c.perdendo, c.total) }"></div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    days: { type: Number, default: 30 },
    kpis: { type: Object, default: () => ({}) },
    distribuicao: { type: Array, default: () => [] },
    por_canal: { type: Array, default: () => [] },
    evolucao: { type: Array, default: () => [] },
    has_data: { type: Boolean, default: false },
});

const periods = [
    { v: 1, l: 'Ontem' }, { v: 7, l: '7 dias' }, { v: 30, l: '1 mês' },
    { v: 90, l: '3 meses' }, { v: 180, l: '6 meses' },
];

const donutTotal = computed(() => props.distribuicao.reduce((s, d) => s + (d.value || 0), 0) || 1);
const donut = computed(() => {
    let acc = 0;
    return props.distribuicao.filter(d => d.value > 0).map(d => {
        const pct = Math.round(d.value / donutTotal.value * 100);
        const seg = { label: d.label, color: d.color, pct, offset: 25 - acc };
        acc += pct;
        return seg;
    });
});

const maxEvo = computed(() => Math.max(1, ...props.evolucao.map(d => d.total || 0)));
function barH(v) { return Math.max(3, Math.round((v || 0) / maxEvo.value * 100)) + '%'; }
function pctOf(v, t) { return (t ? Math.round(v / t * 100) : 0) + '%'; }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) { if (!d) return '—'; const p = String(d).slice(0, 10).split('-'); return `${p[2]}/${p[1]}`; }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl p-4 shadow-sm; }
.kpi-l { @apply text-[11px] font-bold uppercase tracking-wide text-slate-400; }
.kpi-v { @apply text-2xl font-extrabold text-slate-900 mt-1 font-mono; }
.ring-emerald { @apply border-emerald-100; }
.ring-red { @apply border-red-100; }
.ring-amber { @apply border-amber-100; }
.ring-slate { @apply border-slate-200; }
.ring-blue { @apply border-blue-100; }
</style>
