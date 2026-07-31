<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-5xl mx-auto">
            <Link :href="route('marketing.campaigns.index')" class="text-xs font-bold text-slate-400 hover:text-slate-700 mb-4 inline-block">
                <i class="fa-solid fa-arrow-left mr-1"></i> Voltar ao Kanban
            </Link>

            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <span :class="['badge', typeBadge(editForm.type)]">{{ typeLabel(editForm.type) }}</span>
                        <h1 class="text-2xl font-extrabold text-slate-900 mt-2">{{ campaign.name }}</h1>
                    </div>
                    <select v-model="stageModel" class="inp !w-auto text-sm font-bold">
                        <option v-for="s in stages" :key="s" :value="s">{{ stageLabel(s) }}</option>
                    </select>
                </div>

                <form @submit.prevent="saveCampaign" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <Field label="Descrição" class="md:col-span-4"><textarea v-model="editForm.description" rows="2" class="inp"></textarea></Field>
                    <Field label="Tipo">
                        <select v-model="editForm.type" class="inp">
                            <option v-for="t in types" :key="t" :value="t">{{ typeLabel(t) }}</option>
                        </select>
                    </Field>
                    <Field label="Responsável">
                        <select v-model="editForm.owner_id" class="inp">
                            <option :value="null">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </Field>
                    <Field label="Início"><input v-model="editForm.start_date" type="date" class="inp"></Field>
                    <Field label="Fim"><input v-model="editForm.end_date" type="date" class="inp"></Field>
                    <div class="md:col-span-4 flex justify-between items-center">
                        <button type="submit" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Salvar</button>
                        <button type="button" @click="deleteCampaign" class="text-xs font-bold text-red-500 hover:underline">Excluir campanha</button>
                    </div>
                </form>
            </div>

            <!-- Produtos -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Produtos vinculados ({{ products.length }})</h2>

                <div class="relative mb-4">
                    <input v-model="productSearch" @input="searchProducts" type="text" placeholder="Buscar SKU ou produto pra vincular…"
                        class="inp">
                    <div v-if="searchResults.length" class="absolute z-10 bg-white border border-slate-200 rounded-xl shadow-lg mt-1 w-full max-h-60 overflow-y-auto">
                        <button v-for="p in searchResults" :key="p.id" @click="attachProduct(p)"
                            class="block w-full text-left px-4 py-2 text-sm hover:bg-slate-50">
                            <span class="font-semibold">{{ p.title }}</span>
                            <span class="text-slate-400 text-xs font-mono ml-2">{{ p.sku }}</span>
                        </button>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="p in products" :key="p.product_id" class="border-b border-slate-50">
                            <td class="py-2 pr-3">
                                <p class="font-semibold text-slate-700">{{ p.title }}</p>
                                <p class="text-[10px] text-slate-400 font-mono">{{ p.sku }}<span v-if="p.brand"> · {{ p.brand }}</span></p>
                            </td>
                            <td class="py-2 px-3 text-xs text-slate-500">{{ p.suggested_action || '—' }}</td>
                            <td class="py-2 px-3 text-right font-mono text-slate-600">{{ money(p.sale_price) }}</td>
                            <td class="py-2 pl-3 text-right w-8">
                                <button @click="detachProduct(p.product_id)" class="text-slate-300 hover:text-red-500"><i class="fa-solid fa-xmark"></i></button>
                            </td>
                        </tr>
                        <tr v-if="!products.length"><td colspan="4" class="py-6 text-center text-slate-400 text-xs">Nenhum produto vinculado ainda.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Tarefas -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Tarefas ({{ tasks.length }})</h2>

                <form @submit.prevent="createTask" class="flex flex-wrap gap-2 mb-4">
                    <input v-model="taskForm.title" type="text" placeholder="Nova tarefa…" class="inp flex-1 min-w-[200px]" required>
                    <select v-model="taskForm.assignee_id" class="inp !w-auto">
                        <option :value="null">Sem responsável</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <input v-model="taskForm.due_date" type="date" class="inp !w-auto">
                    <button type="submit" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Adicionar</button>
                </form>

                <div v-for="t in tasks" :key="t.id" class="flex items-center gap-3 py-2 border-b border-slate-50 last:border-0">
                    <select :value="t.status" @change="updateTaskStatus(t, $event.target.value)" class="inp !w-auto text-xs !py-1">
                        <option value="todo">A fazer</option>
                        <option value="doing">Em andamento</option>
                        <option value="done">Concluída</option>
                    </select>
                    <div class="flex-1">
                        <p class="text-sm font-semibold" :class="t.status === 'done' ? 'line-through text-slate-400' : 'text-slate-700'">{{ t.title }}</p>
                        <p class="text-[10px] text-slate-400">{{ t.assignee_name || 'sem responsável' }} · {{ t.due_date || 'sem prazo' }}</p>
                    </div>
                    <button @click="deleteTask(t.id)" class="text-slate-300 hover:text-red-500"><i class="fa-solid fa-trash"></i></button>
                </div>
                <p v-if="!tasks.length" class="text-center text-slate-400 text-xs py-6">Nenhuma tarefa ainda.</p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    campaign: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    stages: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const Field = { props: ['label'], template: `<label class="text-xs font-bold text-slate-500 flex flex-col gap-1">{{ label }}<slot /></label>` };

const editForm = ref({
    description: props.campaign.description, type: props.campaign.type,
    owner_id: props.campaign.owner_id, start_date: props.campaign.start_date, end_date: props.campaign.end_date,
});
const stageModel = ref(props.campaign.stage);

function saveCampaign() {
    router.put(route('marketing.campaigns.update', props.campaign.id), {
        name: props.campaign.name, ...editForm.value,
    }, { preserveScroll: true });
}

function deleteCampaign() {
    if (!confirm('Excluir esta campanha? Produtos vinculados serão desvinculados e tarefas ficarão soltas.')) return;
    router.delete(route('marketing.campaigns.destroy', props.campaign.id));
}

let stageDebounce = null;
watch(stageModel, (v) => {
    clearTimeout(stageDebounce);
    stageDebounce = setTimeout(() => {
        router.patch(route('marketing.campaigns.stage', props.campaign.id), { stage: v }, { preserveScroll: true });
    }, 150);
});

const productSearch = ref('');
const searchResults = ref([]);
let searchDebounce = null;
function searchProducts() {
    clearTimeout(searchDebounce);
    if (!productSearch.value.trim()) { searchResults.value = []; return; }
    searchDebounce = setTimeout(async () => {
        const { data } = await axios.get(route('marketing.campaigns.products.search'), { params: { q: productSearch.value } });
        searchResults.value = data.products || [];
    }, 300);
}
function attachProduct(p) {
    router.post(route('marketing.campaigns.products.attach', props.campaign.id), { product_id: p.id }, {
        preserveScroll: true,
        onSuccess: () => { productSearch.value = ''; searchResults.value = []; },
    });
}
function detachProduct(productId) {
    router.delete(route('marketing.campaigns.products.detach', [props.campaign.id, productId]), { preserveScroll: true });
}

const taskForm = useForm({ title: '', assignee_id: null, due_date: '' });
function createTask() {
    taskForm.transform(data => ({ ...data, campaign_id: props.campaign.id })).post(route('marketing.tasks.store'), {
        preserveScroll: true,
        onSuccess: () => taskForm.reset(),
    });
}
function updateTaskStatus(task, status) {
    router.patch(route('marketing.tasks.update', task.id), { status }, { preserveScroll: true, preserveState: true });
}
function deleteTask(id) {
    router.delete(route('marketing.tasks.destroy', id), { preserveScroll: true });
}

const STAGE_LABELS = { ideia: 'Ideia', planejamento: 'Planejamento', execucao: 'Em execução', revisao: 'Revisão', concluido: 'Concluído' };
const TYPE_LABELS = { lancamento: 'Lançamento', liquidacao: 'Liquidação', sazonal: 'Sazonal', recorrente: 'Recorrente', outro: 'Outro' };
const TYPE_BADGES = { lancamento: 'b-blue', liquidacao: 'b-red', sazonal: 'b-amber', recorrente: 'b-indigo', outro: 'b-slate' };
function stageLabel(s) { return STAGE_LABELS[s] || s; }
function typeLabel(t) { return TYPE_LABELS[t] || t; }
function typeBadge(t) { return TYPE_BADGES[t] || 'b-slate'; }
function money(v) { return v == null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }
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
