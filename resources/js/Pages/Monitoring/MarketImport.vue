<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-3xl mx-auto">
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-file-arrow-up"></i> Monitoramento de Preços
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Importar preços de mercado</h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Atualize o preço da concorrência por planilha. Cruza pelo <code class="k">sku</code> do produto — e,
                    como o <b>SKU Netshoes é universal</b>, também pelo <code class="k">netshoes_sku</code> (dá pra usar um
                    export de Buy Box da Netshoes direto).
                </p>
            </div>

            <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ flash.error }}
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400">Enviar arquivo (.xlsx ou .csv)</h2>
                    <button @click="downloadTemplate" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                        <i class="fa-solid fa-download mr-1"></i>Baixar modelo
                    </button>
                </div>

                <label :class="['dz block', form.file ? 'dz-filled' : '']" @dragover.prevent @drop.prevent="onDrop">
                    <div class="flex items-center gap-4">
                        <i :class="form.file ? 'fa-solid fa-file-circle-check text-emerald-500' : 'fa-solid fa-cloud-arrow-up text-slate-400'" class="text-3xl"></i>
                        <div>
                            <div class="font-semibold text-slate-700">{{ form.file ? form.file.name : 'Clique ou arraste o arquivo aqui' }}</div>
                            <div class="text-xs text-slate-400 mt-0.5">{{ form.file ? fileSize(form.file.size) : '.xlsx ou .csv · até 120 MB' }}</div>
                        </div>
                    </div>
                    <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onFile">
                </label>

                <div class="mt-6">
                    <button @click="submit" :disabled="!form.file || busy" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-database mr-2"></i>{{ busy ? 'Importando…' : 'Atualizar preços de mercado' }}
                    </button>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-3">Colunas aceitas</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="c in cols" :key="c" class="text-xs font-mono bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg">{{ c }}</span>
                </div>
                <p class="text-xs text-slate-400 mt-4">
                    <i class="fa-solid fa-key mr-1"></i> Chave: <code class="k">SKU</code> (ou SKU Netshoes) ·
                    Obrigatório: <code class="k">Preço Mercado</code> · Opcional: <code class="k">Vendedor</code>
                </p>
            </div>
        </div>

        <Teleport to="body">
        <transition name="fade">
            <div v-if="phase !== 'idle'" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8 text-center">
                    <div class="w-16 h-16 mx-auto mb-5 relative">
                        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-blue-500 border-t-transparent animate-spin"></div>
                        <i class="fa-solid fa-database absolute inset-0 flex items-center justify-center text-blue-500 text-lg"></i>
                    </div>
                    <template v-if="phase === 'uploading'">
                        <h3 class="text-lg font-bold text-slate-800">Enviando arquivo…</h3>
                        <p class="text-sm text-slate-400 mt-1">{{ uploadPct }}%</p>
                        <div class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-blue-500 rounded-full transition-all" :style="{ width: uploadPct + '%' }"></div>
                        </div>
                    </template>
                    <template v-else>
                        <h3 class="text-lg font-bold text-slate-800">Processando no servidor…</h3>
                        <p class="text-sm text-slate-500 mt-1">
                            <span class="font-mono font-bold text-blue-600 text-lg">{{ n(live.done) }}</span>
                            <span v-if="live.total"> de <span class="font-mono">{{ n(live.total) }}</span></span> linhas
                        </p>
                        <div v-if="live.total" class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: livePct + '%' }"></div>
                        </div>
                        <div v-else class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full w-1/3 bg-emerald-400 rounded-full animate-pulse"></div>
                        </div>
                    </template>
                </div>
            </div>
        </transition>
        </Teleport>

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
                        <div class="stat"><div class="stat-v">{{ n(result.rows) }}</div><div class="stat-l">linhas</div></div>
                        <div class="stat"><div class="stat-v text-emerald-600">{{ n(result.updated) }}</div><div class="stat-l">atualizados</div></div>
                        <div class="stat"><div class="stat-v text-slate-400">{{ n(result.skipped) }}</div><div class="stat-l">não encontrados</div></div>
                    </div>
                    <div class="mt-6 flex justify-center gap-2">
                        <Link :href="route('monitoring.products')" class="btn-primary">Ver produtos monitorados</Link>
                        <button @click="showResult = false" class="btn-ghost">Fechar</button>
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

const page = usePage();
const flash = computed(() => page.props.flash || {});
const cols = ['SKU', 'SKU Netshoes', 'Preço Mercado', 'Vendedor'];

const form = useForm({ file: null, progress_token: '' });
const phase = ref('idle');
const uploadPct = ref(0);
const live = ref({ done: 0, total: 0 });
const showResult = ref(false);
const resultData = ref(null);
let pollTimer = null;

const busy = computed(() => phase.value !== 'idle');
const livePct = computed(() => live.value.total ? Math.min(100, Math.round(live.value.done / live.value.total * 100)) : 0);
const result = computed(() => resultData.value || flash.value.importResult || null);

onMounted(() => { if (flash.value.importResult) showResult.value = true; });
onUnmounted(stopPoll);

function onFile(e) { form.file = e.target.files[0] || null; }
function onDrop(e) { const f = e.dataTransfer.files[0]; if (f) form.file = f; }

function submit() {
    if (!form.file) return;
    const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    form.progress_token = token;
    uploadPct.value = 0; live.value = { done: 0, total: 0 };
    resultData.value = null; showResult.value = false; phase.value = 'uploading';

    form.post(route('monitoring.market.import', { progress_token: token }), {
        preserveScroll: true, forceFormData: true,
        onProgress: (e) => {
            if (e && e.percentage != null) {
                uploadPct.value = Math.round(e.percentage);
                if (e.percentage >= 100 && phase.value === 'uploading') { phase.value = 'processing'; startPoll(token); }
            }
        },
        onError: () => { stopPoll(); phase.value = 'idle'; },
        onFinish: () => { stopPoll(); phase.value = 'idle'; form.reset('file', 'progress_token'); },
    });
}

function startPoll(token) {
    stopPoll();
    pollTimer = setInterval(async () => {
        try {
            const url = `/monitoring/market/progress/${encodeURIComponent(token)}?t=${Date.now()}`;
            const r = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            live.value = { done: d.done || 0, total: d.total || 0 };
            if (d.status === 'done') {
                if (d.result) { resultData.value = d.result; showResult.value = true; }
                phase.value = 'idle'; stopPoll();
            }
        } catch (e) { /* ignora */ }
    }, 1000);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

function downloadTemplate() {
    const csv = 'SKU;Preço Mercado;Vendedor\nSEU-SKU-123;199,90;Concorrente Exemplo\n';
    const blob = new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'modelo-precos-mercado.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function fileSize(b) { if (b < 1024) return b + ' B'; if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'; return (b / 1048576).toFixed(1) + ' MB'; }
</script>

<style scoped>
.btn-primary { @apply bg-blue-500 hover:bg-blue-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2.5 transition; }
.dz { @apply border-[1.5px] border-dashed border-slate-200 rounded-xl p-6 cursor-pointer transition hover:border-blue-300; }
.dz-filled { @apply border-solid border-emerald-300 bg-emerald-50/50; }
.stat { @apply bg-slate-50 border border-slate-200 rounded-xl px-2 py-2.5 text-center; }
.stat-v { @apply font-mono text-lg font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[10px] uppercase tracking-wide mt-0.5; }
.k { @apply font-mono bg-slate-100 text-blue-700 px-1.5 py-0.5 rounded text-[11px]; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
