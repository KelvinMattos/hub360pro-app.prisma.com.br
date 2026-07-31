<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-7xl mx-auto">
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-bullseye"></i> Marketing
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Oportunidades & Campanhas</h1>
                    <p class="text-slate-500 mt-2 font-medium max-w-2xl">
                        O sistema já cruzou vendas, estoque e lançamentos — aqui estão os produtos que mais valem uma campanha agora.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('marketing.campaigns.index')" class="btn-secondary"><i class="fa-solid fa-table-columns mr-2"></i>Kanban</Link>
                    <Link :href="route('marketing.calendar.index')" class="btn-secondary"><i class="fa-solid fa-calendar-days mr-2"></i>Calendário</Link>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Oportunidades -->
                <div class="lg:col-span-3 space-y-6">
                    <OpportunityColumn
                        title="Lançamentos" icon="fa-solid fa-rocket" tone="blue"
                        empty-text="Nenhum lançamento recente (últimos 60 dias)."
                        :items="opportunities.lancamento" opportunity="lancamento"
                        @create-campaign="createFromOpportunity" />

                    <OpportunityColumn
                        title="Mais Vendidos (Curva A)" icon="fa-solid fa-fire" tone="emerald"
                        empty-text="Sem dado de curva ABC ainda — rode o motor de Reposição Inteligente."
                        :items="opportunities.mais_vendido" opportunity="mais_vendido"
                        @create-campaign="createFromOpportunity" />

                    <OpportunityColumn
                        title="Precisam Liquidar" icon="fa-solid fa-tag" tone="red"
                        empty-text="Nenhum produto parado ou em excesso encontrado."
                        :items="opportunities.liquidar" opportunity="liquidar"
                        @create-campaign="createFromOpportunity" />
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Kanban de Campanhas</h3>
                        <div class="space-y-2">
                            <div v-for="stage in stageList" :key="stage.key" class="flex items-center justify-between text-sm">
                                <span class="text-slate-600">{{ stage.label }}</span>
                                <span class="font-mono font-bold text-slate-800">{{ stageCounts[stage.key] ?? 0 }}</span>
                            </div>
                        </div>
                        <Link :href="route('marketing.campaigns.index')" class="block text-center mt-4 text-xs font-bold text-blue-600 hover:underline">Ver Kanban completo</Link>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">Próximas Datas</h3>
                        </div>
                        <div v-if="!upcomingDates.length" class="text-xs text-slate-400 py-4 text-center">Nenhuma data nos próximos 120 dias.</div>
                        <div v-for="d in upcomingDates" :key="d.id" class="flex items-center justify-between py-2 border-b border-slate-50 last:border-0">
                            <div>
                                <p class="text-sm font-semibold text-slate-700">{{ d.title }}</p>
                                <p class="text-[10px] text-slate-400 uppercase">{{ formatDate(d.date) }}</p>
                            </div>
                            <span class="text-[10px] font-black px-2 py-1 rounded-full" :class="d.days_until <= 14 ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500'">
                                {{ d.days_until === 0 ? 'hoje' : `${d.days_until}d` }}
                            </span>
                        </div>
                        <Link :href="route('marketing.calendar.index')" class="block text-center mt-4 text-xs font-bold text-blue-600 hover:underline">Ver calendário completo</Link>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">Minhas Tarefas</h3>
                            <span v-if="overdueCount > 0" class="text-[10px] font-black bg-red-50 text-red-600 px-2 py-1 rounded-full">{{ overdueCount }} atrasada(s)</span>
                        </div>
                        <div v-if="!myTasks.length" class="text-xs text-slate-400 py-4 text-center">Nenhuma tarefa pendente.</div>
                        <div v-for="t in myTasks" :key="t.id" class="py-2 border-b border-slate-50 last:border-0">
                            <p class="text-sm font-semibold text-slate-700 truncate">{{ t.title }}</p>
                            <p class="text-[10px] text-slate-400">{{ t.due_date ? formatDate(t.due_date) : 'sem prazo' }} · {{ priorityLabel(t.priority) }}</p>
                        </div>
                        <Link :href="route('marketing.tasks.index')" class="block text-center mt-4 text-xs font-bold text-blue-600 hover:underline">Ver todas as tarefas</Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import OpportunityColumn from '@/Components/Marketing/OpportunityColumn.vue';

const props = defineProps({
    opportunities: { type: Object, default: () => ({ lancamento: [], mais_vendido: [], liquidar: [] }) },
    upcomingDates: { type: Array, default: () => [] },
    stageCounts: { type: Object, default: () => ({}) },
    myTasks: { type: Array, default: () => [] },
    overdueCount: { type: Number, default: 0 },
});

const stageList = [
    { key: 'ideia', label: 'Ideia' }, { key: 'planejamento', label: 'Planejamento' },
    { key: 'execucao', label: 'Em execução' }, { key: 'revisao', label: 'Revisão' },
    { key: 'concluido', label: 'Concluído' },
];

const OPPORTUNITY_NAMES = { lancamento: 'Lançamentos', mais_vendido: 'Mais Vendidos', liquidar: 'Liquidação' };

function createFromOpportunity({ opportunity, productIds }) {
    if (!productIds.length) return;
    router.post(route('marketing.campaigns.from-opportunity'), {
        opportunity,
        name: `${OPPORTUNITY_NAMES[opportunity]} — ${new Date().toLocaleDateString('pt-BR')}`,
        product_ids: productIds,
    }, { preserveScroll: true });
}

function formatDate(v) {
    if (!v) return '—';
    return new Date(v + 'T00:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}
function priorityLabel(p) { return { baixa: 'Baixa', media: 'Média', alta: 'Alta' }[p] || p; }
</script>

<style scoped>
.btn-secondary { @apply text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 flex items-center; }
</style>
