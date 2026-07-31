<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-4xl mx-auto">
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fa-solid fa-bullseye"></i> Marketing
                    </div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight">Calendário Comercial</h1>
                    <p class="text-slate-500 mt-2 font-medium">Datas sazonais e comerciais pra planejar campanhas com antecedência.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Adicionar data</h3>
                    <form @submit.prevent="createDate" class="space-y-3">
                        <input v-model="form.title" type="text" placeholder="Título (ex.: Aniversário da loja)" class="inp" required>
                        <input v-model="form.date" type="date" class="inp" required>
                        <select v-model="form.category" class="inp">
                            <option value="sazonal">Sazonal</option>
                            <option value="feriado">Feriado</option>
                            <option value="liquidacao">Liquidação</option>
                            <option value="proprio">Próprio</option>
                        </select>
                        <label class="flex items-center gap-2 text-xs text-slate-500">
                            <input v-model="form.recurring_yearly" type="checkbox" class="accent-blue-500"> Repete todo ano
                        </label>
                        <button type="submit" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Adicionar</button>
                    </form>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Importar datas (CSV)</h3>
                    <p class="text-xs text-slate-400 mb-3">Colunas: <code class="k">Título</code>, <code class="k">Data</code> (dd/mm/aaaa), <code class="k">Categoria</code>, <code class="k">Recorrente</code> (sim/não).</p>
                    <input type="file" accept=".csv,.txt" @change="onFile" class="text-xs mb-3">
                    <button @click="importFile" :disabled="!file" class="text-xs font-bold px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-700 disabled:opacity-40">
                        Importar
                    </button>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div v-for="d in dates" :key="d.id" class="flex items-center gap-4 px-5 py-3 border-b border-slate-50 last:border-0">
                    <div class="w-16 text-center">
                        <p class="text-lg font-black text-slate-800">{{ day(d.date) }}</p>
                        <p class="text-[10px] text-slate-400 uppercase">{{ month(d.date) }}</p>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-slate-700">{{ d.title }}</p>
                        <p class="text-[10px] text-slate-400">
                            <span :class="['badge', categoryBadge(d.category)]">{{ categoryLabel(d.category) }}</span>
                            <span v-if="d.recurring_yearly"> · recorrente</span>
                            <span v-if="d.notes"> · {{ d.notes }}</span>
                        </p>
                    </div>
                    <button v-if="!d.is_global" @click="deleteDate(d.id)" class="text-slate-300 hover:text-red-500"><i class="fa-solid fa-trash"></i></button>
                </div>
                <p v-if="!dates.length" class="text-center text-slate-400 text-xs py-10">Nenhuma data cadastrada.</p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { deleteViaPost } from '@/lib/spoofedRouter';

const props = defineProps({
    dates: { type: Array, default: () => [] },
    year: { type: Number, default: () => new Date().getFullYear() },
});

const form = useForm({ title: '', date: '', category: 'sazonal', recurring_yearly: true });
function createDate() {
    form.post(route('marketing.calendar.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}
function deleteDate(id) {
    if (!confirm('Remover esta data?')) return;
    deleteViaPost(route('marketing.calendar.destroy', id), { preserveScroll: true });
}

const file = ref(null);
function onFile(e) { file.value = e.target.files[0] || null; }
function importFile() {
    if (!file.value) return;
    router.post(route('marketing.calendar.import'), { file: file.value }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { file.value = null; },
    });
}

function day(d) { return new Date(d + 'T00:00:00').getDate(); }
function month(d) { return new Date(d + 'T00:00:00').toLocaleDateString('pt-BR', { month: 'short' }).replace('.', ''); }

const CATEGORY_LABELS = { sazonal: 'Sazonal', feriado: 'Feriado', liquidacao: 'Liquidação', proprio: 'Próprio', importado: 'Importado' };
const CATEGORY_BADGES = { sazonal: 'b-amber', feriado: 'b-slate', liquidacao: 'b-red', proprio: 'b-blue', importado: 'b-indigo' };
function categoryLabel(c) { return CATEGORY_LABELS[c] || c || '—'; }
function categoryBadge(c) { return CATEGORY_BADGES[c] || 'b-slate'; }
</script>

<style scoped>
.inp { @apply w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400; }
.badge { @apply text-[9px] font-black px-2 py-0.5 rounded-full uppercase; }
.b-amber { @apply bg-amber-100 text-amber-700; }
.b-slate { @apply bg-slate-100 text-slate-500; }
.b-red { @apply bg-red-100 text-red-700; }
.b-blue { @apply bg-blue-100 text-blue-700; }
.b-indigo { @apply bg-indigo-100 text-indigo-700; }
.k { @apply font-mono bg-slate-100 px-1.5 py-0.5 rounded; }
</style>
