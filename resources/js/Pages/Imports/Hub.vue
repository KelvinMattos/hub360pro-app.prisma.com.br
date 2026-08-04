<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-4xl mx-auto">
            <div class="mb-8">
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-inbox text-blue-500"></i>
                    Central de <span class="text-blue-600">Importações</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Solte o arquivo aqui — o sistema identifica o tipo pelo cabeçalho (ou pelas abas, no caso do
                    Diário de Vendas) e leva direto pro lugar certo.
                </p>
            </div>

            <!-- Dropzone -->
            <div v-if="phase === 'idle' || phase === 'detecting'"
                class="bg-white border-2 border-dashed rounded-2xl p-10 text-center transition"
                :class="dragOver ? 'border-blue-400 bg-blue-50/50' : 'border-slate-200'"
                @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop">
                <template v-if="phase === 'detecting'">
                    <div class="w-12 h-12 mx-auto mb-4 relative">
                        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-blue-500 border-t-transparent animate-spin"></div>
                    </div>
                    <p class="font-semibold text-slate-700">Analisando {{ pendingFile?.name }}…</p>
                    <p class="text-xs text-slate-400 mt-1">Só lê o cabeçalho — nada é gravado ainda.</p>
                </template>
                <template v-else>
                    <i class="fa-solid fa-cloud-arrow-up text-4xl text-slate-300"></i>
                    <p class="font-semibold text-slate-700 mt-3">Clique ou arraste o arquivo aqui</p>
                    <p class="text-xs text-slate-400 mt-1">.csv, .txt ou .xlsx — até 100 MB</p>
                    <label class="inline-block mt-4 bg-blue-500 hover:bg-blue-600 text-white font-semibold rounded-lg px-5 py-2.5 transition cursor-pointer">
                        Escolher arquivo
                        <input type="file" accept=".csv,.txt,.xlsx,.xls" class="hidden" @change="onFileInput">
                    </label>
                </template>
            </div>

            <!-- Erro de transporte na detecção -->
            <div v-if="detectError" class="mt-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ detectError }}
            </div>

            <!-- Resultado: confiante ou candidato escolhido -->
            <div v-if="phase === 'result' && selected" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center text-xl shrink-0">
                        <i :class="selected.icon"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] font-black uppercase tracking-widest text-blue-500 mb-0.5">
                            {{ detection.status === 'confident' ? 'Detectado automaticamente' : 'Você escolheu' }}
                        </div>
                        <h2 class="text-lg font-bold text-slate-900">{{ selected.title }}</h2>
                        <p class="text-sm text-slate-500 mt-1">{{ selected.description }}</p>
                    </div>
                </div>

                <!-- Colunas -->
                <div v-if="selected.matched_columns?.length || selected.missing_columns?.length" class="mt-5 flex flex-wrap gap-1.5">
                    <span v-for="c in selected.matched_columns" :key="'m-' + c"
                        class="text-xs font-mono bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-1 rounded-lg">
                        <i class="fa-solid fa-check text-[10px] mr-1"></i>{{ c }}
                    </span>
                    <span v-for="c in selected.missing_columns" :key="'x-' + c"
                        class="text-xs font-mono bg-slate-50 text-slate-400 border border-slate-200 px-2 py-1 rounded-lg">{{ c }}</span>
                </div>

                <!-- Conta (quando o tipo exige) -->
                <div v-if="selected.needs_account" class="mt-5">
                    <label class="text-xs font-black uppercase tracking-widest text-slate-400">Conta</label>
                    <div v-if="!selected.accounts?.length" class="mt-2 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-3">
                        <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                        Nenhuma conta ativa cadastrada pra esse canal.
                        <Link v-if="selected.account_manage_route" :href="route(selected.account_manage_route)" class="font-semibold underline ml-1">
                            Cadastrar conta
                        </Link>
                    </div>
                    <select v-else v-model="accountId" class="mt-2 w-full bg-white border border-slate-200 rounded-lg px-3 py-2.5 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                        <option :value="null" disabled>Selecione a conta…</option>
                        <option v-for="a in selected.accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </div>

                <!-- Criar inexistentes (quando o tipo suporta) -->
                <div v-if="selected.needs_create_missing" class="mt-4 flex items-start gap-2">
                    <input id="createMissing" type="checkbox" v-model="createMissing" class="mt-1 accent-blue-500">
                    <label for="createMissing" class="text-sm text-slate-600">Criar registros que ainda não existem no banco.</label>
                </div>

                <!-- Ações -->
                <div class="mt-6 flex items-center gap-3">
                    <button @click="confirmImport" :disabled="!canConfirm || submitting"
                        class="bg-blue-500 hover:bg-blue-600 text-white font-bold rounded-lg px-5 py-2.5 transition disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-check mr-2"></i>{{ submitting ? 'Enviando…' : 'Confirmar e importar' }}
                    </button>
                    <button @click="reset" :disabled="submitting" class="text-slate-500 hover:text-slate-700 font-semibold text-sm disabled:opacity-40">
                        Cancelar
                    </button>
                    <button v-if="detection.candidates?.length || detection.status === 'confident'" @click="showManualPicker = !showManualPicker"
                        :disabled="submitting" class="ml-auto text-slate-400 hover:text-slate-600 text-xs font-semibold disabled:opacity-40">
                        Não é isso? Escolher manualmente
                    </button>
                </div>

                <p v-if="submitting" class="text-xs text-slate-400 mt-3">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    Enviando e processando — arquivos grandes podem levar alguns minutos. Não feche esta aba.
                </p>
            </div>

            <!-- Ambíguo: mais de um tipo bateu com score parecido -->
            <div v-if="phase === 'result' && detection?.status === 'ambiguous' && !selected" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <h2 class="text-sm font-black uppercase tracking-widest text-amber-600 mb-1">
                    <i class="fa-solid fa-circle-question mr-1"></i> Mais de um tipo bateu — qual é o certo?
                </h2>
                <p class="text-sm text-slate-500 mb-4">O cabeçalho desse arquivo é parecido com mais de uma tela. Escolha a correta.</p>
                <div class="grid gap-2">
                    <button v-for="c in detection.candidates" :key="c.source + (c.type || '')" @click="selected = c"
                        class="text-left bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 rounded-xl px-4 py-3 transition">
                        <div class="flex items-center gap-3">
                            <i :class="c.icon" class="text-blue-500"></i>
                            <span class="font-semibold text-slate-800">{{ c.title }}</span>
                            <span class="ml-auto text-xs font-mono text-slate-400">{{ Math.round(c.score * 100) }}% de match</span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Não reconhecido -->
            <div v-if="phase === 'result' && detection?.status === 'unknown'" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <h2 class="text-sm font-black uppercase tracking-widest text-slate-500 mb-1">
                    <i class="fa-solid fa-circle-question mr-1"></i> Não reconheci esse arquivo
                </h2>
                <p class="text-sm text-slate-500 mb-2">{{ detection.message || 'O cabeçalho não bateu com nenhum tipo conhecido de importação.' }}</p>
                <button @click="reset" class="text-blue-600 hover:underline text-sm font-semibold">Tentar outro arquivo</button>
                <div class="mt-2">
                    <button @click="showManualPicker = true" class="text-slate-500 hover:underline text-sm font-semibold">
                        Ou escolher a tela manualmente
                    </button>
                </div>
            </div>

            <!-- Grade manual (fallback, sempre disponível) -->
            <div v-if="showManualPicker || (phase === 'result' && detection?.status === 'unknown')" class="mt-6">
                <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-3">Todas as telas de importação</h3>
                <div v-for="group in groupedCatalog" :key="group.key" class="mb-5">
                    <div class="text-xs font-bold text-slate-500 mb-2">{{ group.label }}</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <Link v-for="c in group.items" :key="c.source + (c.type || '')"
                            :href="route(c.show_route, c.route_params)"
                            class="bg-white border border-slate-200 hover:border-blue-300 hover:bg-blue-50/40 rounded-xl px-4 py-3 flex items-center gap-3 transition">
                            <i :class="c.icon" class="text-slate-400"></i>
                            <span class="text-sm font-semibold text-slate-700">{{ c.title }}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    catalog: { type: Array, default: () => [] },
});

const GROUP_LABELS = {
    magazord: 'Magazord',
    netshoes: 'Netshoes',
    order_channel: 'Vendas por Marketplace',
    ads: 'Gasto de ADS',
    sales_channel: 'Diário de Vendas por Canal',
    market_price: 'Preço de Mercado (Buy Box)',
};

const groupedCatalog = computed(() => {
    const bySource = {};
    for (const c of props.catalog) {
        (bySource[c.source] ??= []).push(c);
    }
    return Object.keys(bySource).map(key => ({
        key, label: GROUP_LABELS[key] || key, items: bySource[key],
    }));
});

const phase = ref('idle'); // idle | detecting | result
const dragOver = ref(false);
const pendingFile = ref(null);
const detection = ref(null);
const detectError = ref('');
const selected = ref(null);
const accountId = ref(null);
const createMissing = ref(false);
const submitting = ref(false);
const showManualPicker = ref(false);

const canConfirm = computed(() => {
    if (!selected.value) return false;
    if (selected.value.needs_account && (!accountId.value || !selected.value.accounts?.length)) return false;
    return true;
});

function onFileInput(e) {
    const f = e.target.files[0];
    if (f) detectFile(f);
}
function onDrop(e) {
    dragOver.value = false;
    const f = e.dataTransfer.files[0];
    if (f) detectFile(f);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function detectFile(file) {
    reset();
    pendingFile.value = file;
    phase.value = 'detecting';

    const formData = new FormData();
    formData.append('file', file);

    try {
        const { data } = await axios.post(route('imports.hub.detect'), formData, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        detection.value = data;
        if (data.status === 'confident') {
            selected.value = data.match;
        }
        phase.value = 'result';
    } catch (e) {
        const status = e.response?.status;
        detectError.value = status === 419
            ? 'Sessão expirada (419). Recarregue a página e tente de novo.'
            : (e.response?.data?.message || `Falha ao analisar o arquivo (HTTP ${status || '?'}).`);
        phase.value = 'idle';
    }
}

function confirmImport() {
    if (!canConfirm.value || !pendingFile.value || !selected.value) return;
    submitting.value = true;

    const payload = { file: pendingFile.value };
    if (selected.value.needs_account) payload.account_id = accountId.value;
    if (selected.value.needs_create_missing) payload.create_missing = createMissing.value;

    const form = useForm(payload);
    form.post(route(selected.value.import_route, selected.value.route_params), {
        forceFormData: true,
        onError: () => { submitting.value = false; },
    });
}

function reset() {
    phase.value = 'idle';
    pendingFile.value = null;
    detection.value = null;
    detectError.value = '';
    selected.value = null;
    accountId.value = null;
    createMissing.value = false;
    submitting.value = false;
    showManualPicker.value = false;
}
</script>
