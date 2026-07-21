<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-file-import"></i> Importações Magazord
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i :class="config.icon" class="text-blue-500"></i>
                    {{ config.title }}
                </h1>
                <p class="text-slate-500 mt-2 font-medium">{{ config.description }}</p>
            </div>

            <!-- Sub-navegação entre os 4 tipos -->
            <div class="flex flex-wrap gap-2 mb-8">
                <Link v-for="t in allTypes" :key="t.key" :href="route('magazord.show', { type: t.key })"
                    :class="['px-4 py-2 rounded-xl text-sm font-semibold border transition flex items-center gap-2',
                        t.key === type ? 'bg-blue-500 text-white border-blue-500 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']">
                    <i :class="t.icon"></i>{{ t.title }}
                </Link>
            </div>

            <!-- Nota para Preços de Venda (modelo Consulta Dinâmica) -->
            <div v-if="type === 'precos'" class="bg-blue-50 border border-blue-200 text-blue-800 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-info mr-2"></i>
                Use o modelo <b>"Consulta Dinâmica – Custo x Preço de Venda"</b> exportado em CSV. Além do preço de venda
                (base = coluna <code class="k">Site</code>), este importador também atualiza <b>custo</b> e <b>estoque</b> na mesma passada.
            </div>

            <!-- Resultado da última importação -->
            <div v-if="flash.importResult" :class="['rounded-2xl border p-5 mb-6', flash.importResult.ok ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200']">
                <p :class="['font-semibold mb-3', flash.importResult.ok ? 'text-emerald-700' : 'text-red-700']">
                    <i :class="flash.importResult.ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'" class="mr-2"></i>
                    {{ flash.importResult.message }}
                </p>
                <div v-if="flash.importResult.ok" class="flex flex-wrap gap-4">
                    <div class="stat"><div class="stat-v">{{ n(flash.importResult.rows) }}</div><div class="stat-l">linhas lidas</div></div>
                    <div class="stat"><div class="stat-v text-emerald-600">{{ n(flash.importResult.updated) }}</div><div class="stat-l">atualizados</div></div>
                    <div class="stat"><div class="stat-v text-blue-600">{{ n(flash.importResult.created) }}</div><div class="stat-l">criados</div></div>
                    <div class="stat"><div class="stat-v text-slate-400">{{ n(flash.importResult.skipped) }}</div><div class="stat-l">ignorados</div></div>
                </div>
            </div>
            <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ flash.error }}
            </div>

            <!-- Formulário de upload -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Enviar arquivo (.csv)</h2>

                <form @submit.prevent="submit">
                    <label :class="['dz block', form.file ? 'dz-filled' : '']" @dragover.prevent @drop.prevent="onDrop">
                        <div class="flex items-center gap-4">
                            <i :class="form.file ? 'fa-solid fa-file-circle-check text-emerald-500' : 'fa-solid fa-cloud-arrow-up text-slate-400'" class="text-3xl"></i>
                            <div>
                                <div class="font-semibold text-slate-700">
                                    {{ form.file ? form.file.name : 'Clique ou arraste o arquivo CSV aqui' }}
                                </div>
                                <div class="text-xs text-slate-400 mt-0.5">
                                    {{ form.file ? fileSize(form.file.size) : 'Modelo exportado pelo Magazord · latin-1 · separador ";" · até 100 MB' }}
                                </div>
                            </div>
                        </div>
                        <input type="file" accept=".csv,.txt" class="hidden" @change="onFile">
                    </label>

                    <div v-if="form.errors.file" class="text-red-600 text-sm mt-2">{{ form.errors.file }}</div>

                    <div v-if="config.can_create" class="mt-4 flex items-start gap-2">
                        <input id="createMissing" type="checkbox" v-model="form.create_missing" class="mt-1 accent-blue-500">
                        <label for="createMissing" class="text-sm text-slate-600">
                            Criar registros que ainda não existem no banco
                            <span class="text-slate-400">({{ type === 'vendas' ? 'pedidos novos' : 'produtos novos' }}). Se desmarcado, apenas registros já existentes são atualizados.</span>
                        </label>
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <button type="submit" :disabled="!form.file || form.processing" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-database mr-2"></i>
                            {{ form.processing ? 'Importando…' : 'Importar para o banco' }}
                        </button>
                        <span v-if="form.processing" class="text-xs text-slate-400">Arquivos grandes podem levar alguns minutos. Não feche a aba.</span>
                    </div>
                </form>
            </div>

            <!-- Colunas esperadas -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mt-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-3">Colunas esperadas do modelo</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="c in config.columns" :key="c" class="text-xs font-mono bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg">{{ c }}</span>
                </div>
                <p class="text-xs text-slate-400 mt-4">
                    <i class="fa-solid fa-key mr-1"></i>
                    Chave de cruzamento: <code class="k">{{ config.key_label }}</code> ·
                    Campo importado: <code class="k">{{ config.value_label }}</code>
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    type: { type: String, required: true },
    config: { type: Object, required: true },
    allTypes: { type: Array, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({
    file: null,
    create_missing: false,
});

function onFile(e) {
    form.file = e.target.files[0] || null;
}
function onDrop(e) {
    const f = e.dataTransfer.files[0];
    if (f) form.file = f;
}
function submit() {
    form.post(route('magazord.import', { type: props.type }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { form.reset('file'); },
    });
}

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function fileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}
</script>

<style scoped>
.btn-primary { @apply bg-blue-500 hover:bg-blue-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.dz { @apply border-[1.5px] border-dashed border-slate-200 rounded-xl p-6 cursor-pointer transition hover:border-blue-300; }
.dz-filled { @apply border-solid border-emerald-300 bg-emerald-50/50; }
.stat { @apply bg-white border border-slate-200 rounded-xl px-4 py-2.5 min-w-[110px]; }
.stat-v { @apply font-mono text-xl font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[11px] uppercase tracking-wide mt-0.5; }
.k { @apply font-mono bg-slate-100 text-blue-700 px-1.5 py-0.5 rounded text-[11px]; }
</style>
