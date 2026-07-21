<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1400px] mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-hourglass-half text-amber-500"></i>
                    Aging de <span class="bg-gradient-to-r from-amber-500 to-orange-600 bg-clip-text text-transparent">Estoque</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Distribuição do estoque por idade (Data de Lançamento) e valor imobilizado — para decidir promoções e liquidações com base em dados reais.
                </p>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="kpi"><div class="kpi-v">{{ n(stats.total_skus) }}</div><div class="kpi-l">SKUs com estoque</div></div>
                <div class="kpi"><div class="kpi-v">{{ n(stats.total_units) }}</div><div class="kpi-l">unidades</div></div>
                <div class="kpi"><div class="kpi-v">{{ money(stats.total_cost_value) }}</div><div class="kpi-l">valor imobilizado (custo)</div></div>
                <div class="kpi kpi-warn"><div class="kpi-v text-amber-600">{{ money(stats.aged_cost_value) }}</div><div class="kpi-l">parado há +1 ano</div></div>
                <div class="kpi kpi-warn"><div class="kpi-v text-amber-600">{{ stats.aged_pct }}%</div><div class="kpi-l">do valor com +1 ano</div></div>
            </div>

            <!-- Distribuição por faixa -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-8">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-5">Valor em estoque por faixa de idade</h2>
                <div class="space-y-3">
                    <div v-for="b in buckets" :key="b.label" class="flex items-center gap-4">
                        <div class="w-32 shrink-0 text-sm font-semibold text-slate-600 text-right">{{ b.label }}</div>
                        <div class="flex-1 bg-slate-100 rounded-lg h-7 relative overflow-hidden">
                            <div class="h-full rounded-lg transition-all" :class="barColor(b.label)"
                                :style="{ width: pctOfMax(b.cost_value) + '%' }"></div>
                            <div class="absolute inset-0 flex items-center px-3 text-xs font-mono text-slate-700">
                                {{ money(b.cost_value) }}
                            </div>
                        </div>
                        <div class="w-40 shrink-0 text-xs text-slate-400 font-mono text-right">
                            {{ n(b.count) }} SKUs · {{ n(b.units) }} un.
                        </div>
                    </div>
                </div>
                <p v-if="stats.without_date" class="text-xs text-slate-400 mt-4">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    {{ n(stats.without_date) }} SKUs estão em "Sem data" — importe o modelo <b>Produtos & Datas</b> (Data de Lançamento) para classificá-los.
                </p>
            </div>

            <!-- Produtos mais antigos -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400">Produtos mais antigos em estoque</h2>
                    <input v-model="search" type="text" placeholder="Buscar SKU ou produto..." class="cfg-input !w-64 font-mono">
                </div>
                <div class="overflow-auto max-h-[60vh] border border-slate-200 rounded-xl">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-slate-50 z-10">
                            <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                                <th class="th-l">SKU</th><th class="th-l">Produto</th><th class="th-l">Marca</th>
                                <th class="th-r">Lançamento</th><th class="th-r">Idade</th><th class="th-r">Faixa</th>
                                <th class="th-r">Estoque</th><th class="th-r">Custo un.</th><th class="th-r">Valor parado</th><th class="th-r">Preço venda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in filtered" :key="r.sku" class="border-t border-slate-100 hover:bg-slate-50/60">
                                <td class="td-l">{{ r.sku }}</td>
                                <td class="td-l max-w-[280px] truncate" :title="r.title">{{ r.title }}</td>
                                <td class="td-l">{{ r.brand || '—' }}</td>
                                <td class="td-r">{{ fmtDate(r.launched_at) }}</td>
                                <td class="td-r">{{ ageLabel(r.age_months) }}</td>
                                <td class="td-r"><span class="pill" :class="barColor(r.bucket)">{{ r.bucket }}</span></td>
                                <td class="td-r">{{ n(r.stock) }}</td>
                                <td class="td-r">{{ money(r.cost_price) }}</td>
                                <td class="td-r font-semibold text-amber-700">{{ money(r.stock_value) }}</td>
                                <td class="td-r">{{ money(r.sale_price) }}</td>
                            </tr>
                            <tr v-if="!filtered.length"><td colspan="10" class="text-center text-slate-400 py-8">Nenhum produto com data de lançamento e estoque. Importe o modelo "Produtos & Datas".</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    buckets: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    oldest: { type: Array, default: () => [] },
});

const search = ref('');

const maxCost = computed(() => Math.max(1, ...props.buckets.map(b => b.cost_value || 0)));
function pctOfMax(v) { return Math.round((v / maxCost.value) * 100); }

const filtered = computed(() => {
    const s = search.value.trim().toLowerCase();
    if (!s) return props.oldest;
    return props.oldest.filter(r => r.sku.toLowerCase().includes(s) || String(r.title).toLowerCase().includes(s));
});

function barColor(label) {
    switch (label) {
        case 'Menos de 6 meses': return 'bg-emerald-400';
        case 'Mais de 6 meses': return 'bg-lime-400';
        case '1 ano': return 'bg-amber-400';
        case '1 ano e meio': return 'bg-orange-400';
        case '2 anos': return 'bg-red-400';
        case '+2 anos': return 'bg-red-500';
        default: return 'bg-slate-300';
    }
}
function ageLabel(months) {
    if (months == null) return '—';
    if (months < 12) return months + ' m';
    const y = Math.floor(months / 12), m = months % 12;
    return m ? `${y}a ${m}m` : `${y}a`;
}
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) {
    if (!d) return '—';
    const [y, m, day] = d.split('-');
    return `${day}/${m}/${y}`;
}
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl px-5 py-4 shadow-sm; }
.kpi-warn { @apply border-amber-200 bg-amber-50/40; }
.kpi-v { @apply font-mono text-2xl font-bold text-slate-900; }
.kpi-l { @apply text-slate-400 text-[11px] uppercase tracking-wide mt-1; }
.cfg-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-1.5 text-sm outline-none focus:border-amber-400 transition; }
.th-l { @apply text-left font-semibold px-3 py-2 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-3 py-2 whitespace-nowrap; }
.td-l { @apply text-left px-3 py-1.5 whitespace-nowrap; }
.td-r { @apply text-right px-3 py-1.5 font-mono whitespace-nowrap; }
.pill { @apply text-white text-[10px] font-bold px-2 py-0.5 rounded-full; }
</style>
