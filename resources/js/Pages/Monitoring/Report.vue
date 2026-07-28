<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-chart-column"></i> Monitoramento de Preços
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Relatório de competitividade</h1>
                <p class="text-slate-500 mt-2 font-medium">Quem está ganhando a Buy Box, por marca e por nível de concorrência.</p>
            </div>

            <!-- Buy Box -->
            <div class="grid grid-cols-1 xl:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-br from-slate-900 to-slate-700 text-white rounded-2xl p-6 shadow-sm xl:col-span-1">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-white/60">Share de Buy Box</div>
                    <div class="text-5xl font-extrabold mt-1 font-mono">{{ buybox.share }}<span class="text-2xl">%</span></div>
                    <div class="text-xs text-white/60 mt-1">de {{ n(buybox.total) }} produtos com dados</div>
                    <div class="mt-4 h-2 bg-white/20 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-400 rounded-full" :style="{ width: buybox.share + '%' }"></div>
                    </div>
                </div>
                <div class="kpi"><div class="kpi-l">Ganhando Buy Box</div><div class="kpi-v text-emerald-600">{{ n(buybox.ganhando) }}</div></div>
                <div class="kpi"><div class="kpi-l">Perdendo Buy Box</div><div class="kpi-v text-red-600">{{ n(buybox.perdendo) }}</div></div>
                <div class="kpi"><div class="kpi-l">Sem informação</div><div class="kpi-v text-slate-400">{{ n(buybox.sem_info) }}</div></div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
                <!-- Competitividade -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Nível de concorrência</h2>
                    <div class="space-y-3">
                        <div v-for="c in competitividade" :key="c.label">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="flex items-center gap-2 text-slate-600">
                                    <span class="w-2.5 h-2.5 rounded-full" :style="{ background: c.color }"></span>{{ c.label }}
                                </span>
                                <span class="font-mono font-bold text-slate-800">{{ n(c.value) }}</span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full" :style="{ width: pctOf(c.value) , background: c.color }"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Produtos por marca -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Produtos por marca</h2>
                    <div v-if="por_marca.length" class="space-y-2 max-h-72 overflow-y-auto pr-1">
                        <div v-for="m in por_marca" :key="m.marca" class="flex items-center gap-3">
                            <span class="text-xs text-slate-600 w-32 truncate shrink-0">{{ m.marca }}</span>
                            <div class="flex-1 h-4 bg-slate-100 rounded overflow-hidden">
                                <div class="h-full bg-blue-400 rounded" :style="{ width: pctOfMarca(m.total) }"></div>
                            </div>
                            <span class="text-xs font-mono font-bold text-slate-700 w-12 text-right">{{ n(m.total) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-slate-400">Sem dados de marca.</p>
                </div>
            </div>

            <!-- Marcas em risco -->
            <div v-if="marcas_risco.length" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Marcas com mais produtos perdendo</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                <th class="py-2 pr-4 font-bold">Marca</th>
                                <th class="py-2 px-4 font-bold text-right">Com mercado</th>
                                <th class="py-2 px-4 font-bold text-right">Perdendo</th>
                                <th class="py-2 px-4 font-bold text-right">Ganhando BB</th>
                                <th class="py-2 pl-4 font-bold text-right">Gap médio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in marcas_risco" :key="m.marca" class="border-b border-slate-50 hover:bg-slate-50/60">
                                <td class="py-2.5 pr-4 font-semibold text-slate-700">{{ m.marca }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-600">{{ n(m.total) }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-red-600">{{ n(m.perdendo) }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-emerald-600">{{ n(m.ganhando) }}</td>
                                <td class="py-2.5 pl-4 text-right font-mono" :class="m.gap > 0 ? 'text-red-600' : 'text-emerald-600'">{{ m.gap }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Lista Buy Box -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center gap-2">
                    <Link v-for="s in situacoes" :key="s.v" :href="route('monitoring.report', { situacao: s.v })"
                        :class="['px-3 py-1.5 rounded-lg text-xs font-bold border transition',
                            situacao === s.v ? s.on : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50']">
                        {{ s.l }}
                    </Link>
                    <a :href="route('monitoring.report.export', { situacao })" class="ml-auto text-xs font-semibold text-blue-600 hover:text-blue-700">
                        <i class="fa-solid fa-download mr-1"></i>Exportar CSV
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/50">
                                <th class="py-3 px-4 font-bold">Produto</th>
                                <th class="py-3 px-4 font-bold text-right">Meu preço</th>
                                <th class="py-3 px-4 font-bold text-right">Buy Box</th>
                                <th class="py-3 px-4 font-bold text-right">Gap</th>
                                <th class="py-3 px-4 font-bold">Quem ganha</th>
                                <th class="py-3 px-4 font-bold text-right">Ofertas</th>
                                <th class="py-3 px-4 font-bold text-right">Anúncio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in lista" :key="p.id" class="border-b border-slate-50 hover:bg-slate-50/60">
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ p.titulo }}</div>
                                    <div class="text-xs text-slate-400 font-mono">{{ p.netshoes_sku || p.sku || '—' }}<span v-if="p.marca"> · {{ p.marca }}</span></div>
                                </td>
                                <td class="py-3 px-4 text-right font-mono">{{ money(p.preco) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-slate-600">{{ p.market_price ? money(p.market_price) : '—' }}</td>
                                <td class="py-3 px-4 text-right font-mono" :class="p.gap == null ? 'text-slate-300' : (p.gap > 0 ? 'text-red-600' : 'text-emerald-600')">
                                    {{ p.gap == null ? '—' : p.gap + '%' }}
                                </td>
                                <td class="py-3 px-4">
                                    <span v-if="p.winner === true" class="text-[11px] font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">Nós 🏆</span>
                                    <span v-else class="text-xs text-slate-600">{{ p.seller || '—' }}</span>
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-slate-500">{{ p.ofertas ?? '—' }}</td>
                                <td class="py-3 px-4 text-right">
                                    <a v-if="p.url" :href="p.url" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-700" title="Abrir anúncio">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                            </tr>
                            <tr v-if="!lista.length">
                                <td colspan="7" class="py-10 text-center text-slate-400">
                                    Nenhum produto nesta situação. Rode a
                                    <Link :href="route('monitoring.scraper')" class="text-blue-600 font-semibold">coleta de Buy Box</Link>.
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
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    por_marca: { type: Array, default: () => [] },
    marcas_risco: { type: Array, default: () => [] },
    competitividade: { type: Array, default: () => [] },
    buybox: { type: Object, default: () => ({ ganhando: 0, perdendo: 0, sem_info: 0, total: 0, share: 0 }) },
    lista: { type: Array, default: () => [] },
    situacao: { type: String, default: 'perdendo' },
    has_data: { type: Boolean, default: false },
});

const situacoes = [
    { v: 'perdendo', l: 'Perdendo Buy Box', on: 'bg-red-500 text-white border-red-500' },
    { v: 'ganhando', l: 'Ganhando Buy Box', on: 'bg-emerald-500 text-white border-emerald-500' },
    { v: 'sem_info', l: 'Sem informação', on: 'bg-slate-400 text-white border-slate-400' },
];

const totalComp = computed(() => props.competitividade.reduce((s, c) => s + (c.value || 0), 0) || 1);
const maxMarca = computed(() => Math.max(1, ...props.por_marca.map(m => m.total || 0)));
function pctOf(v) { return Math.round((v || 0) / totalComp.value * 100) + '%'; }
function pctOfMarca(v) { return Math.round((v || 0) / maxMarca.value * 100) + '%'; }
function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl p-4 shadow-sm; }
.kpi-l { @apply text-[11px] font-bold uppercase tracking-wide text-slate-400; }
.kpi-v { @apply text-2xl font-extrabold text-slate-900 mt-1 font-mono; }
</style>
