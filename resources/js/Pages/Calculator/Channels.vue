<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1400px] mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-calculator text-cyan-500"></i>
                    Calculadora de <span class="bg-gradient-to-r from-cyan-500 to-blue-600 bg-clip-text text-transparent">Canais</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Informe o custo do produto e veja o ponto de equilíbrio, o preço-meta de lucro e a margem real em <b>todos os canais</b> — com as comissões e taxas fixas de cada um.
                </p>
            </div>

            <div v-if="errorMessage" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ errorMessage }}
            </div>

            <!-- Entradas -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-5">
                    <div>
                        <label class="lbl">Custo do produto (R$)</label>
                        <input v-model.number="custo" type="number" step="0.01" class="inp inp-lg">
                    </div>
                    <div>
                        <label class="lbl">Preço praticado (R$) <span class="text-slate-400">opcional</span></label>
                        <input v-model.number="preco" type="number" step="0.01" placeholder="ver margem" class="inp inp-lg">
                    </div>
                    <div>
                        <label class="lbl">Imposto (%)</label>
                        <input v-model.number="imposto" type="number" step="0.01" class="inp">
                    </div>
                    <div>
                        <label class="lbl">MC (%)</label>
                        <input v-model.number="mc" type="number" step="0.01" class="inp">
                    </div>
                    <div>
                        <label class="lbl">Markup meta lucro (%)</label>
                        <input v-model.number="markup" type="number" step="0.001" class="inp">
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-4">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    Ponto de equilíbrio = custo ÷ (1 − (imposto + MC + comissão do canal)). PV Meta aplica o markup sobre o equilíbrio.
                    Margem é o resultado líquido no preço praticado. ML e Shopee somam a taxa fixa por faixa de preço.
                </p>
            </div>

            <!-- Resultado por canal -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400">Retorno por canal</h2>
                    <div v-if="custo > 0" class="text-xs text-slate-400">Custo base: <b class="text-slate-600">{{ money(custo) }}</b></div>
                </div>

                <div v-if="!custo || custo <= 0" class="text-center text-slate-400 py-12">
                    <i class="fa-solid fa-arrow-up text-2xl mb-2"></i>
                    <p>Informe o custo do produto para calcular o retorno em cada canal.</p>
                </div>

                <div v-else-if="loading && !rows.length" class="text-center text-slate-400 py-12">
                    <i class="fa-solid fa-circle-notch fa-spin text-2xl mb-2"></i>
                    <p>Calculando…</p>
                </div>

                <div v-else>
                    <div v-if="!preco || preco <= 0" class="bg-amber-50 border border-amber-200 text-amber-700 text-xs px-4 py-2.5 rounded-xl mb-4">
                        <i class="fa-solid fa-circle-info mr-1.5"></i>
                        <b>PV Promo, Margem, % Margem e Status</b> ficam vazios até você informar o <b>Preço praticado</b> ali em cima — sem ele não tem como saber se aquele preço dá lucro ou prejuízo em cada canal.
                    </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-200">
                                <th class="py-2 pr-3 font-semibold">Canal</th>
                                <th class="py-2 px-3 font-semibold">Comissão %</th>
                                <th class="py-2 px-3 font-semibold text-right">Ponto Equilíbrio</th>
                                <th class="py-2 px-3 font-semibold text-right">PV Meta (lucro)</th>
                                <th class="py-2 px-3 font-semibold text-right">PV Promo</th>
                                <th class="py-2 px-3 font-semibold text-right">Margem @ preço</th>
                                <th class="py-2 px-3 font-semibold text-right">% Margem</th>
                                <th class="py-2 px-3 font-semibold text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50/60">
                                <td class="py-2 pr-3 font-medium text-slate-700 whitespace-nowrap">{{ r.label }}</td>
                                <td class="py-2 px-3"><input v-model.number="r.comissao" type="number" step="0.01" class="tbl-input"></td>
                                <td class="py-2 px-3 text-right font-mono">{{ money(r.equilibrio) }}</td>
                                <td class="py-2 px-3 text-right font-mono text-slate-700">{{ money(r.meta) }}</td>
                                <td class="py-2 px-3 text-right font-mono">{{ money(r.promo) }}</td>
                                <td class="py-2 px-3 text-right font-mono font-semibold" :class="r.margem == null ? 'text-slate-300' : r.margem < 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(r.margem) }}</td>
                                <td class="py-2 px-3 text-right font-mono" :class="marginClass(r.margemPct)">{{ r.margemPct == null ? '—' : r.margemPct.toFixed(1) + '%' }}</td>
                                <td class="py-2 px-3 text-center">
                                    <span :class="['pill', r.statusClass]">{{ r.status }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </div>
                <p class="text-xs text-slate-400 mt-4">
                    A comissão de cada canal vem da configuração padrão (editável aqui para simular). Quer travar esses valores por empresa? Dá para persistir depois.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, watch, onBeforeUnmount } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

// Todo o cálculo (equilíbrio, meta, promo, margem) vem do backend
// (App\Services\PricingEngine) — este componente não reimplementa nenhuma
// fórmula, só envia os inputs (com debounce) e renderiza o resultado.

const props = defineProps({
    defaults: { type: Object, required: true },
});

const custo = ref(null);
const preco = ref(null);
const imposto = ref(props.defaults.imposto);
const mc = ref(props.defaults.mc);
const markup = ref(props.defaults.channels[0]?.markup ?? 23.433);

const channels = reactive(props.defaults.channels.map(c => ({
    id: c.id, label: c.label, comissao: c.comissao, temFaixa: c.temFaixa,
    descAtual: c.descAtual, descEquil: c.descEquil,
})));

const rows = ref([]);
const loading = ref(false);
const errorMessage = ref(null);
let debounceTimer = null;
let requestSeq = 0;

function scheduleCompute() {
    clearTimeout(debounceTimer);
    if (!custo.value || custo.value <= 0) {
        rows.value = [];
        return;
    }
    loading.value = true;
    debounceTimer = setTimeout(runCompute, 300);
}

async function runCompute() {
    const seq = ++requestSeq;
    try {
        const { data } = await axios.post(route('calculator.compute'), {
            custo: custo.value,
            preco: preco.value,
            imposto: imposto.value,
            mc: mc.value,
            markup: markup.value,
            channels: channels.map(c => ({ ...c })),
        }, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        if (seq === requestSeq) {
            rows.value = data.rows;
            errorMessage.value = null;
        }
    } catch (e) {
        // Nunca falhar em silêncio (CLAUDE.md §2.1): antes só ia pro console —
        // a tela ficava com a tabela vazia sem explicar por quê, indistinguível
        // de "não funciona". Mostra o status real (camada de transporte vs.
        // validação), nunca um dado inventado.
        if (seq === requestSeq) {
            const status = e.response?.status;
            if (status === 419) {
                errorMessage.value = 'Sessão expirada — recarregue a página e tente novamente.';
            } else if (status === 422) {
                const details = Object.values(e.response?.data?.errors ?? {}).flat().join(' ');
                errorMessage.value = 'Dados inválidos' + (details ? `: ${details}` : '.');
            } else if (status) {
                errorMessage.value = `Falha ao calcular (HTTP ${status}). Tente novamente.`;
            } else {
                errorMessage.value = 'Falha de conexão ao calcular — verifique sua internet e tente novamente.';
            }
        }
        console.error('Falha ao calcular retorno por canal:', e);
    } finally {
        if (seq === requestSeq) loading.value = false;
    }
}

watch([custo, preco, imposto, mc, markup, () => channels.map(c => c.comissao)], scheduleCompute, { deep: true });
onBeforeUnmount(() => clearTimeout(debounceTimer));

function marginClass(v) {
    if (v == null) return 'text-slate-300';
    if (v < 0) return 'text-red-600';
    if (v < 15) return 'text-amber-600';
    return 'text-emerald-600';
}
function money(v) { return v == null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<style scoped>
.lbl { @apply block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5; }
.inp { @apply w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-2 font-mono outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-100 transition; }
.inp-lg { @apply text-lg font-bold; }
.tbl-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-2 py-1 text-sm w-20 font-mono outline-none focus:border-cyan-400 transition; }
.pill { @apply text-[10px] font-bold px-2 py-0.5 rounded-full; }
.pill-green { @apply bg-emerald-100 text-emerald-700; }
.pill-red { @apply bg-red-100 text-red-700; }
.pill-amber { @apply bg-amber-100 text-amber-700; }
.pill-idle { @apply bg-slate-100 text-slate-400; }
</style>
