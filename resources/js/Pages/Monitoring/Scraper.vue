<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-5xl mx-auto">
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-robot"></i> Monitoramento de Preços
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Coleta de Buy Box · Netshoes</h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Busca cada produto na Netshoes pelo <b>SKU Netshoes</b> (universal entre sellers) e traz
                    <b>preço</b>, <b>loja vencedora</b> e <b>link do anúncio</b>.
                </p>
            </div>

            <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ flash.error }}
            </div>

            <!-- Aviso de bloqueio -->
            <div class="bg-amber-50 border-2 border-amber-300 rounded-2xl p-5 mb-6">
                <div class="flex gap-3">
                    <i class="fa-solid fa-shield-halved text-amber-500 text-xl mt-0.5"></i>
                    <div class="text-sm">
                        <div class="font-bold text-slate-800 mb-1">A Netshoes bloqueia coleta por servidor</div>
                        <p class="text-slate-600">
                            As requisições do servidor recebem <b>HTTP 403 (Access Denied)</b> na borda do site. Por isso esta
                            coleta vem <b>desativada</b> e não tentamos contornar o bloqueio.
                        </p>
                        <p class="text-slate-600 mt-2">
                            Use a
                            <Link :href="route('monitoring.market.form')" class="text-blue-600 font-semibold">importação de preços de mercado</Link>
                            com o relatório de Buy Box do Seller Center (ou o export do Hooklab). Esta tela serve para
                            <b>diagnóstico</b> e ficará pronta caso a coleta autorizada seja liberada.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Aviso: sem SKU Netshoes -->
            <div v-if="!stats.elegiveis" class="bg-red-50 border-2 border-red-300 rounded-2xl p-5 mb-6">
                <div class="flex gap-3">
                    <i class="fa-solid fa-circle-exclamation text-red-500 text-xl mt-0.5"></i>
                    <div class="text-sm">
                        <div class="font-bold text-slate-800 mb-1">Nenhum produto tem SKU Netshoes</div>
                        <p class="text-slate-600">
                            Sem isso não há o que coletar nem reprecificar. Rode primeiro a
                            <Link :href="route('netshoes.show', { type: 'produtos' })" class="text-blue-600 font-semibold">importação de Produtos Netshoes</Link>
                            (export "Portal"), que preenche o <code class="k">netshoes_sku</code>.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="kpi"><div class="kpi-l">Produtos com SKU Netshoes</div>
                    <div class="kpi-v" :class="stats.elegiveis ? '' : 'text-red-500'">{{ n(stats.elegiveis) }}</div></div>
                <div class="kpi"><div class="kpi-l">Já coletados</div><div class="kpi-v text-emerald-600">{{ n(stats.coletados) }}</div></div>
            </div>

            <!-- Diagnóstico -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">1. Testar um SKU</h2>
                <p class="text-sm text-slate-500 mb-4">Valide a coleta antes de rodar em lote. Mostra exatamente o que foi capturado.</p>
                <div class="flex flex-wrap gap-2">
                    <input v-model="testSku" type="text" placeholder="Ex.: 39V-24AJ-205-43"
                        class="flex-1 min-w-56 border border-slate-200 rounded-lg px-3 py-2 font-mono text-sm outline-none focus:border-blue-400">
                    <button @click="runTest" :disabled="testing || !testSku" class="btn-primary disabled:opacity-40">
                        <i class="fa-solid fa-vial mr-2"></i>{{ testing ? 'Testando…' : 'Testar' }}
                    </button>
                </div>

                <div v-if="testResult" class="mt-4 rounded-xl border p-4 text-sm"
                    :class="testResult.ok ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200'">
                    <div class="font-bold mb-2" :class="testResult.ok ? 'text-emerald-700' : 'text-red-700'">
                        <i :class="testResult.ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'" class="mr-1"></i>
                        <template v-if="testResult.ok">Capturado com sucesso</template>
                        <template v-else-if="testResult.layer === 'transporte'">
                            Falha antes de consultar o site (erro do próprio sistema)
                        </template>
                        <template v-else>Não foi possível capturar do site</template>
                    </div>
                    <div v-if="!testResult.ok && testResult.error"
                        class="mb-3 text-sm font-semibold"
                        :class="testResult.layer === 'transporte' ? 'text-amber-700' : 'text-red-700'">
                        {{ testResult.error }}
                    </div>
                    <dl class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <div><dt class="dt">Situação</dt><dd class="dd"><span class="font-mono text-xs">{{ testResult.status || '—' }}</span></dd></div>
                        <div><dt class="dt">Preço do anúncio</dt><dd class="dd">{{ testResult.price ? money(testResult.price) : '—' }}</dd></div>
                        <div><dt class="dt">Preço PIX <span class="font-normal normal-case">(não é mercado)</span></dt><dd class="dd text-slate-400">{{ testResult.pix_price ? money(testResult.pix_price) : '—' }}</dd></div>
                        <div><dt class="dt">Loja vencedora</dt><dd class="dd">{{ testResult.seller || '—' }}</dd></div>
                        <div><dt class="dt">offerCount <span class="font-normal normal-case">(faixas, não sellers)</span></dt><dd class="dd">{{ testResult.offers ?? '—' }}</dd></div>
                        <div><dt class="dt">Estamos ganhando?</dt>
                            <dd class="dd">
                                <span v-if="testResult.buybox_winner === true" class="text-emerald-600 font-bold">Sim</span>
                                <span v-else-if="testResult.buybox_winner === false" class="text-red-600 font-bold">Não</span>
                                <span v-else class="text-slate-400">Configure o nome da loja</span>
                            </dd>
                        </div>
                        <div><dt class="dt">HTTP</dt><dd class="dd">{{ testResult.http ?? '—' }}</dd></div>
                        <div><dt class="dt">Estratégia</dt><dd class="dd font-mono text-xs">{{ testResult.strategy || '—' }}</dd></div>
                        <div class="col-span-2 md:col-span-3"><dt class="dt">Código buscado</dt><dd class="dd font-mono text-xs">{{ testResult.code }}</dd></div>
                        <div class="col-span-2 md:col-span-3"><dt class="dt">Link</dt>
                            <dd class="dd"><a v-if="testResult.url" :href="testResult.url" target="_blank" rel="noopener" class="text-blue-600 hover:underline break-all text-xs">{{ testResult.url }}</a><span v-else>—</span></dd>
                        </div>
                        <div v-if="testResult.error" class="col-span-2 md:col-span-3">
                            <dt class="dt">Erro</dt><dd class="dd text-red-600 text-xs">{{ testResult.error }}</dd>
                        </div>
                    </dl>
                    <p v-if="!testResult.ok && testResult.layer === 'transporte'" class="text-xs text-slate-500 mt-3">
                        Este erro é da aplicação, não da Netshoes. Em 419, recarregue a página (a sessão expirou).
                        Persistindo, confira se o deploy está atualizado.
                    </p>
                    <p v-else-if="!testResult.ok && testResult.status === 'blocked'" class="text-xs text-slate-500 mt-3">
                        A Netshoes recusou a requisição do servidor (403). É o bloqueio esperado — use a importação
                        do relatório de Buy Box.
                    </p>
                    <p v-else-if="!testResult.ok" class="text-xs text-slate-500 mt-3">
                        Se o HTML veio ({{ n(testResult.html_len) }} bytes) mas nada foi extraído, o layout do site mudou —
                        me envie este diagnóstico que eu ajusto o parser.
                    </p>
                </div>
            </div>

            <!-- Configuração -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">2. Configuração</h2>
                <p class="text-sm text-slate-500 mb-4">O nome da loja é o que define se <b>nós</b> estamos ganhando a Buy Box.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 border-b border-slate-100 pb-4 mb-1">
                        <label class="flex items-center gap-2 text-sm font-bold text-slate-800">
                            <input type="checkbox" v-model="form.scraper_enabled" class="accent-amber-500">
                            Ativar coleta direta do site
                            <span class="text-xs font-normal text-slate-400">(bloqueada hoje — deixe desligado)</span>
                        </label>
                    </div>
                    <div class="md:col-span-2">
                        <label class="lb">Nome da nossa loja na Netshoes</label>
                        <input v-model="form.netshoes_seller_name" type="text" placeholder="Ex.: Sportime" class="inp">
                    </div>
                    <div class="md:col-span-2">
                        <label class="lb">URL de busca <span class="text-slate-400 font-normal">({code} = produto sem tamanho, {sku} = SKU completo)</span></label>
                        <input v-model="form.search_url" type="text" class="inp font-mono text-xs">
                    </div>
                    <div><label class="lb">Pausa entre requisições (ms)</label><input v-model.number="form.delay_ms" type="number" min="0" max="10000" class="inp"></div>
                    <div><label class="lb">Timeout (s)</label><input v-model.number="form.timeout" type="number" min="5" max="60" class="inp"></div>
                    <div><label class="lb">Produtos por rodada</label><input v-model.number="form.batch_limit" type="number" min="1" max="2000" class="inp"></div>
                    <div><label class="lb">Não recoletar antes de (horas)</label><input v-model.number="form.recheck_hours" type="number" min="0" max="720" class="inp"></div>
                </div>
                <div class="mt-4">
                    <button @click="saveConfig" :disabled="form.processing" class="btn-ghost"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar configuração</button>
                </div>
            </div>

            <!-- Execução -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">3. Rodar coleta</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Processa até <b>{{ n(form.batch_limit) }}</b> produtos, respeitando a pausa entre requisições.
                    Estimativa: ~{{ eta }}.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <button @click="runBatch" :disabled="busy || !form.scraper_enabled || !stats.elegiveis" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-play mr-2"></i>{{ busy ? 'Coletando…' : 'Coletar agora' }}
                    </button>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" v-model="runForm.force" class="accent-blue-500"> Recoletar tudo (ignorar recentes)
                    </label>
                </div>
                <div class="mt-4 bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs text-slate-500">
                    <i class="fa-solid fa-clock mr-1"></i> Para rodar sozinho, agende no cron do cPanel:
                    <code class="block mt-1 font-mono text-slate-700">php /home2/kelvi593/app.prismaads.com.br/artisan netshoes:buybox --limit=300</code>
                </div>

                <div v-if="erros.length" class="mt-5">
                    <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Erros mais comuns</div>
                    <div v-for="e in erros" :key="e.erro" class="flex items-center justify-between text-xs border-b border-slate-100 py-1.5">
                        <span class="text-slate-600 truncate mr-3">{{ e.erro }}</span>
                        <span class="font-mono font-bold text-red-500">{{ n(e.total) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overlay de progresso -->
        <Teleport to="body">
        <transition name="fade">
            <div v-if="busy" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8 text-center">
                    <div class="w-16 h-16 mx-auto mb-5 relative">
                        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-blue-500 border-t-transparent animate-spin"></div>
                        <i class="fa-solid fa-robot absolute inset-0 flex items-center justify-center text-blue-500 text-lg"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800">Coletando Buy Box…</h3>
                    <p class="text-sm text-slate-500 mt-1">
                        <span class="font-mono font-bold text-blue-600 text-lg">{{ n(live.done) }}</span>
                        <span v-if="live.total"> de <span class="font-mono">{{ n(live.total) }}</span></span> produtos
                    </p>
                    <div v-if="live.total" class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: pct + '%' }"></div>
                    </div>
                    <div class="flex justify-center gap-4 mt-4 text-xs">
                        <span class="text-emerald-600 font-bold">{{ n(live.winning) }} ganhando</span>
                        <span class="text-red-600 font-bold">{{ n(live.losing) }} perdendo</span>
                        <span class="text-slate-400">{{ n(live.fail) }} falhas</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-3">Não feche esta aba.</p>
                </div>
            </div>
        </transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({ elegiveis: 0, coletados: 0 }) },
    erros: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({
    scraper_enabled: !!props.config.scraper_enabled,
    netshoes_seller_name: props.config.netshoes_seller_name || '',
    search_url: props.config.search_url || '',
    timeout: props.config.timeout ?? 20,
    delay_ms: props.config.delay_ms ?? 1500,
    batch_limit: props.config.batch_limit ?? 200,
    recheck_hours: props.config.recheck_hours ?? 12,
});
const runForm = useForm({ progress_token: '', force: false, batch_limit: form.batch_limit });

const testSku = ref('');
const testing = ref(false);
const testResult = ref(null);

const busy = ref(false);
const live = ref({ done: 0, total: 0, winning: 0, losing: 0, fail: 0 });
let pollTimer = null;

const pct = computed(() => live.value.total ? Math.min(100, Math.round(live.value.done / live.value.total * 100)) : 0);
const eta = computed(() => {
    const secs = Math.round((form.batch_limit * (form.delay_ms + 800)) / 1000);
    if (secs < 60) return `${secs}s`;
    return `${Math.round(secs / 60)} min`;
});

onUnmounted(stopPoll);

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

/** Mensagem honesta por status de transporte — nunca esconder o motivo real. */
function transportMessage(status, data) {
    if (status === 419) return 'Sessão expirada / token CSRF inválido (419). Recarregue a página e tente de novo.';
    if (status === 401) return 'Não autenticado (401). Faça login novamente.';
    if (status === 403) return 'Sem permissão para executar o diagnóstico (403).';
    if (status === 404) return 'Rota de diagnóstico não encontrada (404). O deploy pode estar desatualizado.';
    if (status === 419 || status === 422) return data?.message || 'Requisição inválida (422).';
    if (status >= 500) return data?.message || `Erro interno do servidor (${status}). Verifique o laravel.log.`;
    return data?.message || `Falha na requisição (HTTP ${status}).`;
}

async function runTest() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(
            route('monitoring.scraper.test'),
            { sku: testSku.value },
            { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() } }
        );

        // Resposta 2xx mas fora do formato esperado (ex.: erro serializado).
        if (!data || typeof data !== 'object' || !('status' in data)) {
            testResult.value = {
                ok: false, layer: 'transporte', status: 'resposta_inesperada',
                http: 200, error: data?.message || 'O servidor respondeu num formato inesperado.',
                html_len: 0,
            };
        } else {
            testResult.value = { ...data, layer: 'coleta' };
        }
    } catch (e) {
        // Falha ANTES de chegar no coletor — mostrar o status real, não "não foi
        // possível capturar", que atribuiria o erro ao site errado.
        const status = e?.response?.status ?? null;
        const data = e?.response?.data;
        testResult.value = {
            ok: false,
            layer: 'transporte',
            status: status ? `http_${status}` : 'rede',
            http: status,
            error: status ? transportMessage(status, data) : `Falha de rede: ${e.message}`,
            html_len: 0,
        };
    } finally {
        testing.value = false;
    }
}

function saveConfig() {
    form.post(route('monitoring.scraper.config'), { preserveScroll: true });
}

function runBatch() {
    const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    runForm.progress_token = token;
    runForm.batch_limit = form.batch_limit;
    live.value = { done: 0, total: 0, winning: 0, losing: 0, fail: 0 };
    busy.value = true;
    startPoll(token);

    runForm.post(route('monitoring.scraper.run'), {
        preserveScroll: true,
        onFinish: () => { stopPoll(); busy.value = false; },
        onError: () => { stopPoll(); busy.value = false; },
    });
}

function startPoll(token) {
    stopPoll();
    pollTimer = setInterval(async () => {
        try {
            const r = await fetch(`/monitoring/scraper/progress/${encodeURIComponent(token)}?t=${Date.now()}`,
                { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            live.value = {
                done: d.done || 0, total: d.total || 0,
                winning: d.winning || 0, losing: d.losing || 0, fail: d.fail || 0,
            };
            if (d.status === 'done') { stopPoll(); }
        } catch (e) { /* ignora */ }
    }, 1000);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl p-4 shadow-sm; }
.kpi-l { @apply text-[11px] font-bold uppercase tracking-wide text-slate-400; }
.kpi-v { @apply text-2xl font-extrabold text-slate-900 mt-1 font-mono; }
.btn-primary { @apply bg-blue-500 hover:bg-blue-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2.5 transition; }
.lb { @apply block text-xs font-bold uppercase tracking-wide text-slate-400 mb-1; }
.inp { @apply w-full border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400; }
.dt { @apply text-[10px] font-bold uppercase tracking-wide text-slate-400; }
.dd { @apply text-slate-800 font-semibold; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
