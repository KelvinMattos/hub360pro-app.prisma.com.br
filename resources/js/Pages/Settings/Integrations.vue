<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="mb-10">
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                    Central de <span class="bg-gradient-to-r from-blue-400 to-indigo-500 bg-clip-text text-transparent">Conectividade</span>
                </h1>
                <p class="text-slate-500 mt-2 font-medium text-lg italic">Gerencie suas APIs e regras de negócio globais.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <!-- Left: Marketplaces -->
                <div class="lg:col-span-8 space-y-8">
                    <!-- Mercado Livre Card -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl relative overflow-hidden group">
                        <div class="absolute right-0 top-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                            <i class="fa-brands fa-laravel text-9xl text-yellow-500"></i>
                        </div>
                        
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-14 h-14 rounded-2xl bg-[#FFE600]/10 flex items-center justify-center border border-[#FFE600]/20 shadow-lg">
                                <i class="fa-solid fa-handshake text-[#FFE600] text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-slate-900">Mercado Livre</h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <span v-if="integrations.mercadolibre?.status === 'active'" class="flex items-center gap-1 text-[10px] font-black text-emerald-400 uppercase tracking-widest bg-emerald-400/10 px-2 py-0.5 rounded-full border border-emerald-500/20">
                                        <i class="fa-solid fa-circle-check"></i> CONECTADO
                                    </span>
                                    <span v-else class="flex items-center gap-1 text-[10px] font-black text-amber-500 uppercase tracking-widest bg-amber-500/10 px-2 py-0.5 rounded-full border border-amber-500/20">
                                        <i class="fa-solid fa-circle-exclamation"></i> PENDENTE
                                    </span>
                                </div>
                            </div>
                        </div>

                        <form @submit.prevent="saveKeys('mercadolibre')" class="space-y-6 relative z-10">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <input v-model="meliForm.app_id" type="password" class="w-full bg-slate-800 border border-slate-700 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold transition-all outline-none placeholder:text-slate-500" placeholder="Insira o seu App ID">
                                </div>
                                <div class="space-y-2">
                                    <input v-model="meliForm.client_secret" type="password" class="w-full bg-slate-800 border border-slate-700 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold transition-all outline-none placeholder:text-slate-500" placeholder="Insira o seu Client Secret">
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between pt-4">
                                <div class="text-[10px] text-slate-500 font-bold max-w-md italic">
                                    Suas chaves são criptografadas e armazenadas em ambiente seguro. 
                                    Nunca compartilhe seu Client Secret.
                                </div>
                                <div class="flex gap-4">
                                    <a v-if="integrations.mercadolibre && meliForm.app_id && meliForm.client_secret" :href="route('ml.connect')" class="bg-[#FFE600] hover:bg-yellow-400 text-slate-900 px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-[10px] transition-all shadow-xl active:scale-95 flex items-center gap-2">
                                        <i class="fa-solid fa-link"></i> Autorizar
                                    </a>
                                    <button v-else-if="integrations.mercadolibre" type="button" @click="showNotSavedError" class="bg-slate-700 text-slate-400 px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-[10px] cursor-not-allowed flex items-center gap-2">
                                        <i class="fa-solid fa-lock"></i> Salve para Autorizar
                                    </button>
                                    <button type="submit" :disabled="meliForm.processing" class="bg-blue-600 hover:bg-blue-500 text-slate-900 px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-[10px] transition-all shadow-xl active:scale-95 disabled:opacity-50">
                                        Salvar Chaves
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Shopee Placeholder -->
                    <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl relative overflow-hidden group grayscale opacity-60">
                         <div class="flex items-center gap-4">
                            <div class="w-14 h-14 rounded-2xl bg-orange-500/10 flex items-center justify-center border border-orange-500/20 shadow-lg">
                                <i class="fa-solid fa-bag-shopping text-orange-500 text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-slate-900">Shopee</h3>
                                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest bg-slate-800 px-2 py-0.5 rounded-full border border-slate-700 mt-1 inline-block">EM BREVE</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Financial Settings -->
                <div class="lg:col-span-4">
                    <div class="sticky top-8 space-y-8">
                        <div class="bg-gradient-to-br from-indigo-600 to-blue-700 rounded-[2.5rem] p-8 shadow-2xl">
                            <h3 class="text-slate-900 font-black text-xs uppercase tracking-[0.2em] mb-8 flex items-center gap-2">
                                <i class="fa-solid fa-coins"></i> Regras de Negócio
                            </h3>
                            
                            <form @submit.prevent="saveFinance" class="space-y-6">
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black text-slate-900/50 uppercase tracking-widest pl-1">Alíquota de Imposto Fiscal (%)</label>
                                    <div class="relative">
                                        <input v-model="financeForm.tax_rate" type="number" step="0.01" class="w-full bg-white/10 border border-white/20 focus:border-white text-slate-900 rounded-2xl py-4 pl-6 pr-12 font-black text-2xl outline-none transition-all">
                                        <span class="absolute right-6 top-1/2 -translate-y-1/2 text-slate-900/50 font-black text-xl">%</span>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-[10px] font-black text-slate-900/50 uppercase tracking-widest pl-1">Custo Operacional Fixo (R$)</label>
                                    <div class="relative">
                                        <span class="absolute left-6 top-1/2 -translate-y-1/2 text-slate-900/50 font-black text-xl">R$</span>
                                        <input v-model="financeForm.operational_rate" type="number" step="0.01" class="w-full bg-white/10 border border-white/20 focus:border-white text-slate-900 rounded-2xl py-4 pl-16 pr-6 font-black text-2xl outline-none transition-all">
                                    </div>
                                    <p class="text-[9px] text-slate-900/40 font-bold leading-relaxed mt-2 uppercase tracking-tight">
                                        Este valor será debitado automaticamente em cada venda no DRE para cobrir embalagem, fitas e etiquetas.
                                    </p>
                                </div>

                                <button type="submit" :disabled="financeForm.processing" class="w-full bg-white text-blue-700 py-4 rounded-2xl font-black uppercase tracking-[0.2em] text-xs transition-all hover:bg-blue-50 active:scale-95 disabled:opacity-50 shadow-xl shadow-blue-900/40">
                                    Atualizar Regras
                                </button>
                            </form>
                        </div>

                        <div class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 shadow-2xl">
                             <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-4">Informações da Conta</h4>
                             <div class="space-y-4">
                                <div class="flex justify-between items-center py-2 border-b border-slate-800">
                                    <span class="text-xs text-slate-400 font-bold">Empresa</span>
                                    <span class="text-xs text-slate-900 font-black uppercase italic">{{ company.name }}</span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-slate-800">
                                    <span class="text-xs text-slate-400 font-bold">Plano</span>
                                    <span class="text-[9px] bg-blue-500/10 text-blue-400 px-2 py-0.5 rounded border border-blue-500/20 font-black tracking-widest uppercase">ULTIMATE EDGE</span>
                                </div>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    integrations: Object,
    company: Object
});

const meliForm = useForm({
    app_id: props.integrations.mercadolibre?.app_id || '',
    client_secret: props.integrations.mercadolibre?.client_secret || ''
});

// Sincroniza o formulário se as props mudarem (ex: após salvar)
watch(() => props.integrations, (newVal) => {
    if (newVal.mercadolibre) {
        meliForm.app_id = newVal.mercadolibre.app_id || '';
        meliForm.client_secret = newVal.mercadolibre.client_secret || '';
    }
}, { deep: true });

const financeForm = useForm({
    tax_rate: props.company.tax_rate || 0,
    operational_rate: props.company.operational_rate || 0
});

const saveKeys = (platform) => {
    meliForm.post(route('settings.update_keys', platform), {
        preserveScroll: true,
        onSuccess: () => {
            // Success feedback
        }
    });
};

const showNotSavedError = () => {
    alert('Por favor, salve seu App ID e Client Secret antes de tentar autorizar.');
};

const saveFinance = () => {
    financeForm.post(route('settings.update_finance'), {
        preserveScroll: true
    });
};
</script>
