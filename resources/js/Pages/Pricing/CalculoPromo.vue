<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1500px] mx-auto">
            <!-- Header -->
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-tags text-emerald-500"></i>
                        Cálculo <span class="bg-gradient-to-r from-emerald-500 to-teal-600 bg-clip-text text-transparent">Promo</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Direto do banco — sem importar planilhas. Margens, ponto de equilíbrio e PV promo por canal, sobre os {{ n(stats.total) }} produtos filtrados.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-3 py-1.5">
                        <span class="text-xs text-slate-400 font-semibold uppercase tracking-wide">Canal</span>
                        <select :value="channel.key" @change="changeChannel($event.target.value)" class="text-sm font-semibold text-slate-700 bg-transparent outline-none">
                            <option v-for="c in channels" :key="c.key" :value="c.key">{{ c.label }}</option>
                        </select>
                    </div>
                    <a :href="exportUrl" class="btn-primary text-sm"><i class="fa-solid fa-file-export mr-2"></i>Exportar CSV</a>
                    <Link :href="route('pricing.channels')" class="btn-ghost text-sm"><i class="fa-solid fa-sliders mr-2"></i>Config.</Link>
                </div>
            </div>

            <!-- Aviso de dados -->
            <div v-if="!globals.has_channel_prices" class="bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Ainda não há preços por canal no banco — usando o preço de venda base para todos. Importe o modelo
                <b>Preços de Venda (Consulta Dinâmica)</b> para preços específicos por canal.
            </div>

            <!-- Stats -->
            <div class="flex flex-wrap gap-4 mb-5">
                <div class="stat"><div class="stat-v">{{ n(stats.total) }}</div><div class="stat-l">produtos ({{ channel.label }})</div></div>
                <div class="stat"><div class="stat-v text-emerald-600">{{ n(stats.promos_abaixo) }}</div><div class="stat-l">com promo abaixo do PV atual</div></div>
                <div class="stat"><div class="stat-v text-amber-600">{{ n(stats.sem_custo) }}</div><div class="stat-l">sem custo cadastrado</div></div>
                <div class="flex-1"></div>
                <div class="text-xs text-slate-400 self-end">Encargos no canal: imposto {{ globals.imposto }}% + MC {{ globals.mc }}% + comissão {{ channel.comissao }}% = <b>{{ channel.encargos_pct }}%</b></div>
            </div>

            <!-- Busca -->
            <div class="flex items-center gap-3 mb-4">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input v-model="q" @keyup.enter="search" type="text" placeholder="Buscar SKU ou produto..." class="cfg-input !w-72 !pl-8 font-mono">
                </div>
                <button @click="search" class="btn-ghost text-sm">Buscar</button>
                <span v-if="filters.q" class="text-xs text-slate-400">filtrando por “{{ filters.q }}”</span>
            </div>

            <!-- Tabela -->
            <div class="overflow-auto max-h-[62vh] border border-slate-200 rounded-2xl bg-white">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-50 z-10">
                        <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                            <th class="th-l">SKU</th><th class="th-l">Produto</th><th class="th-r">Estoque</th>
                            <th class="th-r">Tempo Est.</th><th class="th-r">Custo</th><th class="th-r">PV Atual</th>
                            <th class="th-r">Ponto Eq.</th><th class="th-r">Meta Lucro</th><th class="th-r">PV Promo</th>
                            <th class="th-r">Result. Promo</th><th class="th-r">% Desc.</th><th class="th-r">Promo&lt;PV?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.sku" class="border-t border-slate-100 hover:bg-slate-50/60">
                            <td class="td-l">{{ r.sku }}</td>
                            <td class="td-l max-w-[240px] truncate" :title="r.produto">{{ r.produto }}</td>
                            <td class="td-r">{{ n(r.estoque) }}</td>
                            <td class="td-r text-slate-500">{{ r.tempo_estoque || '—' }}</td>
                            <td class="td-r">{{ money(r.custo) }}</td>
                            <td class="td-r">{{ money(r.pv_atual) }}</td>
                            <td class="td-r">{{ money(r.ponto_equilibrio) }}</td>
                            <td class="td-r text-slate-500">{{ money(r.meta_lucro) }}</td>
                            <td class="td-r font-semibold">{{ money(r.promo_sugerido) }}</td>
                            <td class="td-r font-mono" :class="(r.resultado_promo ?? 0) < 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(r.resultado_promo) }}</td>
                            <td class="td-r">{{ pct(r.perc_desc) }}</td>
                            <td class="td-r font-bold" :class="r.promo_menor === 'SIM' ? 'text-red-500' : 'text-emerald-600'">{{ r.promo_menor || '—' }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="12" class="text-center text-slate-400 py-10">Nenhum produto. Importe Custos/Preços em Importações Magazord.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginação -->
            <div v-if="pagination.lastPage > 1" class="flex items-center justify-between mt-4">
                <div class="text-xs text-slate-400">Página {{ pagination.page }} de {{ pagination.lastPage }} · {{ n(pagination.total) }} produtos</div>
                <div class="flex gap-2">
                    <button @click="goPage(pagination.page - 1)" :disabled="pagination.page <= 1" class="btn-ghost text-sm disabled:opacity-40">Anterior</button>
                    <button @click="goPage(pagination.page + 1)" :disabled="pagination.page >= pagination.lastPage" class="btn-ghost text-sm disabled:opacity-40">Próxima</button>
                </div>
            </div>

            <p class="text-xs text-slate-400 mt-6">
                Motor idêntico ao validado na planilha (equilíbrio, meta e PV promo por canal), agora 100% no banco.
                Ajuste comissões e descontos em <Link :href="route('pricing.channels')" class="text-emerald-600 hover:underline">Config. de Canais</Link>.
            </p>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    channel: { type: Object, default: () => ({}) },
    channels: { type: Array, default: () => [] },
    globals: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    pagination: { type: Object, default: () => ({}) },
});

const q = ref(props.filters.q || '');

const exportUrl = computed(() => route('pricing.calculo-promo.export', { channel: props.channel.key, q: props.filters.q || undefined }));

function visit(params) {
    router.get(route('pricing.calculo-promo'), params, { preserveScroll: true, preserveState: false });
}
function changeChannel(key) { visit({ channel: key, q: props.filters.q || undefined }); }
function search() { visit({ channel: props.channel.key, q: q.value || undefined }); }
function goPage(p) { visit({ channel: props.channel.key, q: props.filters.q || undefined, page: p }); }

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function pct(v) { return v == null ? '—' : (v * 100).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }
</script>

<style scoped>
.cfg-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-1.5 text-sm outline-none focus:border-emerald-400 transition; }
.btn-primary { @apply bg-emerald-500 hover:bg-emerald-600 text-white font-semibold rounded-lg px-4 py-2 transition shadow-sm; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 transition; }
.stat { @apply bg-white border border-slate-200 rounded-2xl px-5 py-3 min-w-[150px] shadow-sm; }
.stat-v { @apply font-mono text-2xl font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[11px] uppercase tracking-wide mt-0.5; }
.th-l { @apply text-left font-semibold px-3 py-2 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-3 py-2 whitespace-nowrap; }
.td-l { @apply text-left px-3 py-1.5 whitespace-nowrap; }
.td-r { @apply text-right px-3 py-1.5 font-mono whitespace-nowrap; }
</style>
