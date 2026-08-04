<template>
    <AppLayout :title="config.title">
        <div class="p-6 lg:p-8 max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-file-import"></i> Importações de ADS
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i :class="config.icon" class="text-teal-500"></i>
                    {{ config.title }}
                </h1>
                <p class="text-slate-500 mt-2 font-medium">{{ config.description }}</p>
            </div>

            <!-- Sub-navegação -->
            <div class="flex flex-wrap gap-2 mb-8">
                <Link v-for="t in allTypes" :key="t.key" :href="route('ads.import.show', { type: t.key })"
                    :class="['px-4 py-2 rounded-xl text-sm font-semibold border transition flex items-center gap-2',
                        t.key === type ? 'bg-teal-500 text-white border-teal-500 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']">
                    <i :class="t.icon"></i>{{ t.title }}
                </Link>
            </div>

            <div class="bg-teal-50 border border-teal-200 text-teal-800 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Cria/atualiza <b>gasto diário por campanha</b>, cruzando pela combinação
                <code class="k">{{ config.key_label }}</code>. Grava: <code class="k">{{ config.value_label }}</code>.
            </div>

            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-flask mr-2"></i>
                Este importador ainda não foi validado contra um arquivo real exportado por você — ele reconhece o
                formato padrão do relatório de campanha, mas se algum valor não bater ao testar, nos envie uma
                amostra do arquivo pra ajustarmos.
            </div>

            <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ flash.error }}
            </div>

            <!-- Sem conta cadastrada -->
            <div v-if="!accounts.length" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-xl mb-6 flex items-center justify-between gap-4">
                <span><i class="fa-solid fa-triangle-exclamation mr-2"></i>Nenhuma conta cadastrada pra esta plataforma ainda. Cadastre pelo menos uma antes de importar.</span>
                <Link :href="route('ads.accounts.index')" class="btn-secondary shrink-0">Cadastrar conta</Link>
            </div>

            <!-- Formulário de upload -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Enviar arquivo</h2>

                <div class="mb-4">
                    <label class="text-xs font-bold text-slate-400 uppercase block mb-1.5">Conta</label>
                    <select v-model.number="form.account_id" class="input w-full sm:w-72" :disabled="!accounts.length">
                        <option :value="null" disabled>Selecione a conta</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </div>

                <label :class="['dz block', form.file ? 'dz-filled' : '', !accounts.length ? 'opacity-50 pointer-events-none' : '']" @dragover.prevent @drop.prevent="onDrop">
                    <div class="flex items-center gap-4">
                        <i :class="form.file ? 'fa-solid fa-file-circle-check text-emerald-500' : 'fa-solid fa-cloud-arrow-up text-slate-400'" class="text-3xl"></i>
                        <div>
                            <div class="font-semibold text-slate-700">{{ form.file ? form.file.name : `Clique ou arraste o relatório de campanha aqui` }}</div>
                            <div class="text-xs text-slate-400 mt-0.5">{{ form.file ? fileSize(form.file.size) : 'Relatório exportado da plataforma · .csv ou .xlsx · até 120 MB' }}</div>
                        </div>
                    </div>
                    <input type="file" accept=".xlsx,.csv" class="hidden" @change="onFile">
                </label>

                <div class="mt-6">
                    <button @click="submit" :disabled="!form.file || !form.account_id || busy" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-database mr-2"></i>{{ busy ? 'Importando…' : 'Importar para o banco' }}
                    </button>
                </div>
            </div>

            <!-- Colunas esperadas -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-3">Colunas do arquivo original usadas</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="c in config.columns" :key="c" class="text-xs font-mono bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg">{{ c }}</span>
                </div>
            </div>
        </div>

        <!-- ===== OVERLAY DE CARREGAMENTO ===== -->
        <Teleport to="body">
        <transition name="fade">
            <div v-if="phase !== 'idle'" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8 text-center">
                    <div class="w-16 h-16 mx-auto mb-5 relative">
                        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-teal-500 border-t-transparent animate-spin"></div>
                        <i class="fa-solid fa-database absolute inset-0 flex items-center justify-center text-teal-500 text-lg"></i>
                    </div>

                    <template v-if="phase === 'uploading'">
                        <h3 class="text-lg font-bold text-slate-800">Enviando arquivo…</h3>
                        <p class="text-sm text-slate-400 mt-1">{{ uploadPct }}%</p>
                        <div class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-teal-500 rounded-full transition-all" :style="{ width: uploadPct + '%' }"></div>
                        </div>
                    </template>
                    <template v-else>
                        <h3 class="text-lg font-bold text-slate-800">Processando no servidor…</h3>
                        <p class="text-xs text-slate-400 mt-3">Não feche esta aba. Arquivos grandes podem levar alguns minutos.</p>
                    </template>
                </div>
            </div>
        </transition>
        </Teleport>

        <!-- ===== MODAL DE RESULTADO ===== -->
        <Teleport to="body">
        <transition name="fade">
            <div v-if="showResult && result" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="showResult = false">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-8">
                    <div class="text-center">
                        <div :class="['w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center text-3xl', result.ok ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600']">
                            <i :class="result.ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900">{{ result.ok ? 'Importação concluída' : 'Falha na importação' }}</h3>
                        <p class="text-sm text-slate-500 mt-2">{{ result.message }}</p>
                    </div>

                    <div v-if="result.ok" class="grid grid-cols-3 gap-3 mt-6">
                        <div class="stat"><div class="stat-v">{{ n(result.rows) }}</div><div class="stat-l">linhas lidas</div></div>
                        <div class="stat"><div class="stat-v text-emerald-600">{{ n(result.created) }}</div><div class="stat-l">gravadas</div></div>
                        <div class="stat"><div class="stat-v text-slate-400">{{ n(result.skipped) }}</div><div class="stat-l">ignoradas</div></div>
                    </div>

                    <div class="mt-6 flex justify-center">
                        <button @click="showResult = false" class="btn-primary">Fechar</button>
                    </div>
                </div>
            </div>
        </transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    type: { type: String, required: true },
    config: { type: Object, required: true },
    allTypes: { type: Array, required: true },
    accounts: { type: Array, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({ file: null, account_id: null, progress_token: '' });

const phase = ref('idle'); // idle | uploading | processing
const uploadPct = ref(0);
const showResult = ref(false);
const resultData = ref(null);
let pollTimer = null;

const busy = computed(() => phase.value !== 'idle');
const result = computed(() => resultData.value || flash.value.importResult || null);

onMounted(() => {
    if (flash.value.importResult) { showResult.value = true; }
});
onUnmounted(stopPoll);

function onFile(e) { form.file = e.target.files[0] || null; }
function onDrop(e) { const f = e.dataTransfer.files[0]; if (f) form.file = f; }

function submit() {
    if (!form.file || !form.account_id) return;
    const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    form.progress_token = token;
    uploadPct.value = 0;
    resultData.value = null;
    showResult.value = false;
    phase.value = 'uploading';

    form.post(route('ads.import.import', { type: props.type, progress_token: token }), {
        preserveScroll: true,
        forceFormData: true,
        onProgress: (e) => {
            if (e && e.percentage != null) {
                uploadPct.value = Math.round(e.percentage);
                if (e.percentage >= 100 && phase.value === 'uploading') {
                    phase.value = 'processing';
                    startPoll(token);
                }
            }
        },
        onError: () => {
            if (phase.value !== 'processing') { stopPoll(); phase.value = 'idle'; }
        },
        onSuccess: () => { form.reset('file', 'progress_token'); },
    });
}

function startPoll(token) {
    stopPoll();
    pollTimer = setInterval(async () => {
        try {
            const url = route('ads.import.progress', token) + `?t=${Date.now()}`;
            const r = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.status === 'done') {
                if (d.result) { resultData.value = d.result; showResult.value = true; }
                phase.value = 'idle';
                stopPoll();
            }
        } catch (e) { /* ignora falhas transitórias de rede */ }
    }, 1000);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function fileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}
</script>

<style scoped>
.input { @apply border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-200; }
.btn-primary { @apply bg-teal-500 hover:bg-teal-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.btn-secondary { @apply bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold rounded-lg px-4 py-2 transition text-sm; }
.dz { @apply border-[1.5px] border-dashed border-slate-200 rounded-xl p-6 cursor-pointer transition hover:border-teal-300; }
.dz-filled { @apply border-solid border-emerald-300 bg-emerald-50/50; }
.stat { @apply bg-slate-50 border border-slate-200 rounded-xl px-2 py-2.5 text-center; }
.stat-v { @apply font-mono text-lg font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[10px] uppercase tracking-wide mt-0.5; }
.k { @apply font-mono bg-slate-100 text-teal-700 px-1.5 py-0.5 rounded text-[11px]; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
