<template>
    <AppLayout title="Dashboard de ADS">
        <div class="p-6 lg:p-8 max-w-[100rem] mx-auto">
            <!-- Header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-bullhorn"></i> Marketing Digital
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-chart-line text-teal-500"></i>
                        Dashboard de ADS
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Cruza o gasto importado (Google Ads / Meta Ads) com a receita atribuída via UTM na venda real —
                        ROAS e CPA calculados de ponta a ponta, não estimados.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <Link :href="route('ads.accounts.index')" class="btn-secondary">
                        <i class="fa-solid fa-sliders mr-2"></i> Contas de ADS
                    </Link>
                    <Link :href="route('ads.import.show', { type: 'google_ads' })" class="btn-secondary">
                        <i class="fa-brands fa-google mr-2"></i> Importar Google Ads
                    </Link>
                    <Link :href="route('ads.import.show', { type: 'meta_ads' })" class="btn-secondary">
                        <i class="fa-brands fa-meta mr-2"></i> Importar Meta Ads
                    </Link>
                </div>
            </div>

            <!-- Filtro de período -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm mb-6 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-400 uppercase">De</label>
                    <input type="date" v-model="filters.since" @change="reload" class="input">
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-400 uppercase">Até</label>
                    <input type="date" v-model="filters.until" @change="reload" class="input">
                </div>
            </div>

            <!-- Sem dado nenhum -->
            <div v-if="!overview.has_spend_data && !overview.has_utm_data" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-5 py-4 rounded-xl mb-6">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                Nenhum gasto de ADS nem venda com origem (UTM) importados neste período ainda.
                Importe o relatório de campanha e reimporte as Vendas do Magazord (que já capturam a origem da venda).
            </div>
            <div v-else-if="!overview.has_spend_data" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-5 py-4 rounded-xl mb-6">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                Ainda não há gasto de ADS importado neste período — o ROAS/CPA só aparece depois de importar o
                relatório de campanha do Google Ads/Meta Ads.
            </div>
            <div v-else-if="!overview.has_utm_data" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-5 py-4 rounded-xl mb-6">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                Ainda não há venda com origem (UTM) importada neste período — reimporte "Vendas" do Magazord com o
                arquivo mais recente pra capturar a origem.
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="kpi">
                    <div class="kpi-l">Gasto total</div>
                    <div class="kpi-v">{{ money(totalSpend) }}</div>
                </div>
                <div class="kpi">
                    <div class="kpi-l">Receita atribuída</div>
                    <div class="kpi-v text-emerald-600">{{ money(totalRevenue) }}</div>
                </div>
                <div class="kpi">
                    <div class="kpi-l">ROAS geral</div>
                    <div class="kpi-v">{{ totalRoas != null ? totalRoas.toFixed(2) + 'x' : '—' }}</div>
                </div>
                <div class="kpi">
                    <div class="kpi-l">CPA geral</div>
                    <div class="kpi-v">{{ totalCpa != null ? money(totalCpa) : '—' }}</div>
                </div>
            </div>

            <!-- Por plataforma -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-800">Por plataforma</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Receita atribuída via utm_source (google/adwords → Google Ads · facebook/instagram → Meta Ads).</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <th class="text-left px-4 py-2.5">Plataforma</th>
                                <th class="text-right px-3 py-2.5">Gasto</th>
                                <th class="text-right px-3 py-2.5">Impressões</th>
                                <th class="text-right px-3 py-2.5">Cliques</th>
                                <th class="text-right px-3 py-2.5">CTR</th>
                                <th class="text-right px-3 py-2.5">Pedidos</th>
                                <th class="text-right px-3 py-2.5">Receita</th>
                                <th class="text-right px-3 py-2.5">ROAS</th>
                                <th class="text-right px-3 py-2.5">CPA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in overview.by_platform" :key="p.platform" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 font-semibold text-slate-700">{{ platformLabel(p.platform) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ money(p.spend) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-400">{{ n(p.impressions) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-400">{{ n(p.clicks) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-400">{{ p.ctr != null ? p.ctr + '%' : '—' }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ n(p.orders) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-emerald-600">{{ money(p.revenue) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold">{{ p.roas != null ? p.roas.toFixed(2) + 'x' : '—' }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ p.cpa != null ? money(p.cpa) : '—' }}</td>
                            </tr>
                            <tr v-if="!overview.by_platform.length">
                                <td colspan="9" class="px-4 py-6 text-center text-slate-400">Sem dado no período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Campanhas casadas -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-800">Campanhas — gasto e venda casados</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Só entram aqui campanhas em que o nome no relatório de ADS bate exatamente com a origem (utm_campaign) gravada na venda.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <th class="text-left px-4 py-2.5">Campanha</th>
                                <th class="text-left px-3 py-2.5">Plataforma</th>
                                <th class="text-right px-3 py-2.5">Gasto</th>
                                <th class="text-right px-3 py-2.5">Pedidos</th>
                                <th class="text-right px-3 py-2.5">Receita</th>
                                <th class="text-right px-3 py-2.5">ROAS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in overview.campaigns.matched" :key="c.campaign + c.platform" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 text-slate-700">{{ c.campaign }}</td>
                                <td class="px-3 py-2.5 text-slate-400">{{ platformLabel(c.platform) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ money(c.spend) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ n(c.orders) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-emerald-600">{{ money(c.revenue) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold">{{ c.roas != null ? c.roas.toFixed(2) + 'x' : '—' }}</td>
                            </tr>
                            <tr v-if="!overview.campaigns.matched.length">
                                <td colspan="6" class="px-4 py-6 text-center text-slate-400">Nenhuma campanha casada ainda.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Não casadas -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100">
                        <h2 class="font-bold text-slate-800 text-sm">Gasto sem venda correspondente</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Nome da campanha do ADS não bateu com nenhum utm_campaign da venda.</p>
                    </div>
                    <div class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                        <div v-for="c in overview.campaigns.spend_only" :key="c.campaign + c.platform" class="px-5 py-2.5 flex justify-between text-sm">
                            <span class="text-slate-600 truncate pr-2">{{ c.campaign }}</span>
                            <span class="font-mono text-slate-500 shrink-0">{{ money(c.spend) }}</span>
                        </div>
                        <div v-if="!overview.campaigns.spend_only.length" class="px-5 py-4 text-sm text-slate-400">Nada aqui.</div>
                    </div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100">
                        <h2 class="font-bold text-slate-800 text-sm">Venda com origem sem gasto correspondente</h2>
                        <p class="text-xs text-slate-400 mt-0.5">utm_campaign da venda não bateu com nenhuma campanha do relatório de ADS importado.</p>
                    </div>
                    <div class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                        <div v-for="c in overview.campaigns.revenue_only" :key="c.campaign" class="px-5 py-2.5 flex justify-between text-sm">
                            <span class="text-slate-600 truncate pr-2">{{ c.campaign }}</span>
                            <span class="font-mono text-emerald-600 shrink-0">{{ money(c.revenue) }}</span>
                        </div>
                        <div v-if="!overview.campaigns.revenue_only.length" class="px-5 py-4 text-sm text-slate-400">Nada aqui.</div>
                    </div>
                </div>
            </div>

            <!-- Origem bruta -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mt-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-800 text-sm">Receita por origem (utm_source bruto)</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Todas as origens capturadas na venda, mesmo as que ainda não mapeiam pra Google Ads/Meta Ads (ex.: orgânico, e-mail).</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <th class="text-left px-4 py-2.5">Origem</th>
                                <th class="text-right px-3 py-2.5">Pedidos</th>
                                <th class="text-right px-3 py-2.5">Receita</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in overview.revenue_by_source" :key="s.source" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 text-slate-700">{{ s.source }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ n(s.orders) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-emerald-600">{{ money(s.revenue) }}</td>
                            </tr>
                            <tr v-if="!overview.revenue_by_source.length">
                                <td colspan="3" class="px-4 py-6 text-center text-slate-400">Sem dado no período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    since: { type: String, required: true },
    until: { type: String, required: true },
    overview: { type: Object, required: true },
    platforms: { type: Array, required: true },
});

const filters = reactive({ since: props.since, until: props.until });

function reload() {
    router.get(route('ads.dashboard'), { since: filters.since, until: filters.until }, { preserveState: true, preserveScroll: true });
}

const totalSpend = computed(() => (props.overview.by_platform || []).reduce((s, p) => s + p.spend, 0));
const totalRevenue = computed(() => (props.overview.by_platform || []).reduce((s, p) => s + p.revenue, 0));
const totalOrders = computed(() => (props.overview.by_platform || []).reduce((s, p) => s + p.orders, 0));
const totalRoas = computed(() => (totalSpend.value > 0 && totalRevenue.value > 0) ? totalRevenue.value / totalSpend.value : null);
const totalCpa = computed(() => (totalSpend.value > 0 && totalOrders.value > 0) ? totalSpend.value / totalOrders.value : null);

function platformLabel(key) {
    const found = props.platforms.find(p => p.key === key);
    return found ? found.label : key;
}

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return (v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
</script>

<style scoped>
.input { @apply border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-200; }
.btn-secondary { @apply bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold rounded-lg px-4 py-2 transition text-sm inline-flex items-center; }
.kpi { @apply bg-white border border-slate-200 rounded-2xl p-4 shadow-sm; }
.kpi-l { @apply text-xs font-bold text-slate-400 uppercase tracking-wide; }
.kpi-v { @apply text-2xl font-extrabold text-slate-900 mt-1; }
</style>
