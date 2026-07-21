<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-4xl mx-auto">
            <div class="mb-8">
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-gears text-slate-500"></i>
                    Configurações do <span class="text-slate-600">Sistema</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Operações administrativas do sistema. Use com cautela.</p>
            </div>

            <!-- Zona de Perigo -->
            <div class="border-2 border-red-200 bg-red-50/40 rounded-2xl overflow-hidden">
                <div class="bg-red-500 text-white px-6 py-3 flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                    <h2 class="font-black uppercase tracking-widest text-sm">Zona de Perigo</h2>
                </div>

                <div class="p-6">
                    <h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-trash-can text-red-500"></i> Limpar catálogo e vendas (recomeçar importações)
                    </h3>
                    <p class="text-sm text-slate-600 mt-2">
                        Remove <b>todos os produtos, dados de produto e pedidos</b> para você reimportar do zero.
                        <b>Preserva</b> usuários, empresa e as configurações de canais.
                        <span class="text-red-600 font-semibold">Esta ação é irreversível.</span>
                    </p>

                    <!-- Alerta -->
                    <div class="mt-4 bg-red-100 border border-red-200 text-red-800 text-sm rounded-xl px-4 py-3">
                        <i class="fa-solid fa-circle-exclamation mr-2"></i>
                        Faça isso apenas se tiver certeza. Recomendado ter os arquivos de importação em mãos antes de limpar.
                    </div>

                    <!-- Tabelas afetadas -->
                    <div class="mt-5">
                        <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Registros que serão apagados</div>
                        <div v-if="preview.length" class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            <div v-for="t in preview" :key="t.table" class="bg-white border border-slate-200 rounded-lg px-3 py-2 flex items-center justify-between">
                                <span class="text-xs font-mono text-slate-500">{{ t.table }}</span>
                                <span class="text-sm font-bold" :class="t.count > 0 ? 'text-red-600' : 'text-slate-300'">{{ n(t.count) }}</span>
                            </div>
                        </div>
                        <p v-else class="text-sm text-slate-400">Nenhum registro para apagar.</p>
                        <p class="text-sm text-slate-600 mt-3 font-semibold">Total: {{ n(total) }} registros</p>
                    </div>

                    <!-- Confirmação -->
                    <div class="mt-6 border-t border-red-200 pt-5">
                        <label class="text-sm text-slate-700 font-semibold">
                            Para confirmar, digite <code class="bg-white border border-red-200 text-red-600 px-2 py-0.5 rounded font-mono">{{ confirmPhrase }}</code> abaixo:
                        </label>
                        <div class="flex flex-wrap items-center gap-3 mt-2">
                            <input v-model="form.confirm" type="text" :placeholder="confirmPhrase"
                                class="bg-white border border-red-200 text-slate-800 rounded-lg px-3 py-2 font-mono outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100 w-64">
                            <button @click="submit" :disabled="!canSubmit || form.processing"
                                class="bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg px-5 py-2.5 transition disabled:opacity-40 disabled:cursor-not-allowed">
                                <i class="fa-solid fa-trash-can mr-2"></i>{{ form.processing ? 'Limpando…' : 'Limpar tudo agora' }}
                            </button>
                        </div>
                        <div v-if="form.errors.confirm" class="text-red-600 text-sm mt-2">{{ form.errors.confirm }}</div>
                    </div>

                    <!-- Ordem de importação -->
                    <div class="mt-6 bg-white border border-slate-200 rounded-xl p-4">
                        <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Depois de limpar, importe nesta ordem</div>
                        <ol class="text-sm text-slate-600 space-y-1 list-decimal list-inside">
                            <li>Preços de Venda (Consulta Dinâmica) — <b>marque "criar produtos inexistentes"</b></li>
                            <li>Produtos &amp; Datas — <b>marque "criar produtos inexistentes"</b> (traz a data de lançamento)</li>
                            <li>Estoque — atualiza quantidades</li>
                            <li>Custos — refina o custo (opcional)</li>
                            <li>Produtos com Desconto — promoção (opcional)</li>
                            <li>Vendas — pedidos</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    preview: { type: Array, default: () => [] },
    confirmPhrase: { type: String, default: 'LIMPAR TUDO' },
});

const total = computed(() => props.preview.reduce((s, t) => s + (t.count || 0), 0));
const form = useForm({ confirm: '' });
const canSubmit = computed(() => form.confirm.trim() === props.confirmPhrase);

function submit() {
    if (!canSubmit.value) return;
    if (!confirm('Última confirmação: apagar TODOS os produtos e vendas? Não há como desfazer.')) return;
    form.post(route('settings.system.reset_catalog'), {
        preserveScroll: true,
        onSuccess: () => form.reset('confirm'),
    });
}

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
</script>
