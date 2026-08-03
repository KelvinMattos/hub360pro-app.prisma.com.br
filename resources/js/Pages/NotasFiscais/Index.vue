<template>
    <AppLayout title="Notas Fiscais de Compra">
        <div class="p-6 max-w-7xl mx-auto space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-file-invoice text-blue-600"></i> Notas Fiscais de Compra
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Busque um produto (nome, EAN ou código) e encontre em quais notas e páginas ele aparece.</p>
                </div>
                <button @click="reindexar" :disabled="reindexing" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2">
                    <i class="fa-solid fa-rotate" :class="{ 'animate-spin': reindexing }"></i>
                    {{ reindexing ? 'Reindexando…' : 'Reindexar notas' }}
                </button>
            </div>

            <!-- Busca livre -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <form @submit.prevent="buscar" class="flex gap-3">
                    <input v-model="form.termo" type="text" placeholder="Nome do produto, EAN ou código…"
                        class="flex-1 rounded-lg border-gray-300 focus:ring-blue-500 focus:border-blue-500" />
                    <button type="submit" class="px-5 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i> Buscar
                    </button>
                </form>

                <div v-if="resultados && resultados.length" class="mt-5 space-y-3">
                    <p class="text-xs text-gray-500 font-semibold uppercase">{{ resultados.length }} ocorrência(s) encontrada(s)</p>
                    <div v-for="(r, i) in resultados" :key="i" class="border border-gray-100 rounded-xl p-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm font-semibold text-gray-800">{{ r.filename }} <span class="text-gray-400 font-normal">— {{ r.fornecedor }}</span></div>
                            <a :href="pdfUrl(r.nota_fiscal_id, r.page_number)" target="_blank" class="text-xs text-blue-600 hover:underline whitespace-nowrap ml-3">
                                Abrir pág. {{ r.page_number }} <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i>
                            </a>
                        </div>
                        <p class="text-xs text-gray-400 mb-1">{{ r.data_emissao || 'Sem data' }} · página {{ r.page_number }}</p>
                        <p class="text-sm text-gray-600 leading-relaxed" v-html="r.trecho"></p>
                    </div>
                </div>
                <p v-else-if="form.termo && submitted" class="mt-4 text-sm text-gray-400">Nenhuma ocorrência encontrada para "{{ form.termo }}".</p>
            </div>

            <!-- Filtros da listagem -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <form @submit.prevent="filtrar" class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">De</label>
                        <input v-model="filtroForm.data_inicio" type="date" class="w-full rounded-lg border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Até</label>
                        <input v-model="filtroForm.data_fim" type="date" class="w-full rounded-lg border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Fornecedor</label>
                        <input v-model="filtroForm.fornecedor" type="text" class="w-full rounded-lg border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Status</label>
                        <select v-model="filtroForm.status" class="w-full rounded-lg border-gray-300 text-sm">
                            <option value="">Todos</option>
                            <option value="indexed">Indexado</option>
                            <option value="pending">Pendente</option>
                            <option value="failed">Falhou</option>
                            <option value="orphaned">Órfão (arquivo removido)</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">Filtrar</button>
                </form>
            </div>

            <!-- Listagem -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="text-left px-5 py-3">Arquivo</th>
                            <th class="text-left px-5 py-3">Fornecedor</th>
                            <th class="text-left px-5 py-3">Emissão</th>
                            <th class="text-left px-5 py-3">Páginas</th>
                            <th class="text-left px-5 py-3">Status</th>
                            <th class="text-right px-5 py-3">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="nota in notas.data" :key="nota.id" class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800">{{ nota.filename }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ nota.supplier?.name || nota.fornecedor || '—' }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ nota.data_emissao || '—' }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ nota.pages_count }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="statusClass(nota.status)">
                                    {{ statusLabel(nota.status) }}
                                </span>
                                <span v-if="nota.error" class="block text-xs text-gray-400 mt-1">{{ nota.error }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a v-if="nota.status === 'indexed'" :href="pdfUrl(nota.id)" target="_blank" class="text-blue-600 hover:underline text-xs font-semibold">
                                    Abrir PDF <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i>
                                </a>
                            </td>
                        </tr>
                        <tr v-if="!notas.data.length">
                            <td colspan="6" class="px-5 py-10 text-center text-gray-400">Nenhuma nota fiscal encontrada. Clique em "Reindexar notas" para varrer a pasta.</td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :links="notas.links" class="p-4" />
            </div>
        </div>

        <!-- ===== OVERLAY DE PROGRESSO DA REINDEXAÇÃO ===== -->
        <Teleport to="body">
        <transition name="fade">
            <div v-if="reindexing" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8 text-center">
                    <div class="w-16 h-16 mx-auto mb-5 relative">
                        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-blue-500 border-t-transparent animate-spin"></div>
                        <i class="fa-solid fa-file-invoice absolute inset-0 flex items-center justify-center text-blue-500 text-lg"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800">Reindexando notas fiscais…</h3>
                    <p class="text-sm text-slate-500 mt-1">
                        <span class="font-mono font-bold text-blue-600 text-lg">{{ n(live.done) }}</span>
                        <span v-if="live.total"> de <span class="font-mono">{{ n(live.total) }}</span></span> PDFs
                    </p>
                    <div v-if="live.total" class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: livePct + '%' }"></div>
                    </div>
                    <div v-else class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full w-1/3 bg-emerald-400 rounded-full animate-pulse"></div>
                    </div>
                    <p class="text-xs text-slate-400 mt-3">Não feche esta aba. Um lote grande de PDFs pode levar vários minutos.</p>
                </div>
            </div>
        </transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { reactive, ref, computed, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    notas: { type: Object, required: true },
    resultados: { type: Array, default: () => [] },
    filtros: { type: Object, default: () => ({}) },
});

const form = reactive({ termo: props.filtros.termo || '' });
const filtroForm = reactive({
    data_inicio: props.filtros.data_inicio || '',
    data_fim: props.filtros.data_fim || '',
    fornecedor: props.filtros.fornecedor || '',
    status: props.filtros.status || '',
});
const submitted = ref(!!props.filtros.termo);
const reindexing = ref(false);
const live = ref({ done: 0, total: 0 });
const livePct = computed(() => live.value.total ? Math.min(100, Math.round(live.value.done / live.value.total * 100)) : 0);
let pollTimer = null;

onUnmounted(stopPoll);

function buscar() {
    submitted.value = true;
    router.get(route('notas-fiscais.index'), { ...filtroForm, termo: form.termo }, { preserveState: true, preserveScroll: true });
}

function filtrar() {
    router.get(route('notas-fiscais.index'), { ...filtroForm, termo: form.termo }, { preserveState: true, preserveScroll: true });
}

function reindexar() {
    const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    live.value = { done: 0, total: 0 };
    reindexing.value = true;
    startPoll(token);

    router.post(route('notas-fiscais.reindex'), { progress_token: token }, {
        preserveScroll: true,
        onError: () => {
            // Erro na conexão (ex.: Cloudflare cortando um lote grande em ~100s,
            // CLAUDE.md §6.3) não significa que a reindexação parou — o backend
            // continua rodando com ignore_user_abort e o resultado real chega
            // pelo polling. Só encerra o overlay quando o polling confirmar 'done'.
        },
        onFinish: () => {
            // Requisição HTTP terminou (com ou sem erro), mas o polling é quem
            // decide quando a reindexação de fato acabou.
        },
    });
}

function startPoll(token) {
    stopPoll();
    pollTimer = setInterval(async () => {
        try {
            const url = route('notas-fiscais.reindex.progress', token) + `?t=${Date.now()}`;
            const r = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            live.value = { done: d.done || 0, total: d.total || 0 };
            if (d.status === 'done') {
                reindexing.value = false;
                stopPoll();
                router.reload({ only: ['notas'] });
            }
        } catch (e) { /* ignora falhas transitórias de rede */ }
    }, 1000);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }

function pdfUrl(notaId, page) {
    const base = route('notas-fiscais.view', notaId);
    return page ? `${base}#page=${page}` : base;
}

function statusLabel(status) {
    return { indexed: 'Indexado', pending: 'Pendente', failed: 'Falhou', orphaned: 'Órfão' }[status] || status;
}
function statusClass(status) {
    return {
        indexed: 'bg-green-100 text-green-700',
        pending: 'bg-amber-100 text-amber-700',
        failed: 'bg-red-100 text-red-700',
        orphaned: 'bg-gray-100 text-gray-500',
    }[status] || 'bg-gray-100 text-gray-500';
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
