<template>
    <AppLayout title="Desempenho por Canal">
        <div class="p-6 lg:p-8 max-w-[110rem] mx-auto">
            <!-- Header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-chart-simple"></i> Vendas
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-calendar-days text-teal-500"></i>
                        Desempenho por Canal
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Mensal, semanal, diário e conta Mercado Livre — calculados a partir do Diário de Vendas importado.
                    </p>
                </div>
                <Link :href="route('sales.channel-import.show')" class="btn-secondary shrink-0">
                    <i class="fa-solid fa-file-import mr-2"></i> Importar Diário de Vendas
                </Link>
            </div>

            <!-- Filtros -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm mb-6 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-400 uppercase">Ano</label>
                    <select v-model.number="filters.year" @change="reload" class="input">
                        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2" v-if="tab === 'semanal' || tab === 'diario'">
                    <label class="text-xs font-bold text-slate-400 uppercase">Mês</label>
                    <select v-model.number="filters.month" @change="reload" class="input">
                        <option v-for="(name, i) in monthNames" :key="i" :value="i + 1">{{ name }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2" v-if="tab === 'diario'">
                    <label class="text-xs font-bold text-slate-400 uppercase">Canal</label>
                    <select v-model="filters.channel" @change="reload" class="input">
                        <option value="">Todos</option>
                        <option v-for="c in channels" :key="c.key" :value="c.key">{{ c.label }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2" v-if="tab === 'mensal'">
                    <label class="text-xs font-bold text-slate-400 uppercase">Métrica</label>
                    <select v-model="metric" class="input">
                        <option value="paid_value">Pedidos Pagos</option>
                        <option value="gross_value">Pedidos Efetuados</option>
                        <option value="canceled_value">Pedidos Cancelados</option>
                        <option value="net_value">Total Líquido</option>
                        <option value="orders_count">Nº de Pedidos</option>
                    </select>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <div class="relative">
                        <button @click="showExport = !showExport" class="btn-secondary">
                            <i class="fa-solid fa-file-export mr-2"></i> Exportar
                        </button>
                        <div v-if="showExport" class="absolute right-0 mt-2 bg-white border border-slate-200 rounded-xl shadow-lg py-2 w-40 z-20">
                            <a v-for="f in ['xlsx','csv','pdf']" :key="f" :href="exportUrl(f)"
                               class="block px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 uppercase font-semibold"
                               @click="showExport = false">{{ f }}</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Abas -->
            <div class="flex gap-2 mb-6 border-b border-slate-200">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                    :class="['px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition',
                        tab === t.key ? 'border-teal-500 text-teal-600' : 'border-transparent text-slate-400 hover:text-slate-600']">
                    <i :class="t.icon" class="mr-1.5"></i>{{ t.label }}
                </button>
            </div>

            <!-- ===== MENSAL ===== -->
            <div v-if="tab === 'mensal'" class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="font-bold text-slate-800">Comparativo Mensal — {{ monthly.year }} x {{ monthly.prev_year }}</h2>
                    <p class="text-xs text-slate-400">Variação % calculada só quando há dado do ano anterior importado.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <th class="text-left px-4 py-2.5 sticky left-0 bg-slate-50">Canal</th>
                                <th v-for="(name, i) in monthNamesShort" :key="i" class="text-right px-3 py-2.5 whitespace-nowrap">{{ name }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(label, key) in monthly.channels" :key="key"
                                :class="['border-t border-slate-100', key === 'total' ? 'bg-slate-50 font-bold' : (key === 'mercado_livre_total' ? 'bg-teal-50/40 font-semibold' : '')]">
                                <td class="px-4 py-2.5 sticky left-0 bg-inherit whitespace-nowrap">{{ label }}</td>
                                <td v-for="m in 12" :key="m" class="text-right px-3 py-2.5 whitespace-nowrap">
                                    <template v-if="cell(key, m)">
                                        <div>{{ formatMetric(cell(key, m).current) }}</div>
                                        <div v-if="cell(key, m).diff_pct !== null" :class="diffClass(cell(key, m).diff_pct)" class="text-[10px] font-bold">
                                            {{ formatPct(cell(key, m).diff_pct) }}
                                        </div>
                                    </template>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== SEMANAL ===== -->
            <div v-else-if="tab === 'semanal'" class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-800">Vendas Semanais — {{ monthNames[filters.month - 1] }}/{{ weekly.year }}</h2>
                    <p class="text-xs text-slate-400">Semanas de sábado a sexta, recortadas nos limites do mês.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <th class="text-left px-4 py-2.5">Canal</th>
                                <th v-for="w in weekly.weeks" :key="w.label" class="text-right px-3 py-2.5 whitespace-nowrap">{{ w.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(label, key) in weekly.channels" :key="key" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 whitespace-nowrap">{{ label }}</td>
                                <td v-for="w in weekly.weeks" :key="w.label" class="text-right px-3 py-2.5">
                                    {{ formatCurrency(w.channels[key]?.value ?? 0) }}
                                </td>
                            </tr>
                            <tr class="border-t border-slate-200 bg-slate-50 font-bold">
                                <td class="px-4 py-2.5">Total Semanal</td>
                                <td v-for="w in weekly.weeks" :key="w.label" class="text-right px-3 py-2.5">{{ formatCurrency(w.total.value) }}</td>
                            </tr>
                            <tr class="bg-teal-50/40 font-semibold">
                                <td class="px-4 py-2.5">Total Acumulado</td>
                                <td v-for="w in weekly.weeks" :key="w.label" class="text-right px-3 py-2.5">{{ formatCurrency(w.accumulated.value) }}</td>
                            </tr>
                            <tr class="border-t border-slate-100 text-slate-400">
                                <td class="px-4 py-2.5">Nº Pedidos</td>
                                <td v-for="w in weekly.weeks" :key="w.label" class="text-right px-3 py-2.5">{{ n(w.total.orders) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== DIÁRIO ===== -->
            <div v-else-if="tab === 'diario'" class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-800">Diário de Vendas — {{ monthNames[filters.month - 1] }}/{{ filters.year }}</h2>
                </div>
                <div class="overflow-x-auto max-h-[32rem] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 z-10">
                            <tr class="text-slate-500 text-xs uppercase">
                                <th class="text-left px-4 py-2.5">Data</th>
                                <th class="text-left px-3 py-2.5">Canal</th>
                                <th class="text-right px-3 py-2.5">Efetuados</th>
                                <th class="text-right px-3 py-2.5">Pagos</th>
                                <th class="text-right px-3 py-2.5">Cancelados</th>
                                <th class="text-right px-3 py-2.5">Taxa</th>
                                <th class="text-right px-3 py-2.5">Tarifas</th>
                                <th class="text-right px-3 py-2.5">Frete</th>
                                <th class="text-right px-3 py-2.5">Líquido</th>
                                <th class="text-right px-3 py-2.5">Pedidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in daily" :key="i" class="border-t border-slate-100">
                                <td class="px-4 py-2 whitespace-nowrap">{{ formatDate(r.sale_date) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ r.channel_label }}</td>
                                <td class="text-right px-3 py-2">{{ formatCurrency(r.gross_value) }}</td>
                                <td class="text-right px-3 py-2">{{ formatCurrency(r.paid_value) }}</td>
                                <td class="text-right px-3 py-2">{{ formatCurrency(r.canceled_value) }}</td>
                                <td class="text-right px-3 py-2">{{ r.effectiveness_rate !== null ? formatPct(r.effectiveness_rate) : '—' }}</td>
                                <td class="text-right px-3 py-2">{{ formatCurrency(r.fees) }}</td>
                                <td class="text-right px-3 py-2">{{ formatCurrency(r.shipping_cost) }}</td>
                                <td class="text-right px-3 py-2 font-semibold">{{ formatCurrency(r.net_value) }}</td>
                                <td class="text-right px-3 py-2">{{ n(r.orders_count) }}</td>
                            </tr>
                            <tr v-if="!daily.length">
                                <td colspan="10" class="text-center px-4 py-8 text-slate-400">Sem dados importados para este período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== CONTA MERCADO LIVRE ===== -->
            <div v-else class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-800">Mercado Livre — Desempenho por Conta ({{ mlAccounts.year }})</h2>
                    <p class="text-xs text-slate-400">Variação % mês a mês, dentro do mesmo ano.</p>
                </div>
                <div v-for="(account, key) in mlAccounts.accounts" :key="key" class="border-t border-slate-100 first:border-t-0">
                    <div class="px-5 py-3 bg-slate-50 font-semibold text-slate-700">{{ account.label }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-slate-500 text-xs uppercase">
                                    <th class="text-left px-4 py-2">Métrica</th>
                                    <th v-for="(name, i) in monthNamesShort" :key="i" class="text-right px-3 py-2 whitespace-nowrap">{{ name }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-2 text-slate-500">Pedidos Pagos</td>
                                    <td v-for="m in 12" :key="m" class="text-right px-3 py-2">
                                        <template v-if="account.months[m].current">
                                            <div>{{ formatCurrency(account.months[m].current.paid_value) }}</div>
                                            <div v-if="account.months[m].diff_pct !== null" :class="diffClass(account.months[m].diff_pct)" class="text-[10px] font-bold">
                                                {{ formatPct(account.months[m].diff_pct) }}
                                            </div>
                                        </template>
                                        <span v-else class="text-slate-300">—</span>
                                    </td>
                                </tr>
                                <tr class="border-t border-slate-100 text-slate-400">
                                    <td class="px-4 py-2">Nº Pedidos</td>
                                    <td v-for="m in 12" :key="m" class="text-right px-3 py-2">
                                        {{ account.months[m].current ? n(account.months[m].current.orders_count) : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== METAS ===== -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm mt-6 p-5" v-if="tab === 'mensal'">
                <h2 class="font-bold text-slate-800 mb-1">Metas de Faturamento (Pedidos Pagos) — {{ monthNames[filters.month - 1] }}/{{ filters.year }}</h2>
                <p class="text-xs text-slate-400 mb-4">Meta é um valor que você define — usada só pra calcular o % realizado, nunca uma projeção automática.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div v-for="c in channels" :key="c.key" class="border border-slate-200 rounded-xl p-3">
                        <div class="text-xs font-semibold text-slate-600 mb-2">{{ c.label }}</div>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.01" min="0" v-model.number="goalInputs[c.key]" class="input flex-1" placeholder="Meta R$">
                            <button @click="saveGoal(c.key)" class="btn-primary !px-3 !py-1.5 text-xs">Salvar</button>
                        </div>
                        <div class="text-xs text-slate-400 mt-2" v-if="goalRealized(c.key) !== null">
                            Realizado: <span class="font-bold text-slate-600">{{ formatPct(goalRealized(c.key)) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    year: { type: Number, required: true },
    month: { type: Number, required: true },
    monthly: { type: Object, required: true },
    weekly: { type: Object, required: true },
    mlAccounts: { type: Object, required: true },
    goals: { type: Object, required: true },
    daily: { type: Array, required: true },
    dailyChannel: { type: String, default: null },
    channels: { type: Array, required: true },
});

const page = usePage();

const tab = ref('mensal');
const metric = ref('paid_value');
const showExport = ref(false);

const filters = reactive({
    year: props.year,
    month: props.month,
    channel: props.dailyChannel || '',
});

const tabs = [
    { key: 'mensal', label: 'Mensal', icon: 'fa-solid fa-table-cells' },
    { key: 'semanal', label: 'Semanal', icon: 'fa-solid fa-calendar-week' },
    { key: 'diario', label: 'Diário', icon: 'fa-solid fa-calendar-day' },
    { key: 'conta_ml', label: 'Conta Mercado Livre', icon: 'fa-solid fa-store' },
];

const monthNames = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const monthNamesShort = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const yearOptions = computed(() => {
    const y = new Date().getFullYear();
    return [y + 1, y, y - 1, y - 2, y - 3];
});

function reload() {
    router.get(route('sales.channel-performance.index'), {
        year: filters.year, month: filters.month, channel: filters.channel || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function cell(channelKey, month) {
    return props.monthly.months[month]?.[channelKey] ?? null;
}
function formatMetric(entry) {
    if (!entry) return '—';
    return metric.value === 'orders_count' ? n(entry[metric.value]) : formatCurrency(entry[metric.value]);
}

function diffClass(pct) {
    if (pct === null) return 'text-slate-300';
    return pct >= 0 ? 'text-emerald-600' : 'text-red-500';
}
function formatPct(pct) {
    if (pct === null || pct === undefined) return '—';
    const v = (pct * 100).toFixed(1);
    return (pct > 0 ? '+' : '') + v + '%';
}
function formatCurrency(v) {
    return (v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}
function formatDate(d) {
    const [y, m, dd] = d.split('-');
    return `${dd}/${m}/${y}`;
}
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }

function exportUrl(format) {
    const view = tab.value === 'conta_ml' ? 'ml_accounts' : tab.value === 'diario' ? 'daily' : tab.value === 'semanal' ? 'weekly' : 'monthly';
    const params = new URLSearchParams({
        format, view, year: filters.year,
        ...(view === 'weekly' || view === 'daily' ? { month: filters.month } : {}),
        ...(view === 'daily' && filters.channel ? { channel: filters.channel } : {}),
    });
    return route('sales.channel-performance.export') + '?' + params.toString();
}

/* ---------------- Metas ---------------- */
const goalInputs = reactive({});
for (const c of props.channels) {
    const key = `${c.key}:${filters.month}`;
    goalInputs[c.key] = props.goals[key] ?? null;
}

function goalRealized(channelKey) {
    const goal = goalInputs[channelKey];
    if (!goal || goal <= 0) return null;
    const entry = cell(channelKey, filters.month);
    if (!entry || !entry.current) return null;
    return entry.current.paid_value / goal;
}

function saveGoal(channelKey) {
    const amount = goalInputs[channelKey];
    if (amount === null || amount === undefined || amount === '') return;
    router.post(route('sales.channel-performance.goals.save'), {
        channel: channelKey, year: filters.year, month: filters.month, goal_amount: amount,
    }, { preserveScroll: true, preserveState: true });
}
</script>

<style scoped>
.input { @apply border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-200; }
.btn-primary { @apply bg-teal-500 hover:bg-teal-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.btn-secondary { @apply bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold rounded-lg px-5 py-2.5 transition inline-flex items-center; }
</style>
