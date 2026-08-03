<template>
    <AppLayout title="Importar Vendas Diárias por Canal">
        <div class="p-6 lg:p-8 max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-file-import"></i> Importações
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-calendar-days text-teal-500"></i>
                    Importar Vendas Diárias por Canal
                </h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Envie o arquivo "Diário de Vendas" (uma aba por canal, uma linha por dia). Os totais mensais,
                    semanais e o comparativo ano a ano do Desempenho por Canal são recalculados automaticamente
                    a partir dessa base — não é preciso reimportar nada além do diário.
                </p>
            </div>

            <div class="bg-teal-50 border border-teal-200 text-teal-800 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Cada aba da planilha vira um canal (Mercado Livre Matriz/Filial, Netshoes, Centauro, Site, Amazon,
                Shopee, Renner, Magalu, Grupo Casas Bahia, Dafiti, Shop Coopera, FBA Amazon...). Abas com nome não
                reconhecido são ignoradas e reportadas no resultado — nada é gravado às cegas. A data de cada linha
                vem da própria planilha; reimportar um mês já enviado atualiza os valores em vez de duplicar.
            </div>

            <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ flash.error }}
            </div>

            <!-- Formulário de upload -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Enviar arquivo (.xls ou .xlsx)</h2>

                <label :class="['dz block', form.file ? 'dz-filled' : '']" @dragover.prevent @drop.prevent="onDrop">
                    <div class="flex items-center gap-4">
                        <i :class="form.file ? 'fa-solid fa-file-circle-check text-emerald-500' : 'fa-solid fa-cloud-arrow-up text-slate-400'" class="text-3xl"></i>
                        <div>
                            <div class="font-semibold text-slate-700">{{ form.file ? form.file.name : 'Clique ou arraste o arquivo .xls/.xlsx aqui' }}</div>
                            <div class="text-xs text-slate-400 mt-0.5">{{ form.file ? fileSize(form.file.size) : 'Diário de Vendas por Canal · até 120 MB' }}</div>
                        </div>
                    </div>
                    <input type="file" accept=".xlsx,.xls" class="hidden" @change="onFile">
                </label>

                <div class="mt-6">
                    <button @click="submit" :disabled="!form.file || busy" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-database mr-2"></i>{{ busy ? 'Importando…' : 'Importar para o banco' }}
                    </button>
                </div>
            </div>

            <!-- Canais reconhecidos -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-3">Canais reconhecidos</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="c in channels" :key="c.key" class="text-xs font-mono bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg">{{ c.label }}</span>
                </div>
                <p class="text-xs text-slate-400 mt-4">
                    <i class="fa-solid fa-key mr-1"></i>
                    Colunas esperadas em cada aba: <code class="k">DATA</code>, <code class="k">PEDIDOS EFETUADOS</code>,
                    <code class="k">PEDIDOS PAGOS</code>, <code class="k">PEDIDOS CANCELADOS</code>,
                    <code class="k">TARIFAS DE VENDA</code>, <code class="k">CUSTO COM FRETE</code>,
                    <code class="k">TOTAL LÍQUIDO DO PEDIDO</code>, <code class="k">NUMERO DE PEDIDOS</code>.
                </p>
            </div>

            <div class="mt-6">
                <Link :href="route('sales.channel-performance.index')" class="text-teal-600 hover:text-teal-700 font-semibold text-sm">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Ver Desempenho por Canal
                </Link>
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
                        <p class="text-sm text-slate-500 mt-1">
                            <span class="font-mono font-bold text-teal-600 text-lg">{{ n(live.done) }}</span>
                            <span v-if="live.total"> de <span class="font-mono">{{ n(live.total) }}</span></span> abas
                        </p>
                        <div v-if="live.total" class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: livePct + '%' }"></div>
                        </div>
                        <div v-else class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full w-1/3 bg-emerald-400 rounded-full animate-pulse"></div>
                        </div>
                        <p class="text-xs text-slate-400 mt-3">Não feche esta aba.</p>
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
                        <div class="stat"><div class="stat-v">{{ n(result.rows_imported) }}</div><div class="stat-l">dias gravados</div></div>
                        <div class="stat"><div class="stat-v text-emerald-600">{{ n(result.sheets_recognized) }}</div><div class="stat-l">canais reconhecidos</div></div>
                        <div class="stat"><div class="stat-v text-slate-400">{{ n((result.sheets_ignored || []).length) }}</div><div class="stat-l">abas ignoradas</div></div>
                    </div>
                    <div v-if="result.ok && (result.sheets_ignored || []).length" class="mt-3 text-xs text-slate-400">
                        Ignoradas: {{ result.sheets_ignored.join(', ') }}
                    </div>

                    <div class="mt-6 flex justify-center gap-3">
                        <button @click="showResult = false" class="btn-secondary">Fechar</button>
                        <Link v-if="result.ok" :href="route('sales.channel-performance.index')" class="btn-primary">Ver Desempenho por Canal</Link>
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
    channels: { type: Array, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({ file: null, progress_token: '' });

const phase = ref('idle'); // idle | uploading | processing
const uploadPct = ref(0);
const live = ref({ done: 0, total: 0 });
const showResult = ref(false);
const resultData = ref(null);
let pollTimer = null;

const busy = computed(() => phase.value !== 'idle');
const livePct = computed(() => live.value.total ? Math.min(100, Math.round(live.value.done / live.value.total * 100)) : 0);
const result = computed(() => resultData.value || flash.value.importResult || null);

onMounted(() => {
    if (flash.value.importResult) { showResult.value = true; }
});
onUnmounted(stopPoll);

function onFile(e) { form.file = e.target.files[0] || null; }
function onDrop(e) { const f = e.dataTransfer.files[0]; if (f) form.file = f; }

function submit() {
    if (!form.file) return;
    const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    form.progress_token = token;
    uploadPct.value = 0;
    live.value = { done: 0, total: 0 };
    resultData.value = null;
    showResult.value = false;
    phase.value = 'uploading';

    form.post(route('sales.channel-import.import', { progress_token: token }), {
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
            const url = route('sales.channel-import.progress', token) + `?t=${Date.now()}`;
            const r = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            live.value = { done: d.done || 0, total: d.total || 0 };
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
.btn-primary { @apply bg-teal-500 hover:bg-teal-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.btn-secondary { @apply bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold rounded-lg px-5 py-2.5 transition; }
.dz { @apply border-[1.5px] border-dashed border-slate-200 rounded-xl p-6 cursor-pointer transition hover:border-teal-300; }
.dz-filled { @apply border-solid border-emerald-300 bg-emerald-50/50; }
.stat { @apply bg-slate-50 border border-slate-200 rounded-xl px-2 py-2.5 text-center; }
.stat-v { @apply font-mono text-lg font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[10px] uppercase tracking-wide mt-0.5; }
.k { @apply font-mono bg-slate-100 text-teal-700 px-1.5 py-0.5 rounded text-[11px]; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
