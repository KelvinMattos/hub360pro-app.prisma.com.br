<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1300px] mx-auto">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-sliders text-violet-500"></i>
                        Config. de <span class="bg-gradient-to-r from-violet-500 to-fuchsia-600 bg-clip-text text-transparent">Canais</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Parâmetros de precificação da sua empresa. Alimentam o Centro de Decisão, a Calculadora de Canais e o Cálculo Promo.
                    </p>
                </div>
                <button @click="reset" class="btn-ghost text-sm"><i class="fa-solid fa-rotate-left mr-2"></i>Restaurar padrão</button>
            </div>

            <!-- Globais -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Parâmetros globais</h2>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-5">
                    <div><label class="lbl">Imposto (%)</label><input v-model.number="form.imposto" type="number" step="0.01" class="inp"></div>
                    <div><label class="lbl">MC (%)</label><input v-model.number="form.mc" type="number" step="0.01" class="inp"></div>
                    <div><label class="lbl">Desc. PV atual (%)</label><input v-model.number="form.descAtualDefault" type="number" step="0.01" class="inp"></div>
                    <div><label class="lbl">Desc. equilíbrio (%)</label><input v-model.number="form.descEquilDefault" type="number" step="0.01" class="inp"></div>
                    <div><label class="lbl">Terminação (R$)</label><input v-model.number="form.rounding" type="number" step="0.01" class="inp"></div>
                </div>
            </div>

            <!-- Canais -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Canais</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-200">
                                <th class="py-2 pr-3 font-semibold">Ativo</th>
                                <th class="py-2 px-3 font-semibold">Canal</th>
                                <th class="py-2 px-3 font-semibold">Comissão %</th>
                                <th class="py-2 px-3 font-semibold">Taxa fixa</th>
                                <th class="py-2 px-3 font-semibold">Markup %</th>
                                <th class="py-2 px-3 font-semibold">Desc. PV %</th>
                                <th class="py-2 px-3 font-semibold">Desc. eq. %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ch in form.channels" :key="ch.id" class="border-b border-slate-100 hover:bg-slate-50/60" :class="{ 'opacity-50': !ch.active }">
                                <td class="py-2 pr-3"><input type="checkbox" v-model="ch.active" class="accent-violet-500"></td>
                                <td class="py-2 px-3"><input v-model="ch.label" type="text" class="tbl-input !w-44"></td>
                                <td class="py-2 px-3"><input v-model.number="ch.comissao" type="number" step="0.01" class="tbl-input"></td>
                                <td class="py-2 px-3">
                                    <select v-model="ch.temFaixa" class="tbl-input !w-24">
                                        <option value="none">nenhuma</option>
                                        <option value="ml">ML</option>
                                        <option value="shopee">Shopee</option>
                                    </select>
                                </td>
                                <td class="py-2 px-3"><input v-model.number="ch.markup" type="number" step="0.001" class="tbl-input"></td>
                                <td class="py-2 px-3"><input v-model.number="ch.descAtual" type="number" step="0.01" class="tbl-input"></td>
                                <td class="py-2 px-3"><input v-model.number="ch.descEquil" type="number" step="0.01" class="tbl-input"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center gap-3 mt-6">
                    <button @click="save" :disabled="form.processing" class="btn-primary disabled:opacity-40"><i class="fa-solid fa-floppy-disk mr-2"></i>{{ form.processing ? 'Salvando…' : 'Salvar configuração' }}</button>
                    <span v-if="form.recentlySuccessful" class="text-emerald-600 text-sm font-semibold"><i class="fa-solid fa-check mr-1"></i>Salvo!</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ config: { type: Object, required: true } });

const form = useForm({
    imposto: props.config.imposto,
    mc: props.config.mc,
    descAtualDefault: props.config.descAtualDefault,
    descEquilDefault: props.config.descEquilDefault,
    rounding: props.config.rounding,
    channels: props.config.channels.map(c => ({
        id: c.id, label: c.label, origem: c.origem, col: c.col,
        comissao: c.comissao, temFaixa: c.temFaixa, markup: c.markup,
        descAtual: c.descAtual, descEquil: c.descEquil, active: c.active !== false,
    })),
});

function save() {
    form.post(route('pricing.channels.update'), { preserveScroll: true });
}
function reset() {
    if (confirm('Restaurar a configuração padrão? Suas alterações serão perdidas.')) {
        useForm({}).post(route('pricing.channels.reset'), { preserveScroll: true });
    }
}
</script>

<style scoped>
.lbl { @apply block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5; }
.inp { @apply w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-2 font-mono outline-none focus:border-violet-400 transition; }
.tbl-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-2 py-1 text-sm w-20 font-mono outline-none focus:border-violet-400 transition; }
.btn-primary { @apply bg-violet-500 hover:bg-violet-600 text-white font-semibold rounded-lg px-5 py-2.5 transition shadow-sm; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 transition; }
</style>
