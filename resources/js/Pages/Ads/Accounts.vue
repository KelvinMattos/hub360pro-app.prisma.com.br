<template>
    <AppLayout title="Contas de ADS">
        <div class="p-6 lg:p-8 max-w-4xl mx-auto">
            <div class="mb-8">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">
                    <i class="fa-solid fa-sliders"></i> Configurações
                </div>
                <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-bullhorn text-teal-500"></i>
                    Contas de ADS
                </h1>
                <p class="text-slate-500 mt-2 font-medium">
                    Cadastre uma conta pra cada conta de anúncio que você opera (Google Ads / Meta Ads). Ao importar o
                    relatório de gastos, você escolhe qual conta recebeu aquele arquivo.
                </p>
            </div>

            <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>{{ flash.error }}
            </div>

            <!-- Formulário de nova conta -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Nova conta</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <select v-model="form.platform" class="input">
                        <option value="" disabled>Plataforma</option>
                        <option v-for="p in platforms" :key="p.key" :value="p.key">{{ p.label }}</option>
                    </select>
                    <input v-model="form.label" type="text" placeholder="Nome da conta (ex.: Conta Principal)" class="input">
                    <div class="flex gap-2">
                        <input v-model="form.external_account_id" type="text" placeholder="ID da conta (opcional)" class="input flex-1">
                        <button @click="submit" :disabled="!form.platform || !form.label" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                            Adicionar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Lista por plataforma -->
            <div v-for="p in platforms" :key="p.key" class="mb-6">
                <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-2">{{ p.label }}</h2>
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm divide-y divide-slate-100">
                    <div v-for="a in accountsByPlatform[p.key] || []" :key="a.id" class="flex items-center justify-between px-5 py-3">
                        <div>
                            <div class="font-semibold text-slate-700">{{ a.label }}</div>
                            <div class="text-xs text-slate-400" v-if="a.external_account_id">{{ a.external_account_id }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span :class="['text-xs font-bold px-2 py-1 rounded-lg', a.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400']">
                                {{ a.is_active ? 'Ativa' : 'Inativa' }}
                            </span>
                            <button @click="toggle(a)" class="text-slate-400 hover:text-teal-600" title="Ativar/desativar">
                                <i class="fa-solid fa-toggle-on" v-if="a.is_active"></i>
                                <i class="fa-solid fa-toggle-off" v-else></i>
                            </button>
                            <button @click="destroy(a)" class="text-slate-300 hover:text-red-500" title="Remover">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div v-if="!(accountsByPlatform[p.key] || []).length" class="px-5 py-4 text-sm text-slate-400">
                        Nenhuma conta cadastrada.
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    accounts: { type: Array, required: true },
    platforms: { type: Array, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({ platform: '', label: '', external_account_id: '' });

const accountsByPlatform = computed(() => {
    const map = {};
    for (const a of props.accounts) {
        (map[a.platform] ||= []).push(a);
    }
    return map;
});

function submit() {
    form.post(route('ads.accounts.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function toggle(account) {
    router.patch(route('ads.accounts.toggle', account.id), {}, { preserveScroll: true });
}

function destroy(account) {
    if (!confirm(`Remover a conta "${account.label}"?`)) return;
    router.delete(route('ads.accounts.destroy', account.id), { preserveScroll: true });
}
</script>

<style scoped>
.input { @apply border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-200; }
.btn-primary { @apply bg-teal-500 hover:bg-teal-600 text-white font-semibold rounded-lg px-4 py-2 transition shadow-sm text-sm; }
</style>
