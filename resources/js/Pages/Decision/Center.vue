<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1500px] mx-auto">
            <!-- Header -->
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-chess-king text-indigo-500"></i>
                        Centro de <span class="bg-gradient-to-r from-indigo-500 to-violet-600 bg-clip-text text-transparent">Decisão</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Precificação e capital sob controle — margem, ponto de equilíbrio e ações prioritárias sobre os {{ n(kpis.total_skus) }} produtos do seu catálogo.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-3 py-1.5">
                        <span class="text-xs text-slate-400 font-semibold uppercase tracking-wide">Canal</span>
                        <select :value="channel.key" @change="changeChannel($event.target.value)" class="text-sm font-semibold text-slate-700 bg-transparent outline-none">
                            <option v-for="c in channels" :key="c.key" :value="c.key">{{ c.label }}</option>
                        </select>
                    </div>
                    <Link :href="route('pricing.calculo-promo')" class="btn-ghost text-sm"><i class="fa-solid fa-tags mr-2"></i>Cálculo Promo</Link>
                    <Link :href="route('inventory.aging')" class="btn-ghost text-sm"><i class="fa-solid fa-hourglass-half mr-2"></i>Aging</Link>
                </div>
            </div>

            <div class="mb-6 text-xs text-slate-500 bg-indigo-50 border border-indigo-100 rounded-lg px-4 py-2 inline-block">
                <i class="fa-solid fa-circle-info mr-1 text-indigo-400"></i>
                Análise no canal <b class="text-indigo-700">{{ channel.label }}</b> — comissão {{ channel.comissao }}%, encargos totais {{ channel.encargos_pct }}%. Troque o canal para ver margem e alertas específicos.
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
                <div class="kpi"><div class="kpi-l">Capital imobilizado</div><div class="kpi-v">{{ money(kpis.capital_imobilizado) }}</div><div class="kpi-s">custo × estoque</div></div>
                <div class="kpi"><div class="kpi-l">Faturamento potencial</div><div class="kpi-v">{{ money(kpis.faturamento_potencial) }}</div><div class="kpi-s">preço × estoque</div></div>
                <div class="kpi"><div class="kpi-l">Lucro potencial</div><div class="kpi-v" :class="kpis.lucro_potencial >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ money(kpis.lucro_potencial) }}</div><div class="kpi-s">margem × estoque</div></div>
                <div class="kpi"><div class="kpi-l">Margem média</div><div class="kpi-v" :class="kpis.margem_media_pct >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ kpis.margem_media_pct }}%</div><div class="kpi-s">ponderada por receita</div></div>
                <div class="kpi kpi-warn"><div class="kpi-l">Parado há +1 ano</div><div class="kpi-v text-amber-600">{{ money(kpis.capital_parado_antigo) }}</div><div class="kpi-s">capital envelhecido</div></div>
                <div class="kpi"><div class="kpi-l">SKUs com estoque</div><div class="kpi-v">{{ n(kpis.skus_com_estoque) }}</div><div class="kpi-s">de {{ n(kpis.total_skus) }} no total</div></div>
            </div>

            <!-- Cards de decisão (seletores) -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <button v-for="c in cards" :key="c.key" @click="tab = c.key"
                    :class="['decision-card text-left', tab === c.key ? c.activeRing : 'ring-1 ring-slate-200 hover:ring-slate-300']">
                    <div class="flex items-center justify-between">
                        <i :class="[c.icon, c.color]" class="text-xl"></i>
                        <span class="text-2xl font-black font-mono" :class="c.color">{{ n(kpis[c.count]) }}</span>
                    </div>
                    <div class="mt-2 font-bold text-sm text-slate-800">{{ c.title }}</div>
                    <div class="text-xs text-slate-400 mt-0.5">{{ c.subtitle }}</div>
                </button>
            </div>

            <!-- Tabela da categoria selecionada -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                            <i :class="[current.icon, current.color]"></i>{{ current.title }}
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">{{ current.help }}</p>
                    </div>
                    <input v-model="search" type="text" placeholder="Buscar SKU ou produto..." class="cfg-input !w-64 font-mono">
                </div>

                <div v-if="!filteredRows.length" class="text-center text-slate-400 py-12">
                    <i class="fa-solid fa-circle-check text-3xl text-emerald-400 mb-3"></i>
                    <p>Nada aqui — nenhum produto nesta categoria. {{ tab === 'oportunidade' ? '' : 'Ótimo sinal!' }}</p>
                </div>

                <div v-else class="overflow-auto max-h-[58vh] border border-slate-200 rounded-xl">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-slate-50 z-10">
                            <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                                <th class="th-l">SKU</th><th class="th-l">Produto</th><th class="th-l">Marca</th>
                                <th class="th-r">Estoque</th><th class="th-r">Custo</th><th class="th-r">Preço</th>
                                <th v-if="tab === 'promo_perigosa'" class="th-r">Promo</th>
                                <th v-if="hasBreakEven" class="th-r">Equilíbrio</th>
                                <th class="th-r">Margem</th>
                                <th v-if="tab !== 'dados_faltando'" class="th-r">Idade</th>
                                <th class="th-r">{{ tab === 'liquidar' ? 'Capital parado' : tab === 'dados_faltando' ? 'Capital' : 'Impacto' }}</th>
                                <th v-if="tab === 'dados_faltando'" class="th-l">Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in filteredRows" :key="r.sku" class="border-t border-slate-100 hover:bg-slate-50/60">
                                <td class="td-l">{{ r.sku }}</td>
                                <td class="td-l max-w-[260px] truncate" :title="r.title">{{ r.title }}</td>
                                <td class="td-l">{{ r.brand || '—' }}</td>
                                <td class="td-r">{{ n(r.stock) }}</td>
                                <td class="td-r">{{ money(r.cost) }}</td>
                                <td class="td-r">{{ money(r.price) }}</td>
                                <td v-if="tab === 'promo_perigosa'" class="td-r text-orange-600 font-semibold">{{ money(r.promo) }}</td>
                                <td v-if="hasBreakEven" class="td-r">{{ money(r.break_even) }}</td>
                                <td class="td-r font-semibold" :class="marginClass(r.margin_pct)">{{ r.margin_pct == null ? '—' : r.margin_pct + '%' }}</td>
                                <td v-if="tab !== 'dados_faltando'" class="td-r">{{ r.age_bucket }}</td>
                                <td class="td-r font-bold" :class="current.color">{{ money(r.impacto ?? r.capital) }}</td>
                                <td v-if="tab === 'dados_faltando'" class="td-l text-slate-500">{{ r.motivo }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="filteredRows.length >= 80" class="text-xs text-slate-400 mt-3">
                    Exibindo os {{ filteredRows.length }} itens de maior impacto. Refine pela busca para ver casos específicos.
                </p>
            </div>

            <p class="text-xs text-slate-400 mt-6">
                Canal {{ channel.label }}: encargos de {{ channel.encargos_pct }}%
                (imposto {{ globals.imposto }}% + MC {{ globals.mc }}% + comissão {{ channel.comissao }}%).
                <span v-if="!globals.has_launch"> Importe "Produtos & Datas" para habilitar a idade de estoque.</span>
                <span v-if="!globals.has_promo"> Importe "Produtos com Desconto" para avaliar promoções.</span>
            </p>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    globals: { type: Object, default: () => ({}) },
    channel: { type: Object, default: () => ({}) },
    channels: { type: Array, default: () => [] },
    kpis: { type: Object, default: () => ({}) },
    alertas: { type: Object, default: () => ({}) },
});

function changeChannel(key) {
    router.get(route('decision.index'), { channel: key }, { preserveScroll: true, preserveState: false });
}

const cards = [
    { key: 'prejuizo', title: 'Vendendo no prejuízo', subtitle: 'corrigir preço', icon: 'fa-solid fa-arrow-trend-down', color: 'text-red-600', activeRing: 'ring-2 ring-red-400', count: 'count_prejuizo', help: 'Produtos cujo preço de venda não cobre custo + encargos. Cada venda dá prejuízo — reajuste o preço ou reveja o custo.' },
    { key: 'promo_perigosa', title: 'Promoção perigosa', subtitle: 'abaixo do equilíbrio', icon: 'fa-solid fa-fire', color: 'text-orange-600', activeRing: 'ring-2 ring-orange-400', count: 'count_promo_perigosa', help: 'Preço promocional abaixo do ponto de equilíbrio. A promoção está queimando margem — reveja o teto de desconto.' },
    { key: 'liquidar', title: 'Liquidar', subtitle: 'capital parado +1 ano', icon: 'fa-solid fa-box-archive', color: 'text-amber-600', activeRing: 'ring-2 ring-amber-400', count: 'count_liquidar', help: 'Estoque com mais de 1 ano prendendo capital. Considere promoção agressiva para girar e liberar caixa.' },
    { key: 'oportunidade', title: 'Oportunidade', subtitle: 'lucrativo mas parado', icon: 'fa-solid fa-bullseye', color: 'text-emerald-600', activeRing: 'ring-2 ring-emerald-400', count: 'count_oportunidade', help: 'Margem alta (≥30%) mas parado há 6+ meses. Espaço para promover sem sacrificar rentabilidade.' },
    { key: 'dados_faltando', title: 'Dados faltando', subtitle: 'sem custo/preço', icon: 'fa-solid fa-triangle-exclamation', color: 'text-slate-500', activeRing: 'ring-2 ring-slate-400', count: 'count_dados_faltando', help: 'Sem custo ou sem preço não há como calcular margem. Importe Custos / Preços de Venda para completar.' },
];

// Abre na primeira categoria não vazia (mais urgente primeiro).
const firstNonEmpty = cards.find(c => (props.kpis[c.count] || 0) > 0)?.key || 'prejuizo';
const tab = ref(firstNonEmpty);
const search = ref('');

const current = computed(() => cards.find(c => c.key === tab.value));
const hasBreakEven = computed(() => tab.value === 'prejuizo' || tab.value === 'promo_perigosa' || tab.value === 'oportunidade');

const filteredRows = computed(() => {
    const list = props.alertas[tab.value] || [];
    const s = search.value.trim().toLowerCase();
    if (!s) return list;
    return list.filter(r => String(r.sku).toLowerCase().includes(s) || String(r.title).toLowerCase().includes(s));
});

function marginClass(v) {
    if (v == null) return 'text-slate-400';
    if (v < 0) return 'text-red-600';
    if (v < 15) return 'text-amber-600';
    return 'text-emerald-600';
}
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl px-5 py-4 shadow-sm; }
.kpi-warn { @apply border-amber-200 bg-amber-50/40; }
.kpi-l { @apply text-slate-400 text-[11px] uppercase tracking-wide; }
.kpi-v { @apply font-mono text-xl font-bold text-slate-900 mt-1; }
.kpi-s { @apply text-slate-400 text-[10px] mt-0.5; }
.decision-card { @apply bg-white rounded-2xl px-5 py-4 shadow-sm transition cursor-pointer; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 transition; }
.cfg-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-1.5 text-sm outline-none focus:border-indigo-400 transition; }
.th-l { @apply text-left font-semibold px-3 py-2 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-3 py-2 whitespace-nowrap; }
.td-l { @apply text-left px-3 py-1.5 whitespace-nowrap; }
.td-r { @apply text-right px-3 py-1.5 font-mono whitespace-nowrap; }
</style>
