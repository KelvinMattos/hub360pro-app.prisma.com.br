<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-5xl mx-auto">
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-bullseye"></i> Marketing
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Tarefas</h1>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">
                <form @submit.prevent="createTask" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                    <input v-model="form.title" type="text" placeholder="Nova tarefa…" class="inp md:col-span-2" required>
                    <select v-model="form.campaign_id" class="inp">
                        <option :value="null">Sem campanha</option>
                        <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <select v-model="form.assignee_id" class="inp">
                        <option :value="null">Sem responsável</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="form.priority" class="inp">
                        <option v-for="p in priorities" :key="p" :value="p">{{ priorityLabel(p) }}</option>
                    </select>
                    <input v-model="form.due_date" type="date" class="inp">
                    <button type="submit" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700 md:col-span-6 md:w-auto md:justify-self-start">
                        Adicionar tarefa
                    </button>
                </form>
            </div>

            <div class="flex flex-wrap gap-2 mb-4">
                <button @click="applyFilter({ status: null })" :class="['tab-btn', !filters.status ? 'tab-active' : '']">Todas</button>
                <button v-for="s in statuses" :key="s" @click="applyFilter({ status: s })" :class="['tab-btn', filters.status === s ? 'tab-active' : '']">{{ statusLabel(s) }}</button>
                <select v-model="assigneeFilter" @change="applyFilter({ assignee_id: assigneeFilter })" class="inp !w-auto ml-auto">
                    <option value="">Todos os responsáveis</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div v-for="t in tasks" :key="t.id" class="flex items-center gap-3 px-5 py-3 border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                    <select :value="t.status" @change="updateStatus(t, $event.target.value)" class="inp !w-auto text-xs !py-1">
                        <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                    </select>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold truncate" :class="t.status === 'done' ? 'line-through text-slate-400' : 'text-slate-700'">{{ t.title }}</p>
                        <p class="text-[10px] text-slate-400">
                            {{ t.assignee_name || 'sem responsável' }} ·
                            <span :class="isOverdue(t) ? 'text-red-500 font-bold' : ''">{{ t.due_date || 'sem prazo' }}</span>
                            <span v-if="t.campaign_name"> · {{ t.campaign_name }}</span>
                        </p>
                    </div>
                    <span :class="['badge', priorityBadge(t.priority)]">{{ priorityLabel(t.priority) }}</span>
                    <button @click="deleteTask(t.id)" class="text-slate-300 hover:text-red-500"><i class="fa-solid fa-trash"></i></button>
                </div>
                <p v-if="!tasks.length" class="text-center text-slate-400 text-xs py-10">Nenhuma tarefa encontrada.</p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { patchViaPost, deleteViaPost } from '@/lib/spoofedRouter';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const assigneeFilter = ref(props.filters.assignee_id || '');

const form = useForm({ title: '', campaign_id: null, assignee_id: null, priority: 'media', due_date: '' });
function createTask() {
    form.post(route('marketing.tasks.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}

function updateStatus(task, status) {
    patchViaPost(route('marketing.tasks.update', task.id), { status }, { preserveScroll: true, preserveState: true });
}
function deleteTask(id) {
    deleteViaPost(route('marketing.tasks.destroy', id), { preserveScroll: true });
}

function applyFilter(patch) {
    const q = { ...props.filters, ...patch };
    Object.keys(q).forEach(k => { if (q[k] === null || q[k] === '') delete q[k]; });
    router.get(route('marketing.tasks.index'), q, { preserveScroll: true, preserveState: true, replace: true });
}

function isOverdue(t) {
    return t.due_date && t.status !== 'done' && new Date(t.due_date) < new Date(new Date().toDateString());
}

const STATUS_LABELS = { todo: 'A fazer', doing: 'Em andamento', done: 'Concluída' };
const PRIORITY_LABELS = { baixa: 'Baixa', media: 'Média', alta: 'Alta' };
const PRIORITY_BADGES = { baixa: 'b-slate', media: 'b-blue', alta: 'b-red' };
function statusLabel(s) { return STATUS_LABELS[s] || s; }
function priorityLabel(p) { return PRIORITY_LABELS[p] || p; }
function priorityBadge(p) { return PRIORITY_BADGES[p] || 'b-slate'; }
</script>

<style scoped>
.inp { @apply w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400; }
.badge { @apply text-[9px] font-black px-2 py-0.5 rounded-full uppercase; }
.b-blue { @apply bg-blue-100 text-blue-700; }
.b-red { @apply bg-red-100 text-red-700; }
.b-slate { @apply bg-slate-100 text-slate-500; }
.tab-btn { @apply text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50; }
.tab-active { @apply bg-slate-900 text-white border-slate-900; }
</style>
