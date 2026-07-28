<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Monitoramento de Preços
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Otimizar preços</h1>
                <p class="text-slate-500 mt-2 font-medium">Onde um ajuste pequeno recupera a venda — sem vender no prejuízo.</p>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
                <div class="kpi"><div class="kpi-l">Oportunidades</div><div class="kpi-v text-blue-600">{{ n(summary.oportunidades) }}</div></div>
                <div class="kpi"><div class="kpi-l">Ajuste fácil (≤5%)</div><div class="kpi-v text-emerald-600">{{ n(summary.faceis) }}</div></div>
                <div class="kpi"><div class="kpi-l">Receita em risco</div><div class="kpi-v text-red-600 !text-xl">{{ money(summary.receita_risco) }}</div></div>
                <div class="kpi"><div class="kpi-l">Ajuste total necessário</div><div class="kpi-v !text-xl">{{ money(summary.ajuste_total) }}</div></div>
                <div class="kpi"><div class="kpi-l">Recuperados (30d)</div><div class="kpi-v text-emerald-600">{{ n(summary.recuperados_30d) }}</div></div>
            </div>

            <!-- Abas -->
            <div class="flex gap-2 mb-5">
                <button @click="tab = 'oportunidades'" :class="['tab', tab === 'oportunidades' ? 'tab-on' : 'tab-off']">
                    Oportunidades <span class="ml-1 opacity-60">{{ n(oportunidades.length) }}</span>
                </button>
                <button @click="tab = 'otimizacoes'" :class="['tab', tab === 'otimizacoes' ? 'tab-on' : 'tab-off']">
                    Otimizações recentes <span class="ml-1 opacity-60">{{ n(otimizacoes.length) }}</span>
                </button>
            </div>

            <!-- Oportunidades -->
            <div v-if="tab === 'oportunidades'" class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3 text-sm">
                    <span class="text-slate-500">Margem mínima sobre o custo:</span>
                    <input v-model.number="minMargin" @change="reload" type="number" min="0" max="100" step="1"
                        class="w-20 border border-slate-200 rounded-lg px-2 py-1 text-sm font-mono outline-none focus:border-blue-400">
                    <span class="text-slate-400">%</span>
                    <span class="ml-auto text-xs text-slate-400">Sugerido = logo abaixo do mercado, terminando em ,90</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/50">
                                <th class="py-3 px-4 font-bold">Produto</th>
                                <th class="py-3 px-4 font-bold text-right">Meu preço</th>
                                <th class="py-3 px-4 font-bold text-right">Mercado</th>
                                <th class="py-3 px-4 font-bold text-right">Sugerido</th>
                                <th class="py-3 px-4 font-bold text-right">Redução</th>
                                <th class="py-3 px-4 font-bold text-right">Piso</th>
                                <th class="py-3 px-4 font-bold">Concorrente</th>
                                <th class="py-3 px-4 font-bold text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in oportunidades" :key="o.id" class="border-b border-slate-50 hover:bg-slate-50/60">
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ o.titulo }}</div>
                                    <div class="text-xs text-slate-400 font-mono">{{ o.sku || '—' }}<span v-if="o.marca"> · {{ o.marca }}</span></div>
                                </td>
                                <td class="py-3 px-4 text-right font-mono">{{ money(o.preco) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-slate-600">{{ money(o.market_price) }}</td>
                                <td class="py-3 px-4 text-right font-mono font-bold" :class="o.viavel === false ? 'text-red-600' : 'text-emerald-600'">{{ money(o.sugerido) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-red-500">-{{ money(o.reducao) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-xs text-slate-400">{{ o.piso ? money(o.piso) : '—' }}</td>
                                <td class="py-3 px-4">
                                    <a v-if="o.url" :href="o.url" target="_blank" rel="noopener" class="text-blue-600 hover:underline text-xs">
                                        {{ o.seller || 'ver anúncio' }} <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                    </a>
                                    <span v-else class="text-xs text-slate-400">{{ o.seller || '—' }}</span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <span v-if="o.viavel === false" class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-1 rounded-full">abaixo do piso</span>
                                    <button v-else @click="apply(o)" class="text-xs font-bold text-blue-600 hover:text-blue-700">Aplicar</button>
                                </td>
                            </tr>
                            <tr v-if="!oportunidades.length">
                                <td colspan="8" class="py-10 text-center text-slate-400">
                                    Nenhuma oportunidade — ou ainda não há preço de mercado coletado.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Otimizações recentes -->
            <div v-else class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/50">
                                <th class="py-3 px-4 font-bold">Produto</th>
                                <th class="py-3 px-4 font-bold text-right">De</th>
                                <th class="py-3 px-4 font-bold text-right">Para</th>
                                <th class="py-3 px-4 font-bold text-right">Redução</th>
                                <th class="py-3 px-4 font-bold">Resultado</th>
                                <th class="py-3 px-4 font-bold">Quando</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in otimizacoes" :key="o.id" class="border-b border-slate-50 hover:bg-slate-50/60">
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ o.titulo }}</div>
                                    <div class="text-xs text-slate-400 font-mono">{{ o.sku || '—' }}</div>
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-slate-400 line-through">{{ money(o.de) }}</td>
                                <td class="py-3 px-4 text-right font-mono font-bold">{{ money(o.para) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-red-500">-{{ money(o.reducao) }}</td>
                                <td class="py-3 px-4">
                                    <span v-if="o.ganhou" class="text-[11px] font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">ganhou Buy Box</span>
                                    <span v-else class="text-[11px] font-bold bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full">sem Buy Box</span>
                                </td>
                                <td class="py-3 px-4 text-xs text-slate-500">{{ fmtDate(o.quando) }}</td>
                            </tr>
                            <tr v-if="!otimizacoes.length">
                                <td colspan="6" class="py-10 text-center text-slate-400">
                                    Ainda não há histórico. Ele é criado a cada coleta de Buy Box.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    oportunidades: { type: Array, default: () => [] },
    otimizacoes: { type: Array, default: () => [] },
    min_margin: { type: Number, default: 10 },
});

const tab = ref('oportunidades');
const minMargin = ref(props.min_margin);

function reload() {
    router.get(route('monitoring.optimize'), { min_margin: minMargin.value },
        { preserveScroll: true, preserveState: true, replace: true });
}

function apply(o) {
    if (!confirm(`Aplicar ${money(o.sugerido)} em "${o.titulo}"?\n\nIsso atualiza o preço no sistema — replique no canal depois.`)) return;
    router.post(route('monitoring.optimize.apply', { product: o.id }), { price: o.sugerido }, { preserveScroll: true });
}

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) { if (!d) return '—'; const s = String(d).slice(0, 10).split('-'); return `${s[2]}/${s[1]}/${s[0]}`; }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl p-4 shadow-sm; }
.kpi-l { @apply text-[11px] font-bold uppercase tracking-wide text-slate-400; }
.kpi-v { @apply text-2xl font-extrabold text-slate-900 mt-1 font-mono; }
.tab { @apply px-4 py-2 rounded-xl text-sm font-bold border transition; }
.tab-on { @apply bg-slate-900 text-white border-slate-900; }
.tab-off { @apply bg-white text-slate-500 border-slate-200 hover:bg-slate-50; }
</style>
