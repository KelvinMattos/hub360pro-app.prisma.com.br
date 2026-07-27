<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-satellite-dish"></i> Monitoramento de Preços
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Produtos monitorados</h1>
                    <p class="text-slate-500 mt-2 font-medium">Seu preço vs. o preço de mercado, com o status de competitividade.</p>
                </div>
                <Link :href="route('monitoring.market.form')" class="btn-ghost">
                    <i class="fa-solid fa-file-arrow-up mr-2"></i>Importar preços de mercado
                </Link>
            </div>

            <!-- Filtros -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm mb-5">
                <div class="flex flex-wrap items-center gap-2">
                    <button v-for="f in statusFilters" :key="f.v" @click="applyFilter({ filter: f.v })"
                        :class="['px-3 py-1.5 rounded-lg text-xs font-bold border transition',
                            local.filter === f.v ? f.active : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50']">
                        {{ f.l }}
                    </button>

                    <select v-if="marketplaces.length" :value="local.marketplace || ''" @change="applyFilter({ marketplace: $event.target.value || null })"
                        class="ml-auto text-sm border border-slate-200 rounded-lg px-3 py-1.5 outline-none focus:border-blue-400">
                        <option value="">Todos os canais</option>
                        <option v-for="m in marketplaces" :key="m" :value="m">{{ m }}</option>
                    </select>

                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input v-model="search" @keyup.enter="applyFilter({ search })" type="text" placeholder="Buscar produto ou SKU…"
                            class="text-sm border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 w-56 outline-none focus:border-blue-400">
                    </div>
                </div>
            </div>

            <!-- Tabela -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/50">
                                <th class="py-3 px-4 font-bold cursor-pointer" @click="sortBy('titulo')">Produto <SortIcon :col="'titulo'" :s="local" /></th>
                                <th class="py-3 px-4 font-bold">Canal</th>
                                <th class="py-3 px-4 font-bold text-right cursor-pointer" @click="sortBy('preco')">Meu preço <SortIcon :col="'preco'" :s="local" /></th>
                                <th class="py-3 px-4 font-bold text-right cursor-pointer" @click="sortBy('market')">Mercado <SortIcon :col="'market'" :s="local" /></th>
                                <th class="py-3 px-4 font-bold text-right">Gap</th>
                                <th class="py-3 px-4 font-bold">Status</th>
                                <th class="py-3 px-4 font-bold">Vendedor</th>
                                <th class="py-3 px-4 font-bold text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in result.data" :key="p.id" class="border-b border-slate-50 hover:bg-slate-50/60">
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ p.titulo }}</div>
                                    <div class="text-xs text-slate-400 font-mono">{{ p.sku || '—' }}<span v-if="p.marca"> · {{ p.marca }}</span></div>
                                </td>
                                <td class="py-3 px-4 text-slate-500">{{ p.canal || '—' }}</td>
                                <td class="py-3 px-4 text-right font-mono text-slate-700">{{ money(p.preco) }}</td>
                                <td class="py-3 px-4 text-right font-mono" :class="p.market_price ? 'text-slate-700' : 'text-slate-300'">{{ p.market_price ? money(p.market_price) : '—' }}</td>
                                <td class="py-3 px-4 text-right font-mono" :class="gapClass(p.gap)">{{ p.gap == null ? '—' : p.gap + '%' }}</td>
                                <td class="py-3 px-4"><span :class="['badge', badge(p.status)]">{{ label(p.status) }}</span></td>
                                <td class="py-3 px-4 text-slate-500 text-xs">{{ p.seller || '—' }}</td>
                                <td class="py-3 px-4 text-right">
                                    <button @click="openEdit(p)" class="text-slate-400 hover:text-blue-600 transition" title="Definir preço de mercado">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!result.data.length">
                                <td colspan="8" class="py-10 text-center text-slate-400">Nenhum produto encontrado com esses filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginação -->
                <div v-if="result.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
                    <span class="text-slate-400">{{ n(result.total) }} produtos · página {{ result.page }} de {{ result.last_page }}</span>
                    <div class="flex gap-1">
                        <button :disabled="result.page <= 1" @click="applyFilter({ page: result.page - 1 })" class="pg">Anterior</button>
                        <button :disabled="result.page >= result.last_page" @click="applyFilter({ page: result.page + 1 })" class="pg">Próxima</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal editar preço de mercado -->
        <Teleport to="body">
        <transition name="fade">
            <div v-if="editing" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="editing = null">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
                    <h3 class="text-lg font-bold text-slate-900">Preço de mercado</h3>
                    <p class="text-sm text-slate-500 mt-0.5 line-clamp-1">{{ editing.titulo }}</p>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wide text-slate-400">Preço do concorrente (R$)</label>
                            <input v-model="editForm.market_price" type="number" step="0.01" min="0"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 font-mono outline-none focus:border-blue-400">
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wide text-slate-400">Vendedor (opcional)</label>
                            <input v-model="editForm.market_seller" type="text"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-blue-400">
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button @click="editing = null" class="btn-ghost">Cancelar</button>
                        <button @click="saveEdit" :disabled="editForm.processing" class="btn-primary">Salvar</button>
                    </div>
                </div>
            </div>
        </transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, h } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    result: { type: Object, default: () => ({ data: [], total: 0, page: 1, last_page: 1 }) },
    filters: { type: Object, default: () => ({}) },
    marketplaces: { type: Array, default: () => [] },
});

const local = ref({ ...props.filters });
const search = ref(props.filters.search || '');

const statusFilters = [
    { v: 'all', l: 'Todos', active: 'bg-slate-900 text-white border-slate-900' },
    { v: 'vendendo', l: 'Vendendo', active: 'bg-emerald-500 text-white border-emerald-500' },
    { v: 'perdendo', l: 'Perdendo', active: 'bg-red-500 text-white border-red-500' },
    { v: 'alerta', l: 'Alerta', active: 'bg-amber-500 text-white border-amber-500' },
    { v: 'desconhecido', l: 'Desconhecido', active: 'bg-slate-400 text-white border-slate-400' },
];

// Ícone de ordenação (componente inline)
const SortIcon = (p) => {
    if (p.s.sort !== p.col) return h('i', { class: 'fa-solid fa-sort text-slate-200 ml-1' });
    return h('i', { class: `fa-solid fa-sort-${p.s.dir === 'desc' ? 'down' : 'up'} text-slate-400 ml-1` });
};

function applyFilter(patch) {
    const q = { ...local.value, ...patch };
    if (!('page' in patch)) q.page = 1;
    Object.keys(q).forEach(k => { if (q[k] === null || q[k] === '' || q[k] === undefined) delete q[k]; });
    router.get(route('monitoring.products'), q, { preserveScroll: true, preserveState: true, replace: true });
}
function sortBy(col) {
    const dir = local.value.sort === col && local.value.dir === 'asc' ? 'desc' : 'asc';
    applyFilter({ sort: col, dir });
}

// Edição inline do preço de mercado
const editing = ref(null);
const editForm = useForm({ market_price: '', market_seller: '' });
function openEdit(p) {
    editing.value = p;
    editForm.market_price = p.market_price ?? '';
    editForm.market_seller = p.seller ?? '';
}
function saveEdit() {
    editForm.post(route('monitoring.market.set', { product: editing.value.id }), {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

function money(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function label(s) { return { vendendo: 'Vendendo', perdendo: 'Perdendo', alerta: 'Alerta', desconhecido: 'Desconhecido' }[s] || s; }
function badge(s) { return { vendendo: 'b-emerald', perdendo: 'b-red', alerta: 'b-amber', desconhecido: 'b-slate' }[s] || 'b-slate'; }
function gapClass(g) { if (g == null) return 'text-slate-300'; return g > 0 ? 'text-red-600' : 'text-emerald-600'; }
</script>

<style scoped>
.btn-primary { @apply bg-blue-500 hover:bg-blue-600 text-white font-semibold rounded-lg px-4 py-2 text-sm transition shadow-sm disabled:opacity-40; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm transition; }
.pg { @apply px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed text-xs font-semibold; }
.badge { @apply text-[11px] font-bold px-2 py-0.5 rounded-full; }
.b-emerald { @apply bg-emerald-100 text-emerald-700; }
.b-red { @apply bg-red-100 text-red-700; }
.b-amber { @apply bg-amber-100 text-amber-700; }
.b-slate { @apply bg-slate-100 text-slate-500; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
