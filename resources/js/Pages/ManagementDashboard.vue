<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1500px] mx-auto">
            <!-- Header -->
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-gauge-high text-blue-500"></i>
                        Dashboard <span class="bg-gradient-to-r from-blue-500 to-indigo-600 bg-clip-text text-transparent">Gerencial</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Vendas, capital e saúde de margem num só lugar — com atalho para as decisões prioritárias.</p>
                </div>
                <Link :href="route('decision.index')" class="btn-primary text-sm"><i class="fa-solid fa-chess-king mr-2"></i>Centro de Decisão</Link>
            </div>

            <!-- Vendas -->
            <h2 class="section-title">Vendas (pedidos faturados)</h2>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="kpi"><div class="kpi-l">Faturamento 30 dias</div><div class="kpi-v text-emerald-600">{{ money(sales.rev30) }}</div></div>
                <div class="kpi"><div class="kpi-l">Faturado hoje</div><div class="kpi-v">{{ money(sales.rev_today) }}</div></div>
                <div class="kpi"><div class="kpi-l">Pedidos (30 dias)</div><div class="kpi-v">{{ n(sales.orders30) }}</div></div>
                <div class="kpi"><div class="kpi-l">Ticket médio</div><div class="kpi-v">{{ money(sales.ticket) }}</div></div>
            </div>

            <!-- Capital & margem -->
            <h2 class="section-title">Capital & margem <span v-if="channel.label" class="text-slate-400 font-normal normal-case tracking-normal">· canal {{ channel.label }}</span></h2>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="kpi"><div class="kpi-l">Capital imobilizado</div><div class="kpi-v">{{ money(kpis.capital_imobilizado) }}</div></div>
                <div class="kpi"><div class="kpi-l">Lucro potencial</div><div class="kpi-v" :class="(kpis.lucro_potencial ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ money(kpis.lucro_potencial) }}</div></div>
                <div class="kpi"><div class="kpi-l">Margem média</div><div class="kpi-v" :class="(kpis.margem_media_pct ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ kpis.margem_media_pct ?? 0 }}%</div></div>
                <div class="kpi kpi-warn"><div class="kpi-l">Parado há +1 ano</div><div class="kpi-v text-amber-600">{{ money(kpis.capital_parado_antigo) }}</div></div>
            </div>

            <!-- Alertas de decisão -->
            <h2 class="section-title">Ações prioritárias</h2>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <Link :href="route('decision.index')" class="alert-card ring-red-100 hover:ring-red-300">
                    <div class="flex items-center justify-between"><i class="fa-solid fa-arrow-trend-down text-red-500 text-xl"></i><span class="text-2xl font-black font-mono text-red-600">{{ n(kpis.count_prejuizo) }}</span></div>
                    <div class="mt-2 font-bold text-sm text-slate-800">Vendendo no prejuízo</div>
                </Link>
                <Link :href="route('decision.index')" class="alert-card ring-orange-100 hover:ring-orange-300">
                    <div class="flex items-center justify-between"><i class="fa-solid fa-fire text-orange-500 text-xl"></i><span class="text-2xl font-black font-mono text-orange-600">{{ n(kpis.count_promo_perigosa) }}</span></div>
                    <div class="mt-2 font-bold text-sm text-slate-800">Promoção perigosa</div>
                </Link>
                <Link :href="route('decision.index')" class="alert-card ring-amber-100 hover:ring-amber-300">
                    <div class="flex items-center justify-between"><i class="fa-solid fa-box-archive text-amber-500 text-xl"></i><span class="text-2xl font-black font-mono text-amber-600">{{ n(kpis.count_liquidar) }}</span></div>
                    <div class="mt-2 font-bold text-sm text-slate-800">Liquidar</div>
                </Link>
                <Link :href="route('decision.index')" class="alert-card ring-emerald-100 hover:ring-emerald-300">
                    <div class="flex items-center justify-between"><i class="fa-solid fa-bullseye text-emerald-500 text-xl"></i><span class="text-2xl font-black font-mono text-emerald-600">{{ n(kpis.count_oportunidade) }}</span></div>
                    <div class="mt-2 font-bold text-sm text-slate-800">Oportunidade</div>
                </Link>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Vendas por canal -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Faturamento por canal (30 dias)</h3>
                    <div v-if="!sales.por_canal.length" class="text-slate-400 text-sm py-6 text-center">
                        Sem vendas registradas. Importe o modelo <b>Vendas</b> em Importações Magazord.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="c in sales.por_canal" :key="c.canal" class="flex items-center gap-3">
                            <div class="w-32 shrink-0 text-sm font-semibold text-slate-600 truncate" :title="c.canal">{{ c.canal }}</div>
                            <div class="flex-1 bg-slate-100 rounded-lg h-6 overflow-hidden">
                                <div class="h-full bg-blue-400 rounded-lg" :style="{ width: barPct(c.total) + '%' }"></div>
                            </div>
                            <div class="w-40 shrink-0 text-right text-xs font-mono text-slate-500">{{ money(c.total) }} · {{ n(c.pedidos) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Top prejuízo -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Maiores prejuízos (corrigir preço)</h3>
                    <div v-if="!(alertas.prejuizo && alertas.prejuizo.length)" class="text-slate-400 text-sm py-6 text-center">
                        <i class="fa-solid fa-circle-check text-emerald-400 mr-1"></i> Nenhum produto no prejuízo neste canal.
                    </div>
                    <table v-else class="w-full text-xs">
                        <thead><tr class="text-slate-400 text-[10px] uppercase"><th class="text-left py-1">SKU</th><th class="text-left py-1">Produto</th><th class="text-right py-1">Preço</th><th class="text-right py-1">Margem</th></tr></thead>
                        <tbody>
                            <tr v-for="r in alertas.prejuizo" :key="r.sku" class="border-t border-slate-100">
                                <td class="py-1.5 font-mono">{{ r.sku }}</td>
                                <td class="py-1.5 max-w-[200px] truncate" :title="r.title">{{ r.title }}</td>
                                <td class="py-1.5 text-right font-mono">{{ money(r.price) }}</td>
                                <td class="py-1.5 text-right font-mono text-red-600 font-semibold">{{ money(r.margin_unit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-xs text-slate-400 mt-8">
                Atalhos: <Link :href="route('calculator.index')" class="text-blue-600 hover:underline">Calculadora de Canais</Link> ·
                <Link :href="route('inventory.aging')" class="text-blue-600 hover:underline">Aging de Estoque</Link> ·
                <Link :href="route('magazord.show', { type: 'vendas' })" class="text-blue-600 hover:underline">Importar Vendas</Link>
            </p>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    sales: { type: Object, default: () => ({ por_canal: [] }) },
    kpis: { type: Object, default: () => ({}) },
    channel: { type: Object, default: () => ({}) },
    alertas: { type: Object, default: () => ({}) },
});

const maxCanal = computed(() => Math.max(1, ...(props.sales.por_canal || []).map(c => c.total || 0)));
function barPct(v) { return Math.round((v / maxCanal.value) * 100); }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? 'R$ 0,00' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<style scoped>
.section-title { @apply text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-3; }
.kpi { @apply bg-white border border-slate-200 rounded-2xl px-5 py-4 shadow-sm; }
.kpi-warn { @apply border-amber-200 bg-amber-50/40; }
.kpi-l { @apply text-slate-400 text-[11px] uppercase tracking-wide; }
.kpi-v { @apply font-mono text-xl font-bold text-slate-900 mt-1; }
.alert-card { @apply bg-white rounded-2xl px-5 py-4 shadow-sm ring-1 transition; }
.btn-primary { @apply bg-blue-500 hover:bg-blue-600 text-white font-semibold rounded-lg px-4 py-2 transition shadow-sm; }
</style>
