<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1600px] mx-auto">
            <!-- Header -->
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-table-cells text-emerald-500"></i>
                        Preços por <span class="bg-gradient-to-r from-emerald-500 to-teal-600 bg-clip-text text-transparent">Canal</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        O preço de cada produto em cada canal em que é vendido, lado a lado — {{ n(stats.total) }} produtos.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('pricing.calculo-promo')" class="btn-ghost text-sm"><i class="fa-solid fa-tags mr-2"></i>Cálculo Promo</Link>
                </div>
            </div>

            <div v-if="!has_channel_prices" class="bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Ainda não há preços por canal no banco. Importe o modelo <b>Preços de Venda (Consulta Dinâmica)</b> em
                Importações Magazord, ou <b>Importar Preços Netshoes</b> em Importações Netshoes.
            </div>

            <!-- Stats -->
            <div class="flex flex-wrap gap-4 mb-5">
                <div class="stat"><div class="stat-v">{{ n(stats.total) }}</div><div class="stat-l">produtos ativos</div></div>
                <div class="stat"><div class="stat-v text-amber-600">{{ n(stats.sem_vinculo) }}</div><div class="stat-l">sem preço em nenhum canal</div></div>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input v-model="q" @keyup.enter="applyFilters" type="text" placeholder="Buscar SKU ou produto..." class="cfg-input !w-72 !pl-8 font-mono">
                </div>
                <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-3 py-1.5">
                    <span class="text-xs text-slate-400 font-semibold uppercase tracking-wide">Só o canal</span>
                    <select v-model="onlyChannel" @change="applyFilters" class="text-sm font-semibold text-slate-700 bg-transparent outline-none">
                        <option value="">Todos</option>
                        <option v-for="c in channels" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
                <button @click="applyFilters" class="btn-ghost text-sm">Buscar</button>
                <span v-if="filters.q || filters.only_channel" class="text-xs text-slate-400">
                    filtrando<span v-if="filters.q"> por “{{ filters.q }}”</span><span v-if="filters.only_channel"> · só {{ filters.only_channel }}</span>
                </span>
            </div>

            <!-- Tabela -->
            <div class="overflow-auto max-h-[65vh] border border-slate-200 rounded-2xl bg-white">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-50 z-10">
                        <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                            <th class="th-l">SKU</th>
                            <th class="th-l">Produto</th>
                            <th class="th-r">Estoque</th>
                            <th v-for="c in channels" :key="c" class="th-r">{{ c }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="border-t border-slate-100 hover:bg-slate-50/60">
                            <td class="td-l">{{ r.sku }}</td>
                            <td class="td-l max-w-[240px] truncate" :title="r.title">{{ r.title }}</td>
                            <td class="td-r">{{ n(r.stock) }}</td>
                            <td v-for="c in channels" :key="c" class="td-r" :class="r.prices[c] === null ? 'text-slate-300' : 'text-slate-800 font-semibold'">
                                {{ r.prices[c] === null ? '—' : money(r.prices[c]) }}
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="3 + channels.length" class="text-center text-slate-400 py-10">
                            Nenhum produto encontrado com esses filtros.
                        </td></tr>
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
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    channels: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    pagination: { type: Object, default: () => ({}) },
    has_channel_prices: { type: Boolean, default: true },
});

const q = ref(props.filters.q || '');
const onlyChannel = ref(props.filters.only_channel || '');

function visit(params) {
    router.get(route('pricing.channel-prices'), params, { preserveScroll: true, preserveState: false });
}
function applyFilters() { visit({ q: q.value || undefined, only_channel: onlyChannel.value || undefined }); }
function goPage(p) { visit({ q: props.filters.q || undefined, only_channel: props.filters.only_channel || undefined, page: p }); }

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<style scoped>
.cfg-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-1.5 text-sm outline-none focus:border-emerald-400 transition; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 transition; }
.stat { @apply bg-white border border-slate-200 rounded-2xl px-5 py-3 min-w-[150px] shadow-sm; }
.stat-v { @apply font-mono text-2xl font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[11px] uppercase tracking-wide mt-0.5; }
.th-l { @apply text-left font-semibold px-3 py-2 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-3 py-2 whitespace-nowrap; }
.td-l { @apply text-left px-3 py-1.5 whitespace-nowrap; }
.td-r { @apply text-right px-3 py-1.5 font-mono whitespace-nowrap; }
</style>
