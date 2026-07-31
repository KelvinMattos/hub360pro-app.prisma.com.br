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
    </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
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

function buscar() {
    submitted.value = true;
    router.get(route('notas-fiscais.index'), { ...filtroForm, termo: form.termo }, { preserveState: true, preserveScroll: true });
}

function filtrar() {
    router.get(route('notas-fiscais.index'), { ...filtroForm, termo: form.termo }, { preserveState: true, preserveScroll: true });
}

function reindexar() {
    reindexing.value = true;
    router.post(route('notas-fiscais.reindex'), {}, {
        onFinish: () => { reindexing.value = false; },
    });
}

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
