<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1600px] mx-auto">
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-bullseye"></i> Marketing
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Campanhas</h1>
                </div>
                <button @click="showForm = !showForm" class="text-xs font-bold px-4 py-2.5 rounded-lg bg-slate-900 text-white hover:bg-slate-700">
                    <i class="fa-solid fa-plus mr-1"></i> Nova campanha
                </button>
            </div>

            <div v-if="showForm" class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">
                <form @submit.prevent="createCampaign" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <Field label="Nome" class="md:col-span-2"><input v-model="form.name" type="text" class="inp" required></Field>
                    <Field label="Tipo">
                        <select v-model="form.type" class="inp">
                            <option v-for="t in types" :key="t" :value="t">{{ typeLabel(t) }}</option>
                        </select>
                    </Field>
                    <Field label="Responsável">
                        <select v-model="form.owner_id" class="inp">
                            <option :value="null">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </Field>
                    <Field label="Início"><input v-model="form.start_date" type="date" class="inp"></Field>
                    <Field label="Fim"><input v-model="form.end_date" type="date" class="inp"></Field>
                    <div class="md:col-span-5">
                        <button type="submit" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Criar</button>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div v-for="stage in stages" :key="stage"
                    class="bg-slate-50 rounded-2xl p-3 min-h-[200px]"
                    @dragover.prevent @drop="onDrop(stage)">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-500 mb-3 px-2">
                        {{ stageLabel(stage) }} <span class="text-slate-400">({{ byStage(stage).length }})</span>
                    </h3>
                    <div class="space-y-3">
                        <div v-for="c in byStage(stage)" :key="c.id"
                            draggable="true" @dragstart="onDragStart(c.id)"
                            class="bg-white border border-slate-200 rounded-xl p-3 shadow-sm cursor-grab hover:shadow-md transition-shadow">
                            <Link :href="route('marketing.campaigns.show', c.id)" class="block">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span :class="['badge', typeBadge(c.type)]">{{ typeLabel(c.type) }}</span>
                                    <span v-if="c.source_opportunity" class="text-[9px] text-slate-300"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                                </div>
                                <p class="font-bold text-sm text-slate-800 mb-1 line-clamp-2">{{ c.name }}</p>
                                <p v-if="c.owner_name" class="text-[10px] text-slate-400 mb-2"><i class="fa-solid fa-user mr-1"></i>{{ c.owner_name }}</p>
                                <div class="flex items-center justify-between text-[10px] text-slate-400">
                                    <span><i class="fa-solid fa-box mr-1"></i>{{ c.product_count }} produto(s)</span>
                                    <span v-if="c.task_total"><i class="fa-solid fa-check mr-1"></i>{{ c.task_done }}/{{ c.task_total }}</span>
                                </div>
                            </Link>
                        </div>
                        <p v-if="!byStage(stage).length" class="text-[11px] text-slate-300 text-center py-6">Arraste uma campanha aqui</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { patchViaPost } from '@/lib/spoofedRouter';

const props = defineProps({
    stages: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const Field = { props: ['label'], template: `<label class="text-xs font-bold text-slate-500 flex flex-col gap-1">{{ label }}<slot /></label>` };

const showForm = ref(false);
const draggingId = ref(null);

const form = useForm({ name: '', type: 'outro', owner_id: null, start_date: '', end_date: '' });

function createCampaign() {
    form.post(route('marketing.campaigns.store'), {
        preserveScroll: true,
        onSuccess: () => { form.reset(); showForm.value = false; },
    });
}

function byStage(stage) {
    return props.campaigns.filter(c => c.stage === stage);
}

function onDragStart(id) { draggingId.value = id; }
function onDrop(stage) {
    if (draggingId.value === null) return;
    patchViaPost(route('marketing.campaigns.stage', draggingId.value), { stage }, { preserveScroll: true, preserveState: true });
    draggingId.value = null;
}

const STAGE_LABELS = { ideia: 'Ideia', planejamento: 'Planejamento', execucao: 'Em execução', revisao: 'Revisão', concluido: 'Concluído' };
const TYPE_LABELS = { lancamento: 'Lançamento', liquidacao: 'Liquidação', sazonal: 'Sazonal', recorrente: 'Recorrente', outro: 'Outro' };
const TYPE_BADGES = { lancamento: 'b-blue', liquidacao: 'b-red', sazonal: 'b-amber', recorrente: 'b-indigo', outro: 'b-slate' };
function stageLabel(s) { return STAGE_LABELS[s] || s; }
function typeLabel(t) { return TYPE_LABELS[t] || t; }
function typeBadge(t) { return TYPE_BADGES[t] || 'b-slate'; }
</script>

<style scoped>
.inp { @apply w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400; }
.badge { @apply text-[9px] font-black px-2 py-0.5 rounded-full uppercase; }
.b-blue { @apply bg-blue-100 text-blue-700; }
.b-red { @apply bg-red-100 text-red-700; }
.b-amber { @apply bg-amber-100 text-amber-700; }
.b-indigo { @apply bg-indigo-100 text-indigo-700; }
.b-slate { @apply bg-slate-100 text-slate-500; }
</style>
