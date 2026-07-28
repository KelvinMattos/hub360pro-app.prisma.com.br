<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <div class="mb-6">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-gauge-high"></i> Monitoramento de Preços
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Repricing automático</h1>
                <p class="text-slate-500 mt-2 font-medium">Ajuste de preço com travas de segurança, simulação e rollback.</p>
            </div>

            <!-- Estado mestre -->
            <div :class="['rounded-2xl p-5 mb-6 border-2', config.repricing_enabled ? 'bg-amber-50 border-amber-300' : 'bg-slate-100 border-slate-300']">
                <div class="flex flex-wrap items-center gap-3">
                    <i :class="config.repricing_enabled ? 'fa-solid fa-triangle-exclamation text-amber-500' : 'fa-solid fa-lock text-slate-400'" class="text-xl"></i>
                    <div class="flex-1 min-w-64">
                        <div class="font-bold text-slate-800">
                            {{ config.repricing_enabled ? 'Repricing ATIVADO' : 'Repricing DESATIVADO' }}
                            <span v-if="config.repricing_enabled && config.dry_run" class="ml-2 text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">modo simulação</span>
                        </div>
                        <div class="text-sm text-slate-600">
                            {{ config.repricing_enabled
                                ? 'As travas continuam valendo. Nada é aplicado sem você confirmar explicitamente.'
                                : 'Nenhum preço será alterado. Ative apenas quando a fonte de preço de mercado estiver confiável.' }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPIs do plano -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="kpi"><div class="kpi-l">Avaliados</div><div class="kpi-v">{{ n(stats.evaluated) }}</div></div>
                <div class="kpi"><div class="kpi-l">Seriam alterados</div><div class="kpi-v text-emerald-600">{{ n(stats.changed) }}</div></div>
                <div class="kpi"><div class="kpi-l">Barrados pelas travas</div><div class="kpi-v text-amber-600">{{ n(stats.skipped) }}</div></div>
            </div>

            <!-- Configuração -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Travas de segurança</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="lb">Margem mínima global (%)</label><input v-model.number="form.min_margin" type="number" step="0.5" class="inp"></div>
                    <div><label class="lb">Variação máxima automática (%)</label><input v-model.number="form.max_change_pct" type="number" step="0.5" class="inp"></div>
                    <div><label class="lb">Idade máxima do preço (h)</label><input v-model.number="form.max_age_hours" type="number" class="inp"></div>
                    <div><label class="lb">Ficar abaixo do mercado (R$)</label><input v-model.number="form.undercut" type="number" step="0.01" class="inp"></div>
                    <div class="flex items-end"><label class="flex items-center gap-2 text-sm text-slate-700 pb-2">
                        <input type="checkbox" v-model="form.only_losing" class="accent-blue-500"> Só quem está perdendo
                    </label></div>
                    <div class="flex items-end"><label class="flex items-center gap-2 text-sm text-slate-700 pb-2">
                        <input type="checkbox" v-model="form.dry_run" class="accent-blue-500"> Modo simulação (dry-run)
                    </label></div>
                    <div class="md:col-span-3 border-t border-slate-100 pt-4">
                        <label class="flex items-center gap-2 text-sm font-bold text-slate-800">
                            <input type="checkbox" v-model="form.repricing_enabled" class="accent-amber-500">
                            Ativar repricing automático
                        </label>
                    </div>
                </div>
                <div class="mt-4"><button @click="save" class="btn-ghost"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar</button></div>
            </div>

            <!-- Margem por marca -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">Margem mínima por marca</h2>
                <p class="text-sm text-slate-500 mb-4">Sobrepõe a margem global. O piso é sempre <b>custo × (1 + margem)</b>.</p>
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="flex-1 min-w-48">
                        <label class="lb">Marca</label>
                        <input v-model="brandForm.brand" list="marcas-list" class="inp">
                        <datalist id="marcas-list"><option v-for="m in marcas" :key="m" :value="m"></option></datalist>
                    </div>
                    <div class="w-32"><label class="lb">Margem (%)</label><input v-model.number="brandForm.min_margin_pct" type="number" step="0.5" class="inp"></div>
                    <button @click="saveBrand" class="btn-ghost">Salvar marca</button>
                </div>
                <div v-if="Object.keys(brand_margins).length" class="flex flex-wrap gap-2 mt-4">
                    <span v-for="(v, k) in brand_margins" :key="k" class="text-xs bg-slate-100 text-slate-700 px-2.5 py-1 rounded-lg font-mono">{{ k }}: {{ v }}%</span>
                </div>
            </div>

            <!-- Plano -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mb-6">
                <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400">Plano ({{ n(plan.length) }} itens)</h2>
                    <div class="ml-auto flex gap-2">
                        <button @click="apply(false)" class="btn-ghost text-sm"><i class="fa-solid fa-flask mr-2"></i>Simular</button>
                        <button @click="apply(true)" :disabled="!config.repricing_enabled" class="btn-danger text-sm disabled:opacity-40 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-bolt mr-2"></i>Aplicar de verdade
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto max-h-[32rem]">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                <th class="py-3 px-4 font-bold">Produto</th>
                                <th class="py-3 px-4 font-bold text-right">Atual</th>
                                <th class="py-3 px-4 font-bold text-right">Mercado</th>
                                <th class="py-3 px-4 font-bold text-right">Novo</th>
                                <th class="py-3 px-4 font-bold text-right">Var.</th>
                                <th class="py-3 px-4 font-bold text-right">Piso</th>
                                <th class="py-3 px-4 font-bold">Situação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in plan" :key="p.id" class="border-b border-slate-50" :class="p.aplicavel ? '' : 'bg-slate-50/40'">
                                <td class="py-2.5 px-4">
                                    <div class="font-semibold text-slate-800 line-clamp-1 max-w-xs">{{ p.titulo }}</div>
                                    <div class="text-xs text-slate-400 font-mono">{{ p.sku || '—' }}<span v-if="p.marca"> · {{ p.marca }}</span></div>
                                </td>
                                <td class="py-2.5 px-4 text-right font-mono">{{ money(p.preco) }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-600">{{ money(p.market_price) }}</td>
                                <td class="py-2.5 px-4 text-right font-mono font-bold" :class="p.aplicavel ? 'text-emerald-600' : 'text-slate-400'">{{ money(p.novo) }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-xs">{{ p.variacao }}%</td>
                                <td class="py-2.5 px-4 text-right font-mono text-xs text-slate-400">{{ p.piso ? money(p.piso) : '—' }}</td>
                                <td class="py-2.5 px-4">
                                    <span v-if="p.aplicavel" class="text-[11px] font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">aplicável</span>
                                    <span v-else class="text-[11px] text-amber-700" :title="p.bloqueio">{{ p.bloqueio }}</span>
                                </td>
                            </tr>
                            <tr v-if="!plan.length"><td colspan="7" class="py-10 text-center text-slate-400">
                                Nada a reprecificar. É preciso ter preço de mercado recente e custo cadastrado.
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Auditoria -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Histórico e rollback</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100">
                            <th class="py-2 pr-4 font-bold">Lote</th><th class="py-2 px-4 font-bold">Quando</th>
                            <th class="py-2 px-4 font-bold">Tipo</th><th class="py-2 px-4 font-bold text-right">Alterados</th>
                            <th class="py-2 px-4 font-bold text-right">Barrados</th><th class="py-2 pl-4 font-bold text-right">Ação</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="b in batches" :key="b.id" class="border-b border-slate-50">
                                <td class="py-2.5 pr-4 font-mono text-slate-500">#{{ b.id }}</td>
                                <td class="py-2.5 px-4 text-xs text-slate-500">{{ fmtDate(b.created_at) }}</td>
                                <td class="py-2.5 px-4">
                                    <span v-if="b.dry_run" class="text-[11px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-bold">simulação</span>
                                    <span v-else class="text-[11px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-bold">aplicado</span>
                                </td>
                                <td class="py-2.5 px-4 text-right font-mono">{{ n(b.changed) }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-400">{{ n(b.skipped) }}</td>
                                <td class="py-2.5 pl-4 text-right">
                                    <span v-if="b.rolled_back" class="text-xs text-slate-400">desfeito</span>
                                    <button v-else-if="!b.dry_run" @click="rollback(b)" class="text-xs font-bold text-red-600 hover:text-red-700">Desfazer</button>
                                    <span v-else class="text-xs text-slate-300">—</span>
                                </td>
                            </tr>
                            <tr v-if="!batches.length"><td colspan="6" class="py-8 text-center text-slate-400">Nenhuma execução ainda.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    plan: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    batches: { type: Array, default: () => [] },
    brand_margins: { type: Object, default: () => ({}) },
    marcas: { type: Array, default: () => [] },
});

const form = useForm({
    repricing_enabled: !!props.config.repricing_enabled,
    dry_run: props.config.dry_run !== false,
    min_margin: props.config.min_margin ?? 10,
    max_change_pct: props.config.max_change_pct ?? 15,
    max_age_hours: props.config.max_age_hours ?? 24,
    undercut: props.config.undercut ?? 0.10,
    only_losing: props.config.only_losing !== false,
});
const brandForm = useForm({ brand: '', min_margin_pct: 10 });

function save() { form.post(route('monitoring.repricing.config'), { preserveScroll: true }); }
function saveBrand() {
    if (!brandForm.brand) return;
    brandForm.post(route('monitoring.repricing.brand'), { preserveScroll: true, onSuccess: () => brandForm.reset('brand') });
}
function apply(real) {
    if (real && !confirm(`APLICAR DE VERDADE?\n\n${props.stats.changed} preços serão alterados no sistema.\nVocê pode desfazer pelo histórico.`)) return;
    router.post(route('monitoring.repricing.apply'), { confirm_real: real }, { preserveScroll: true });
}
function rollback(b) {
    if (!confirm(`Desfazer o lote #${b.id}? Os preços anteriores serão restaurados.`)) return;
    router.post(route('monitoring.repricing.rollback', { batch: b.id }), {}, { preserveScroll: true });
}

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) { if (!d) return '—'; const s = String(d).replace('T', ' ').slice(0, 16); const [dt, tm] = s.split(' '); const p = dt.split('-'); return `${p[2]}/${p[1]} ${tm || ''}`; }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl p-4 shadow-sm; }
.kpi-l { @apply text-[11px] font-bold uppercase tracking-wide text-slate-400; }
.kpi-v { @apply text-2xl font-extrabold text-slate-900 mt-1 font-mono; }
.lb { @apply block text-xs font-bold uppercase tracking-wide text-slate-400 mb-1; }
.inp { @apply w-full border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 transition; }
.btn-danger { @apply bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg px-4 py-2 transition shadow-sm; }
</style>
