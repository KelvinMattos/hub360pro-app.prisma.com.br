<template>
    <AppLayout title="Reposição Inteligente">
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-boxes-stacked"></i> Estoque
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Reposição Inteligente</h1>
                    <p class="text-slate-500 mt-2 font-medium max-w-2xl">
                        Velocidade ponderada (7/30/90d), ponto de reposição e quantidade sugerida por SKU — recalculado 1x/dia.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right text-xs text-slate-400">
                        <span v-if="last_computed_at">Última atualização: {{ formatDateTime(last_computed_at) }}</span>
                        <span v-else title="dados insuficientes">Ainda não processado</span>
                    </div>
                    <button @click="recompute" :disabled="recomputing"
                        class="text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50">
                        <i class="fa-solid fa-rotate" :class="{ 'fa-spin': recomputing }"></i> Recalcular agora
                    </button>
                    <button @click="showSettings = !showSettings"
                        class="text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">
                        <i class="fa-solid fa-sliders"></i> Parâmetros
                    </button>
                </div>
            </div>

            <!-- Estado vazio -->
            <div v-if="!has_data" class="bg-white border border-slate-200 rounded-2xl p-16 text-center shadow-sm">
                <i class="fa-solid fa-boxes-stacked text-4xl text-slate-300 mb-4"></i>
                <h3 class="text-lg font-bold text-slate-700">Nenhum plano de reposição disponível ainda</h3>
                <p class="mt-2 text-sm text-slate-500 max-w-md mx-auto">
                    O cálculo roda automaticamente 1x/dia (job <code class="font-mono text-xs">inventory:compute-replenishment</code>).
                    Clique em "Recalcular agora" para gerar o plano imediatamente.
                </p>
            </div>

            <template v-else>
                <!-- KPIs -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    <KpiCard label="Comprar agora" :value="money(stats.investment_buy_now)" tone="red" />
                    <KpiCard label="Repor em breve" :value="money(stats.investment_restock)" tone="amber" />
                    <KpiCard label="Receita em risco (30d)" :value="money(stats.revenue_at_risk_30d)" tone="slate-dark" />
                    <KpiCard label="Capital em excesso" :value="money(stats.immobilized_excess)" tone="indigo" />
                    <KpiCard label="Capital parado (morto)" :value="money(stats.immobilized_dead_stock)" tone="slate" />
                    <KpiCard label="Cobertura média" :value="stats.avg_coverage_days == null ? '—' : `${stats.avg_coverage_days} d`" tone="blue" />
                </div>

                <!-- Parâmetros configuráveis -->
                <div v-if="showSettings" class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-5">
                    <h3 class="text-sm font-bold text-slate-700 mb-4">Parâmetros do cálculo (por empresa)</h3>
                    <form @submit.prevent="saveSettings" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <Field label="Peso v7 (recente)"><input v-model.number="settingsForm.weight_v7" type="number" step="0.05" min="0" max="1" class="inp"></Field>
                        <Field label="Peso v30"><input v-model.number="settingsForm.weight_v30" type="number" step="0.05" min="0" max="1" class="inp"></Field>
                        <Field label="Peso v90"><input v-model.number="settingsForm.weight_v90" type="number" step="0.05" min="0" max="1" class="inp"></Field>
                        <Field label="Nível de serviço (Z)"><input v-model.number="settingsForm.service_level_z" type="number" step="0.05" min="0" max="4" class="inp"></Field>
                        <Field label="Cobertura alvo (dias)"><input v-model.number="settingsForm.target_coverage_days" type="number" min="1" class="inp"></Field>
                        <Field label="Estoque segurança fallback (dias)"><input v-model.number="settingsForm.safety_days" type="number" min="0" class="inp"></Field>
                        <Field label="Limiar de excesso (dias)"><input v-model.number="settingsForm.excess_threshold_days" type="number" min="1" class="inp"></Field>
                        <Field label="Estoque morto a partir de (dias)"><input v-model.number="settingsForm.dead_stock_days" type="number" min="1" class="inp"></Field>
                        <div class="col-span-full flex items-center gap-3">
                            <button type="submit" :disabled="savingSettings" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700 disabled:opacity-50">
                                Salvar e recalcular
                            </button>
                            <span class="text-xs text-slate-400">Lead time e lote mínimo são configurados por produto (cadastro do produto).</span>
                        </div>
                    </form>
                </div>

                <!-- Tabs por status -->
                <div class="flex flex-wrap gap-2 mb-5">
                    <button v-for="t in tabsList" :key="t.key" @click="applyFilter({ tab: t.key })"
                        :class="['tab-btn', filters.tab === t.key || (!filters.tab && t.key === 'acao') ? 'tab-active' : '']">
                        {{ t.label }} <span class="opacity-60">({{ n(tab_counts[t.key] ?? 0) }})</span>
                    </button>
                </div>

                <!-- Busca e filtros -->
                <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm mb-5 flex flex-wrap items-center gap-3">
                    <div class="relative flex-1 min-w-[200px] max-w-sm">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input v-model="search" @keyup.enter="applyFilter({ search })" type="text" placeholder="Buscar SKU ou produto…"
                            class="text-sm border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 w-full outline-none focus:border-blue-400">
                    </div>
                    <select v-model="brandFilter" @change="applyFilter({ brand: brandFilter })" class="inp max-w-[180px]">
                        <option value="">Todas as marcas</option>
                        <option v-for="b in brands" :key="b" :value="b">{{ b }}</option>
                    </select>
                    <select v-model="abcFilter" @change="applyFilter({ abc_class: abcFilter })" class="inp max-w-[140px]">
                        <option value="">Curva ABC (todas)</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                    </select>
                    <button v-if="hasActiveFilters" @click="clearFilters" class="text-xs font-bold text-slate-400 hover:text-slate-700">
                        Limpar filtros
                    </button>
                    <div class="flex-1"></div>
                    <span class="text-xs text-slate-400">{{ selected.size }} selecionado(s)</span>
                    <button @click="exportSelection" :disabled="exporting"
                        class="text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50">
                        <i class="fa-solid fa-file-excel"></i> Exportar (.xlsx)
                    </button>
                </div>

                <!-- Tabela -->
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/50">
                                    <th class="py-3 px-4 font-bold w-8"><input type="checkbox" :checked="allOnPageSelected" @change="toggleSelectAll"></th>
                                    <th class="py-3 px-4 font-bold">Produto</th>
                                    <th class="py-3 px-4 font-bold">Status</th>
                                    <th class="py-3 px-4 font-bold text-right cursor-pointer" @click="sortBy('stock')">Estoque</th>
                                    <th class="py-3 px-4 font-bold text-right cursor-pointer" @click="sortBy('coverage_days')">Cobertura</th>
                                    <th class="py-3 px-4 font-bold text-right cursor-pointer" @click="sortBy('suggested_qty')">Comprar</th>
                                    <th class="py-3 px-4 font-bold">ABC</th>
                                    <th class="py-3 px-4 font-bold text-right cursor-pointer" @click="sortBy('priority_score')">Prioridade</th>
                                    <th class="py-3 px-4 font-bold min-w-[260px]">Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.product_id" class="border-b border-slate-50 hover:bg-slate-50/60">
                                    <td class="py-3 px-4"><input type="checkbox" :value="r.product_id" v-model="selectedArray"></td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ r.title || '—' }}</div>
                                        <div class="text-xs text-slate-400 font-mono">{{ r.sku || '—' }}<span v-if="r.brand"> · {{ r.brand }}</span></div>
                                    </td>
                                    <td class="py-3 px-4"><span :class="['badge', statusBadge(r.status)]">{{ statusLabel(r.status) }}</span></td>
                                    <td class="py-3 px-4 text-right font-mono text-slate-700">{{ n(r.stock) }}</td>
                                    <td class="py-3 px-4 text-right font-mono" :class="coverageClass(r)">
                                        {{ r.coverage_days == null ? 'Sem giro' : `${r.coverage_days.toFixed(0)}d` }}
                                    </td>
                                    <td class="py-3 px-4 text-right font-mono font-bold" :class="r.suggested_qty > 0 ? 'text-orange-600' : 'text-slate-300'">
                                        {{ r.suggested_qty > 0 ? n(r.suggested_qty) : '—' }}
                                    </td>
                                    <td class="py-3 px-4">
                                        <span v-if="r.abc_class" :class="['badge', abcBadge(r.abc_class)]">{{ r.abc_class }}</span>
                                        <span v-else class="text-slate-300">—</span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-mono text-slate-500">{{ n(Math.round(r.priority_score)) }}</td>
                                    <td class="py-3 px-4 text-xs text-slate-500">{{ r.reason }}</td>
                                </tr>
                                <tr v-if="!rows.length">
                                    <td colspan="9" class="py-10 text-center text-slate-400">Nenhum SKU encontrado com esses filtros.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
                        <span class="text-slate-400">{{ n(total) }} SKUs · página {{ page }} de {{ last_page }}</span>
                        <div class="flex gap-1">
                            <button :disabled="page <= 1" @click="applyFilter({ page: page - 1 })" class="pg">Anterior</button>
                            <button :disabled="page >= last_page" @click="applyFilter({ page: page + 1 })" class="pg">Próxima</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    page: { type: Number, default: 1 },
    per_page: { type: Number, default: 50 },
    last_page: { type: Number, default: 1 },
    tab: { type: String, default: 'acao' },
    tab_counts: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    settings: { type: Object, default: () => ({}) },
    brands: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    last_computed_at: { type: String, default: null },
    has_data: { type: Boolean, default: false },
});

const KpiCard = {
    props: ['label', 'value', 'tone'],
    template: `<div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">{{ label }}</p>
        <h3 class="text-xl font-black tracking-tight" :class="toneClass">{{ value }}</h3>
    </div>`,
    computed: {
        toneClass() {
            return {
                red: 'text-red-600', amber: 'text-amber-600', indigo: 'text-indigo-600',
                slate: 'text-slate-500', blue: 'text-blue-600', 'slate-dark': 'text-slate-900',
            }[this.tone] || 'text-slate-900';
        }
    }
};

const Field = {
    props: ['label'],
    template: `<label class="text-xs font-bold text-slate-500 flex flex-col gap-1">{{ label }}<slot /></label>`,
};

const search = ref(props.filters.search || '');
const brandFilter = ref(props.filters.brand || '');
const abcFilter = ref(props.filters.abc_class || '');
const showSettings = ref(false);
const savingSettings = ref(false);
const recomputing = ref(false);
const exporting = ref(false);
const selected = ref(new Set());

const settingsForm = ref({ ...props.settings });

const tabsList = [
    { key: 'acao', label: 'Comprar / Repor' },
    { key: 'saudavel', label: 'Saudável' },
    { key: 'excesso', label: 'Excesso' },
    { key: 'morto', label: 'Estoque Morto' },
    { key: 'descontinuado', label: 'Descontinuado' },
    { key: 'todos', label: 'Todos' },
];

const selectedArray = computed({
    get: () => Array.from(selected.value),
    set: (arr) => { selected.value = new Set(arr); },
});

const allOnPageSelected = computed(() => props.rows.length > 0 && props.rows.every(r => selected.value.has(r.product_id)));

function toggleSelectAll() {
    if (allOnPageSelected.value) {
        props.rows.forEach(r => selected.value.delete(r.product_id));
    } else {
        props.rows.forEach(r => selected.value.add(r.product_id));
    }
    selected.value = new Set(selected.value);
}

const hasActiveFilters = computed(() => !!(props.filters.brand || props.filters.abc_class || props.filters.search));

const STATUS_LABELS = {
    ruptura: 'Ruptura', critico: 'Crítico', repor: 'Repor', saudavel: 'Saudável',
    excesso: 'Excesso', estoque_morto: 'Estoque Morto', descontinuado: 'Descontinuado',
};
const STATUS_BADGES = {
    ruptura: 'b-red', critico: 'b-red', repor: 'b-amber', saudavel: 'b-emerald',
    excesso: 'b-indigo', estoque_morto: 'b-slate', descontinuado: 'b-slate',
};
function statusLabel(v) { return STATUS_LABELS[v] || v; }
function statusBadge(v) { return STATUS_BADGES[v] || 'b-slate'; }
function abcBadge(v) { return { A: 'b-emerald', B: 'b-blue', C: 'b-slate' }[v] || 'b-slate'; }
function coverageClass(r) {
    if (r.coverage_days == null) return 'text-slate-300';
    if (r.status === 'ruptura' || r.status === 'critico') return 'text-red-600';
    if (r.status === 'repor') return 'text-amber-600';
    return 'text-slate-700';
}

function applyFilter(patch) {
    const q = { ...props.filters, tab: props.tab, page: props.page, brand: brandFilter.value, abc_class: abcFilter.value, search: search.value, ...patch };
    if (!('page' in patch)) q.page = 1;
    Object.keys(q).forEach(k => { if (q[k] === null || q[k] === '' || q[k] === undefined) delete q[k]; });
    router.get(route('inventory.planning'), q, { preserveScroll: true, preserveState: true, replace: true });
}
function clearFilters() {
    search.value = '';
    brandFilter.value = '';
    abcFilter.value = '';
    router.get(route('inventory.planning'), { tab: props.tab }, { preserveScroll: true });
}
function sortBy(field) {
    const direction = props.filters.sort === field && props.filters.direction === 'desc' ? 'asc' : 'desc';
    applyFilter({ sort: field, direction, page: props.page });
}

function saveSettings() {
    savingSettings.value = true;
    router.post(route('inventory.planning.settings'), settingsForm.value, {
        preserveScroll: true,
        onFinish: () => { savingSettings.value = false; },
    });
}

function recompute() {
    recomputing.value = true;
    router.post(route('inventory.planning.recompute'), {}, {
        preserveScroll: true,
        onFinish: () => { recomputing.value = false; },
    });
}

async function exportSelection() {
    exporting.value = true;
    try {
        const params = new URLSearchParams({ tab: props.tab || 'acao' });
        if (props.filters.brand) params.set('brand', props.filters.brand);
        if (props.filters.abc_class) params.set('abc_class', props.filters.abc_class);
        if (props.filters.search) params.set('search', props.filters.search);

        const res = await fetch(`${route('inventory.planning.export')}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        const data = await res.json();
        let rows = data.rows || [];
        if (selected.value.size > 0) {
            rows = rows.filter(r => selected.value.has(r.product_id));
        }

        const sheetRows = rows.map(r => ({
            SKU: r.sku, Produto: r.title, Marca: r.brand, Estoque: r.stock,
            'Velocidade (un/dia)': r.velocity_weighted, 'Lead time (dias)': r.lead_time_days,
            'Cobertura (dias)': r.coverage_days, 'Ponto de Reposição': r.reorder_point,
            'Qtd. Sugerida': r.suggested_qty, Status: statusLabel(r.status), ABC: r.abc_class,
            'Faturamento 30d': r.revenue_30d, 'Receita em Risco 30d': r.revenue_at_risk_30d, Motivo: r.reason,
        }));

        const sheet = window.XLSX.utils.json_to_sheet(sheetRows);
        const book = window.XLSX.utils.book_new();
        window.XLSX.utils.book_append_sheet(book, sheet, 'Reposição');
        window.XLSX.writeFile(book, `reposicao-inteligente-${new Date().toISOString().slice(0, 10)}.xlsx`);
    } finally {
        exporting.value = false;
    }
}

function money(v) {
    return 'R$ ' + (v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function formatDateTime(v) {
    if (!v) return '—';
    return new Date(v).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.pg { @apply px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed text-xs font-semibold; }
.badge { @apply text-[11px] font-bold px-2 py-0.5 rounded-full; }
.b-emerald { @apply bg-emerald-100 text-emerald-700; }
.b-red { @apply bg-red-100 text-red-700; }
.b-amber { @apply bg-amber-100 text-amber-700; }
.b-slate { @apply bg-slate-100 text-slate-500; }
.b-blue { @apply bg-blue-100 text-blue-700; }
.b-indigo { @apply bg-indigo-100 text-indigo-700; }
.inp { @apply text-sm border border-slate-200 rounded-lg px-3 py-1.5 outline-none focus:border-blue-400; }
.tab-btn { @apply text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50; }
.tab-active { @apply bg-slate-900 text-white border-slate-900; }
</style>
