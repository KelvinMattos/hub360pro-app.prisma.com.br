<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-layer-group"></i> Decisão & Precificação
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Segmentação de SKU</h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Papel de precificação, ciclo de vida, saúde de estoque e posição competitiva — recalculado 1x/dia.
                    </p>
                </div>
                <div class="text-right text-xs text-slate-400">
                    <span v-if="last_computed_at">Última atualização: {{ formatDateTime(last_computed_at) }}</span>
                    <span v-else title="dados insuficientes">Ainda não processado</span>
                </div>
            </div>

            <!-- Estado vazio -->
            <div v-if="!has_data" class="bg-white border border-slate-200 rounded-2xl p-16 text-center shadow-sm">
                <i class="fa-solid fa-layer-group text-4xl text-slate-300 mb-4"></i>
                <h3 class="text-lg font-bold text-slate-700">Nenhuma classificação disponível ainda</h3>
                <p class="mt-2 text-sm text-slate-500 max-w-md mx-auto" title="dados insuficientes">
                    A segmentação roda automaticamente 1x/dia (job <code class="font-mono text-xs">sku:classify-strategy</code>).
                    Assim que a primeira execução acontecer, os SKUs aparecem aqui classificados.
                </p>
            </div>

            <template v-else>
                <!-- Resumo por eixo -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <SummaryCard title="Papel de precificação" :items="pricingRoleItems"
                        :active-value="filters.pricing_role" @select="v => applyFilter({ pricing_role: v })" />
                    <SummaryCard title="Ciclo de vida" :items="lifecycleItems"
                        :active-value="filters.lifecycle_stage" @select="v => applyFilter({ lifecycle_stage: v })" />
                    <SummaryCard title="Posição competitiva" :items="competitiveItems"
                        :active-value="filters.competitive_position" @select="v => applyFilter({ competitive_position: v })" />
                </div>

                <!-- Busca -->
                <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm mb-5 flex items-center gap-2">
                    <div class="relative flex-1 max-w-sm">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input v-model="search" @keyup.enter="applyFilter({ search })" type="text" placeholder="Buscar produto ou SKU…"
                            class="text-sm border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 w-full outline-none focus:border-blue-400">
                    </div>
                    <button v-if="hasActiveFilters" @click="clearFilters" class="text-xs font-bold text-slate-400 hover:text-slate-700">
                        Limpar filtros
                    </button>
                </div>

                <!-- Tabela -->
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/50">
                                    <th class="py-3 px-4 font-bold">Produto</th>
                                    <th class="py-3 px-4 font-bold">Papel</th>
                                    <th class="py-3 px-4 font-bold text-right">Margem</th>
                                    <th class="py-3 px-4 font-bold text-right">Vol. 30d</th>
                                    <th class="py-3 px-4 font-bold">Ciclo de vida</th>
                                    <th class="py-3 px-4 font-bold text-right">Saúde estoque</th>
                                    <th class="py-3 px-4 font-bold">Competitividade</th>
                                    <th class="py-3 px-4 font-bold text-right">Gap mercado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.product_id" class="border-b border-slate-50 hover:bg-slate-50/60">
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ r.title }}</div>
                                        <div class="text-xs text-slate-400 font-mono">{{ r.sku || '—' }}<span v-if="r.brand"> · {{ r.brand }}</span></div>
                                    </td>
                                    <td class="py-3 px-4"><span :class="['badge', pricingRoleBadge(r.pricing_role)]">{{ pricingRoleLabel(r.pricing_role) }}</span></td>
                                    <td class="py-3 px-4 text-right font-mono" :class="r.margin_pct == null ? 'text-slate-300' : r.margin_pct < 0 ? 'text-red-600' : 'text-slate-700'">
                                        {{ r.margin_pct == null ? '—' : r.margin_pct.toFixed(1) + '%' }}
                                    </td>
                                    <td class="py-3 px-4 text-right font-mono text-slate-700">{{ r.volume_30d }}</td>
                                    <td class="py-3 px-4"><span :class="['badge', lifecycleBadge(r.lifecycle_stage)]">{{ lifecycleLabel(r.lifecycle_stage) }}</span></td>
                                    <td class="py-3 px-4 text-right">
                                        <span v-if="r.stock_health_index == null" class="text-slate-300" title="dados insuficientes">—</span>
                                        <span v-else :class="['font-mono font-bold', healthColor(r.stock_health_index)]">{{ r.stock_health_index }}</span>
                                    </td>
                                    <td class="py-3 px-4"><span :class="['badge', competitiveBadge(r.competitive_position)]">{{ competitiveLabel(r.competitive_position) }}</span></td>
                                    <td class="py-3 px-4 text-right font-mono" :class="gapClass(r.market_gap_pct)">
                                        {{ r.market_gap_pct == null ? '—' : (r.market_gap_pct > 0 ? '+' : '') + r.market_gap_pct.toFixed(1) + '%' }}
                                    </td>
                                </tr>
                                <tr v-if="!rows.length">
                                    <td colspan="8" class="py-10 text-center text-slate-400">Nenhum SKU encontrado com esses filtros.</td>
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
import SummaryCard from '@/Components/Segmentation/SummaryCard.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    page: { type: Number, default: 1 },
    per_page: { type: Number, default: 50 },
    last_page: { type: Number, default: 1 },
    summary: { type: Object, default: () => ({ pricing_role: {}, lifecycle_stage: {}, competitive_position: {} }) },
    last_computed_at: { type: String, default: null },
    filters: { type: Object, default: () => ({}) },
    has_data: { type: Boolean, default: false },
});

const search = ref(props.filters.search || '');

const hasActiveFilters = computed(() => {
    return !!(props.filters.pricing_role || props.filters.lifecycle_stage || props.filters.competitive_position || props.filters.search);
});

const PRICING_ROLE_LABELS = { estrela: 'Estrela', alavanca: 'Alavanca', nicho: 'Nicho', reavaliar: 'Reavaliar', sem_dado: 'Sem dado' };
const PRICING_ROLE_BADGES = { estrela: 'b-emerald', alavanca: 'b-blue', nicho: 'b-indigo', reavaliar: 'b-red', sem_dado: 'b-slate' };
const LIFECYCLE_LABELS = { novo: 'Novo', crescimento: 'Crescimento', estavel: 'Estável', declinio: 'Declínio', sem_giro: 'Sem giro' };
const LIFECYCLE_BADGES = { novo: 'b-blue', crescimento: 'b-emerald', estavel: 'b-slate', declinio: 'b-red', sem_giro: 'b-amber' };
const COMPETITIVE_LABELS = { vendendo: 'Vendendo', perdendo: 'Perdendo', alerta: 'Alerta', desconhecido: 'Desconhecido' };
const COMPETITIVE_BADGES = { vendendo: 'b-emerald', perdendo: 'b-red', alerta: 'b-amber', desconhecido: 'b-slate' };

function pricingRoleLabel(v) { return PRICING_ROLE_LABELS[v] || v; }
function pricingRoleBadge(v) { return PRICING_ROLE_BADGES[v] || 'b-slate'; }
function lifecycleLabel(v) { return LIFECYCLE_LABELS[v] || v; }
function lifecycleBadge(v) { return LIFECYCLE_BADGES[v] || 'b-slate'; }
function competitiveLabel(v) { return COMPETITIVE_LABELS[v] || v; }
function competitiveBadge(v) { return COMPETITIVE_BADGES[v] || 'b-slate'; }

function summaryItems(counts, labels) {
    return Object.keys(labels).map(key => ({ key, label: labels[key], count: counts?.[key] ?? 0 }));
}
const pricingRoleItems = computed(() => summaryItems(props.summary.pricing_role, PRICING_ROLE_LABELS));
const lifecycleItems = computed(() => summaryItems(props.summary.lifecycle_stage, LIFECYCLE_LABELS));
const competitiveItems = computed(() => summaryItems(props.summary.competitive_position, COMPETITIVE_LABELS));

function applyFilter(patch) {
    const q = { ...props.filters, page: props.page, ...patch };
    if (!('page' in patch)) q.page = 1;
    Object.keys(q).forEach(k => { if (q[k] === null || q[k] === '' || q[k] === undefined) delete q[k]; });
    router.get(route('segmentation.index'), q, { preserveScroll: true, preserveState: true, replace: true });
}
function clearFilters() {
    search.value = '';
    router.get(route('segmentation.index'), {}, { preserveScroll: true });
}

function healthColor(v) {
    if (v >= 70) return 'text-emerald-600';
    if (v >= 40) return 'text-amber-600';
    return 'text-red-600';
}
function gapClass(g) {
    if (g == null) return 'text-slate-300';
    return g > 0 ? 'text-red-600' : 'text-emerald-600';
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
</style>
