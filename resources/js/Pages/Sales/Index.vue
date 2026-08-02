<template>
    <AppLayout title="Central de Vendas">
        <div class="p-6 lg:p-8 max-w-[1500px] mx-auto">
            <!-- Header -->
            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-chart-simple text-teal-500"></i>
                        Central de <span class="bg-gradient-to-r from-teal-500 to-emerald-600 bg-clip-text text-transparent">Vendas</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Faturamento por mês, canal, região e produto — tudo sobre os pedidos importados.</p>
                </div>
            </div>

            <!-- Filtro de período -->
            <div class="mb-8 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-wrap items-center gap-3">
                <div class="flex gap-1 bg-slate-50 border border-slate-200 rounded-lg p-1">
                    <button v-for="d in [7, 30, 90, 365]" :key="d" @click="setDays(d)"
                        :class="['px-3 py-1.5 rounded-md text-sm font-semibold transition', periodMode === 'days' && days === d ? 'bg-teal-500 text-white' : 'text-slate-500 hover:bg-slate-100']">
                        {{ d === 365 ? '1 ano' : d + 'd' }}
                    </button>
                </div>

                <div class="h-6 w-px bg-slate-200"></div>

                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wide">Mês</label>
                    <input type="month" v-model="monthInput" @change="setMonth"
                        :class="['text-sm border rounded-lg px-2 py-1.5 outline-none focus:border-teal-400', periodMode === 'month' ? 'border-teal-400 bg-teal-50/50' : 'border-slate-200']">
                </div>

                <div class="h-6 w-px bg-slate-200"></div>

                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wide">De</label>
                    <input type="date" v-model="fromInput" :class="['text-sm border rounded-lg px-2 py-1.5 outline-none focus:border-teal-400', periodMode === 'range' ? 'border-teal-400 bg-teal-50/50' : 'border-slate-200']">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wide">até</label>
                    <input type="date" v-model="toInput" :class="['text-sm border rounded-lg px-2 py-1.5 outline-none focus:border-teal-400', periodMode === 'range' ? 'border-teal-400 bg-teal-50/50' : 'border-slate-200']">
                    <button @click="setRange" :disabled="!fromInput || !toInput"
                        class="text-sm font-semibold px-3 py-1.5 rounded-lg bg-slate-900 text-white hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed">
                        Aplicar
                    </button>
                </div>

                <div class="flex-1"></div>
                <div v-if="periodLabel" class="text-xs font-semibold text-teal-700 bg-teal-50 border border-teal-100 rounded-lg px-3 py-1.5">
                    <i class="fa-solid fa-calendar-check mr-1"></i>{{ periodLabel }}
                </div>
            </div>

            <div v-if="!has_data" class="bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Nenhuma venda no período. Importe o modelo <b>Vendas</b> em Importações Magazord para alimentar esta análise.
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <div class="kpi">
                    <div class="kpi-l">Faturamento</div>
                    <div class="kpi-v text-emerald-600">{{ money(kpis.faturamento) }}</div>
                    <div v-if="kpis.variacao_pct !== null" class="kpi-s mt-1" :class="kpis.variacao_pct >= 0 ? 'text-emerald-600' : 'text-red-500'">
                        <i :class="kpis.variacao_pct >= 0 ? 'fa-solid fa-arrow-trend-up' : 'fa-solid fa-arrow-trend-down'" class="mr-1"></i>
                        {{ pctLabel(kpis.variacao_pct) }} vs período anterior
                    </div>
                    <div v-else class="kpi-s mt-1 text-slate-300">sem período anterior pra comparar</div>
                </div>
                <div class="kpi"><div class="kpi-l">Pedidos faturados</div><div class="kpi-v">{{ n(kpis.pedidos) }}</div></div>
                <div class="kpi"><div class="kpi-l">Ticket médio</div><div class="kpi-v">{{ money(kpis.ticket) }}</div></div>
                <div class="kpi kpi-warn">
                    <div class="kpi-l">Cancelados</div>
                    <div class="kpi-v text-red-600">{{ n(kpis.cancelados) }}</div>
                    <div class="kpi-s">{{ money(kpis.cancelado_valor) }} · {{ kpis.taxa_cancelamento_pct }}% dos pedidos</div>
                </div>
                <div class="kpi"><div class="kpi-l">Clientes no período</div><div class="kpi-v">{{ n(clientes_resumo.total_clientes) }}</div></div>
                <div class="kpi" :class="clientes_resumo.multicanal > 0 ? 'kpi-info' : ''">
                    <div class="kpi-l">Clientes multicanal</div>
                    <div class="kpi-v" :class="clientes_resumo.multicanal > 0 ? 'text-indigo-600' : ''">{{ n(clientes_resumo.multicanal) }}</div>
                    <div class="kpi-s">compraram em 2+ canais</div>
                </div>
            </div>

            <!-- Tendência mensal -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                    <i class="fa-solid fa-calendar-days mr-1.5"></i>Tendência mensal (últimos 12 meses)
                </h3>
                <MonthlyTrendChart :items="mensal" />
            </div>

            <!-- Tendência diária do período -->
            <div v-if="por_dia.length" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Faturamento por dia (no período selecionado)</h3>
                <div class="flex items-end gap-1 h-32">
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

            <!-- Canal e Região -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-store mr-1.5"></i>Por canal
                    </h3>
                    <BarList :items="por_canal" label-key="canal" value-key="total" count-key="pedidos" />
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-location-dot mr-1.5"></i>Melhores estados
                    </h3>
                    <BarList :items="por_regiao_estado" label-key="estado" value-key="total" count-key="pedidos"
                        bar-color="bg-indigo-400" empty-text="Sem dado de estado do cliente no período (depende do canal de origem)." />
                </div>
            </div>

            <!-- Macrorregião e Marca -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-map mr-1.5"></i>Por macrorregião
                    </h3>
                    <BarList :items="por_regiao_macro" label-key="regiao" value-key="total" count-key="pedidos"
                        bar-color="bg-indigo-400" empty-text="Sem dado de região no período." />
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-tags mr-1.5"></i>Por marca
                    </h3>
                    <BarList :items="por_marca" label-key="marca" value-key="total" count-key="unidades"
                        bar-color="bg-amber-400" empty-text="Sem dado de marca vinculado aos itens vendidos." />
                </div>
            </div>

            <!-- Top produtos e Status -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-ranking-star mr-1.5"></i>Produtos mais vendidos
                    </h3>
                    <div v-if="!top_produtos.length" class="text-slate-400 text-sm py-4 text-center">Sem itens de pedido no período.</div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wide text-slate-400">
                                <th class="text-left pb-2 font-bold">Produto</th>
                                <th class="text-right pb-2 font-bold">Pedidos</th>
                                <th class="text-right pb-2 font-bold">Unid.</th>
                                <th class="text-right pb-2 font-bold">Preço médio</th>
                                <th class="text-right pb-2 font-bold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in top_produtos" :key="p.sku" class="border-b border-slate-100">
                                <td class="py-2 pr-2 max-w-[200px]">
                                    <p class="font-semibold text-slate-700 truncate" :title="p.titulo">{{ p.titulo }}</p>
                                    <p class="text-[10px] text-slate-400 font-mono">{{ p.sku || '—' }}</p>
                                </td>
                                <td class="py-2 text-right font-mono text-slate-400 whitespace-nowrap">{{ n(p.pedidos) }}</td>
                                <td class="py-2 text-right font-mono text-slate-500 whitespace-nowrap">{{ n(p.unidades) }} un.</td>
                                <td class="py-2 text-right font-mono text-slate-500 whitespace-nowrap">{{ money(p.preco_medio) }}</td>
                                <td class="py-2 pl-3 text-right font-mono font-semibold text-slate-700 whitespace-nowrap">{{ money(p.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

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

            <!-- Top clientes -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                    <i class="fa-solid fa-users mr-1.5"></i>Top 10 clientes
                    <span class="normal-case font-medium text-slate-400 tracking-normal">— agrupado por CPF/CNPJ, independente do canal</span>
                </h3>
                <div v-if="!top_clientes.length" class="text-slate-400 text-sm py-4 text-center">Sem dado de CPF/CNPJ vinculado aos pedidos no período.</div>
                <div v-else class="overflow-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                <th class="text-left py-2 font-bold">Cliente</th>
                                <th class="text-left py-2 font-bold">Canais</th>
                                <th class="text-right py-2 font-bold">Pedidos</th>
                                <th class="text-right py-2 font-bold">Ticket médio</th>
                                <th class="text-right py-2 font-bold">Total gasto</th>
                                <th class="text-right py-2 font-bold">Última compra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in top_clientes" :key="c.doc" class="border-b border-slate-100" :class="c.multicanal ? 'bg-indigo-50/40' : ''">
                                <td class="py-2 pr-2 max-w-[200px]">
                                    <Link :href="route('customers.show', c.doc)" class="font-semibold text-blue-600 hover:underline truncate block" :title="c.nome">{{ c.nome }}</Link>
                                    <p class="text-[10px] text-slate-400 font-mono">{{ c.doc }}</p>
                                </td>
                                <td class="py-2 pr-2">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="canal in c.canais" :key="canal" class="pill pill-idle">{{ canal }}</span>
                                        <span v-if="c.multicanal" class="pill pill-indigo" title="Comprou em mais de um canal">
                                            <i class="fa-solid fa-shuffle mr-1"></i>Multicanal
                                        </span>
                                    </div>
                                </td>
                                <td class="py-2 text-right font-mono text-slate-500 whitespace-nowrap">{{ n(c.pedidos) }}</td>
                                <td class="py-2 text-right font-mono text-slate-500 whitespace-nowrap">{{ money(c.ticket_medio) }}</td>
                                <td class="py-2 text-right font-mono font-semibold text-slate-700 whitespace-nowrap">{{ money(c.total) }}</td>
                                <td class="py-2 text-right text-slate-400 whitespace-nowrap">{{ fmtDateTime(c.ultima_compra) }}</td>
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
                                <td class="td-l font-mono">
                                    <Link v-if="$page.props.features?.orders" :href="route('orders.show', r.id)" class="text-blue-600 hover:underline">{{ r.pedido }}</Link>
                                    <span v-else>{{ r.pedido }}</span>
                                </td>
                                <td class="td-l max-w-[220px] truncate" :title="r.cliente">
                                    <Link v-if="r.doc" :href="route('customers.show', r.doc)" class="text-blue-600 hover:underline font-semibold">{{ r.cliente }}</Link>
                                    <span v-else>{{ r.cliente }}</span>
                                </td>
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
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarList from '@/Components/Sales/BarList.vue';
import MonthlyTrendChart from '@/Components/Sales/MonthlyTrendChart.vue';

const props = defineProps({
    days: { type: Number, default: 30 },
    filters: { type: Object, default: () => ({ mode: 'days' }) },
    kpis: { type: Object, default: () => ({}) },
    por_canal: { type: Array, default: () => [] },
    por_status: { type: Array, default: () => [] },
    por_dia: { type: Array, default: () => [] },
    mensal: { type: Array, default: () => [] },
    por_regiao_estado: { type: Array, default: () => [] },
    por_regiao_macro: { type: Array, default: () => [] },
    por_marca: { type: Array, default: () => [] },
    top_produtos: { type: Array, default: () => [] },
    top_clientes: { type: Array, default: () => [] },
    clientes_resumo: { type: Object, default: () => ({ total_clientes: 0, multicanal: 0 }) },
    recentes: { type: Array, default: () => [] },
    has_data: { type: Boolean, default: false },
});

const maxDia = computed(() => Math.max(1, ...props.por_dia.map(d => d.total || 0)));

const periodMode = computed(() => props.filters?.mode || 'days');
const periodLabel = computed(() => {
    if (periodMode.value === 'month') return `Mês: ${monthLabel(props.filters.month)}`;
    if (periodMode.value === 'range') return `${fmtDate(props.filters.from)} até ${fmtDate(props.filters.to)}`;
    return '';
});
function monthLabel(ym) {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    const nomes = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return `${nomes[parseInt(m, 10) - 1]}/${y}`;
}

const monthInput = ref(periodMode.value === 'month' ? props.filters.month : '');
const fromInput = ref(periodMode.value === 'range' ? props.filters.from : '');
const toInput = ref(periodMode.value === 'range' ? props.filters.to : '');

function setDays(d) {
    monthInput.value = ''; fromInput.value = ''; toInput.value = '';
    router.get(route('sales.index'), { days: d }, { preserveScroll: true, preserveState: false });
}
function setMonth() {
    if (!monthInput.value) return;
    fromInput.value = ''; toInput.value = '';
    router.get(route('sales.index'), { month: monthInput.value }, { preserveScroll: true, preserveState: false });
}
function setRange() {
    if (!fromInput.value || !toInput.value) return;
    monthInput.value = '';
    router.get(route('sales.index'), { from: fromInput.value, to: toInput.value }, { preserveScroll: true, preserveState: false });
}
function barH(v) { return Math.max(2, Math.round((v / maxDia.value) * 100)); }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? 'R$ 0,00' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function pctLabel(v) { return (v >= 0 ? '+' : '') + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }
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
.kpi-info { @apply border-indigo-200 bg-indigo-50/40; }
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
.pill-indigo { @apply bg-indigo-100 text-indigo-700; }
</style>
