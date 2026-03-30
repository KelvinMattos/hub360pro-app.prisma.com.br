<template>
    <AppLayout>
        <div class="px-6 py-8 max-w-7xl mx-auto min-h-screen">
            <!-- Header Section: Premium & Minimal -->
            <div class="mb-12 flex flex-col md:flex-row justify-between items-end gap-6 border-b border-black/[0.03] pb-8">
                <div>
                    <h1 class="text-6xl font-black text-slate-800 tracking-tighter leading-none mb-4">
                        Connectivity <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">360</span>
                    </h1>
                    <p class="text-slate-400 font-bold text-sm uppercase tracking-[0.2em] flex items-center gap-3">
                        <i class="fa-solid fa-server text-blue-500"></i>
                        Central de Integrações Inteligentes
                    </p>
                </div>

                <div class="flex gap-4">
                    <div class="bg-white px-6 py-4 rounded-[1.5rem] shadow-sm border border-black/[0.04]">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Status Global</p>
                        <div class="flex items-center gap-2">
                             <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full animate-pulse"></div>
                             <span class="text-sm font-black text-slate-800">Sistemas Ativos</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
                <!-- Main Area: Connected Accounts & Platforms -->
                <div class="lg:col-span-8 space-y-12">
                    
                    <!-- Section: Active Accounts (Cards) -->
                    <section v-if="credentials.length > 0">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-black text-slate-700 uppercase tracking-widest flex items-center gap-3">
                                <i class="fa-solid fa-link text-blue-500/50"></i>
                                Contas Conectadas
                            </h2>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div v-for="account in credentials" :key="account.id" 
                                 class="group bg-white rounded-[2rem] border border-black/5 p-6 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-500 overflow-hidden relative"
                            >
                                <!-- Platform Decorate -->
                                <div class="absolute right-[-10%] top-[-10%] opacity-[0.03] group-hover:opacity-[0.08] transition-opacity">
                                     <i v-if="account.platform === 'mercadolivre'" class="fa-solid fa-handshake text-[120px] -rotate-12"></i>
                                </div>

                                <div class="relative z-10">
                                    <div class="flex justify-between items-start mb-6">
                                        <div class="flex items-center gap-4">
                                            <div class="relative">
                                                <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center border border-black/5 overflow-hidden">
                                                    <img :src="`https://ui-avatars.com/api/?name=${account.account_nickname || 'ML'}&background=4f46e5&color=fff&size=128`" class="w-full h-full object-cover">
                                                </div>
                                                <div class="absolute -right-1 -bottom-1 w-6 h-6 rounded-lg bg-white border border-black/5 flex items-center justify-center">
                                                    <i v-if="account.platform === 'mercadolivre'" class="fa-solid fa-handshake text-[#FFE600] text-[10px]"></i>
                                                </div>
                                            </div>
                                            <div>
                                                <h3 class="text-lg font-black text-slate-800 leading-tight truncate max-w-[120px]">{{ account.account_nickname }}</h3>
                                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">ID: {{ account.external_user_id }}</p>
                                            </div>
                                        </div>

                                        <div class="flex gap-2">
                                            <button @click="toggleCredential(account)" 
                                                    :class="account.is_active ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-slate-50 text-slate-400 border-slate-100'"
                                                    class="w-8 h-8 rounded-xl border flex items-center justify-center transition-all hover:scale-110 shadow-sm"
                                            >
                                                <i class="fa-solid fa-power-off text-[10px]"></i>
                                            </button>
                                            <button @click="deleteCredential(account)" 
                                                    class="w-8 h-8 bg-red-50 text-red-500 border border-red-100 rounded-xl flex items-center justify-center transition-all hover:scale-110 shadow-sm"
                                            >
                                                <i class="fa-solid fa-trash text-[10px]"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between pt-6 border-t border-black/[0.03]">
                                        <div class="flex items-center gap-2">
                                            <div :class="account.is_active ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]' : 'bg-slate-300'" class="w-2 h-2 rounded-full"></div>
                                            <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">
                                                {{ account.is_active ? 'Sincronizando' : 'Pausado' }}
                                            </span>
                                        </div>
                                        <div class="text-[9px] font-black text-slate-300 uppercase italic">
                                            Expira: {{ formatDate(account.token_expires_at) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- "Empty State" or "Add" Card -->
                            <a :href="route('ml.connect')" 
                               v-if="integrations.mercadolivre?.status === 'active'"
                               class="bg-white border-4 border-dashed border-black/5 rounded-[2rem] flex flex-col items-center justify-center p-8 group hover:border-blue-500/30 transition-all hover:bg-blue-50/10"
                            >
                                <div class="w-12 h-12 rounded-2xl bg-black/[0.02] flex items-center justify-center text-slate-300 group-hover:bg-blue-600 group-hover:text-white transition-all shadow-sm group-hover:shadow-lg mb-3">
                                    <i class="fa-solid fa-plus text-xl"></i>
                                </div>
                                <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest group-hover:text-blue-600">Conectar Nova Conta</span>
                            </a>
                        </div>
                    </section>

                    <!-- Section: Platform Management -->
                    <section>
                         <div class="flex items-center justify-between mb-8">
                            <h2 class="text-lg font-black text-slate-700 uppercase tracking-widest flex items-center gap-3">
                                <i class="fa-solid fa-gear text-slate-400/50"></i>
                                Configuração de Plataformas
                            </h2>
                        </div>

                        <div class="space-y-6">
                            <!-- Mercado Livre Config Card -->
                            <div class="bg-white rounded-[2.5rem] p-10 border border-black/5 shadow-premium overflow-hidden relative">
                                <div class="absolute right-0 top-0 p-12 opacity-[0.02] pointer-events-none">
                                     <i class="fa-solid fa-handshake text-[160px]"></i>
                                </div>

                                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-8 mb-10 relative z-10">
                                    <div class="flex items-center gap-5">
                                        <div class="w-16 h-16 rounded-3xl bg-[#FFE600]/10 border border-[#FFE600]/20 flex items-center justify-center shadow-lg">
                                            <i class="fa-solid fa-handshake text-[#FFE600] text-3xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-2xl font-black text-slate-800 tracking-tight">Mercado Livre</h3>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span v-if="integrations.mercadolibre?.status === 'active'" class="flex items-center gap-1.5 text-[10px] font-black text-emerald-500 bg-emerald-50 px-3 py-1 rounded-full border border-emerald-500/10 uppercase tracking-widest">
                                                    <i class="fa-solid fa-circle-check"></i> Chaves Ativas
                                                </span>
                                                <span v-else class="flex items-center gap-1.5 text-[10px] font-black text-amber-500 bg-amber-50 px-3 py-1 rounded-full border border-amber-500/10 uppercase tracking-widest">
                                                    <i class="fa-solid fa-circle-exclamation"></i> Chaves Ausentes
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex gap-3">
                                         <a v-if="integrations.mercadolibre && meliForm.app_id && meliForm.client_secret" :target="_blank" :href="route('ml.connect')" class="bg-[#FFE600] hover:bg-[#FFE600]/90 text-slate-900 px-8 py-4 rounded-full font-black uppercase tracking-[0.1em] text-xs transition-all shadow-xl shadow-yellow-500/20 active:scale-95 flex items-center gap-3">
                                            <i class="fa-solid fa-link animate-bounce-horizontal"></i>
                                            Autorizar Nova Conta
                                        </a>
                                        <button v-else class="bg-slate-100 text-slate-400 px-8 py-4 rounded-full font-black uppercase tracking-widest text-xs cursor-not-allowed opacity-50">
                                            Configure as Chaves Primeiro
                                        </button>
                                    </div>
                                </div>

                                <form @submit.prevent="saveKeys('mercadolibre')" class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10">
                                    <div class="space-y-3">
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4 flex items-center gap-2">
                                            <i class="fa-solid fa-key"></i> Application ID (App ID)
                                        </label>
                                        <input v-model="meliForm.app_id" type="password" class="w-full bg-slate-50 border border-black/5 focus:border-blue-500 focus:bg-white text-slate-800 rounded-3xl py-4 px-8 font-bold transition-all outline-none placeholder:text-slate-300" placeholder="••••••••••••••••">
                                    </div>
                                    <div class="space-y-3">
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4 flex items-center gap-2">
                                            <i class="fa-solid fa-shield-halved"></i> Secret Key (Client Secret)
                                        </label>
                                        <input v-model="meliForm.client_secret" type="password" class="w-full bg-slate-50 border border-black/5 focus:border-blue-500 focus:bg-white text-slate-800 rounded-3xl py-4 px-8 font-bold transition-all outline-none placeholder:text-slate-300" placeholder="••••••••••••••••">
                                    </div>
                                    
                                    <div class="md:col-span-2 flex justify-end mt-4">
                                        <button type="submit" :disabled="meliForm.processing" class="bg-blue-600 hover:bg-blue-500 text-white px-10 py-4 rounded-full font-black uppercase tracking-[0.2em] text-xs transition-all shadow-2xl shadow-blue-600/30 active:scale-95 disabled:opacity-50">
                                            {{ meliForm.processing ? 'Sincronizando...' : 'Atualizar Credenciais de API' }}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Upcoming Platforms -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 opacity-40 grayscale pointer-events-none">
                                <div class="bg-white rounded-[2rem] p-8 border border-black/5 flex items-center gap-5">
                                    <div class="w-14 h-14 rounded-2xl bg-orange-500/10 flex items-center justify-center border border-orange-500/20 shadow-md">
                                        <i class="fa-solid fa-bag-shopping text-orange-500 text-2xl"></i>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-black text-slate-800">Shopee</h4>
                                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest bg-slate-100 px-2 py-0.5 rounded-full">Coming Soon</span>
                                    </div>
                                </div>
                                <div class="bg-white rounded-[2rem] p-8 border border-black/5 flex items-center gap-5">
                                    <div class="w-14 h-14 rounded-2xl bg-black/5 flex items-center justify-center border border-black/5 shadow-md">
                                        <i class="fa-brands fa-amazon text-slate-800 text-2xl"></i>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-black text-slate-800">Amazon SP-API</h4>
                                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest bg-slate-100 px-2 py-0.5 rounded-full">In Roadmap</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Right Area: Financial Sidebar -->
                <div class="lg:col-span-4">
                    <div class="sticky top-8 space-y-8">
                        <!-- Business Rules Card -->
                        <div class="bg-gradient-to-br from-indigo-700 to-blue-800 rounded-[3rem] p-10 shadow-2xl relative overflow-hidden">
                            <!-- Background Decor -->
                            <div class="absolute top-0 right-0 p-8 opacity-10">
                                <i class="fa-solid fa-coins text-8xl text-white"></i>
                            </div>

                            <h3 class="text-white/60 font-black text-[10px] uppercase tracking-[0.3em] mb-10 flex items-center gap-3">
                                <i class="fa-solid fa-calculator"></i> Business Algorithm
                            </h3>
                            
                            <form @submit.prevent="saveFinance" class="space-y-8 relative z-10">
                                <div class="space-y-3">
                                    <label class="text-[10px] font-black text-white/40 uppercase tracking-widest pl-2">Alíquota Fiscal Global (Imposto %)</label>
                                    <div class="relative group">
                                        <input v-model="financeForm.tax_rate" type="number" step="0.01" class="w-full bg-white/10 border border-white/10 focus:border-white/40 focus:bg-white/20 text-white rounded-[1.5rem] py-5 pl-8 pr-16 font-black text-3xl outline-none transition-all">
                                        <span class="absolute right-8 top-1/2 -translate-y-1/2 text-white/40 font-black text-xl group-focus-within:text-white transition-colors">%</span>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <label class="text-[10px] font-black text-white/40 uppercase tracking-widest pl-2">Despesa Operacional por Unidade (R$)</label>
                                    <div class="relative group">
                                        <span class="absolute left-8 top-1/2 -translate-y-1/2 text-white/40 font-black text-xl group-focus-within:text-white transition-colors">R$</span>
                                        <input v-model="financeForm.operational_rate" type="number" step="0.01" class="w-full bg-white/10 border border-white/10 focus:border-white/40 focus:bg-white/20 text-white rounded-[1.5rem] py-5 pl-20 pr-8 font-black text-3xl outline-none transition-all">
                                    </div>
                                    <p class="text-[9px] text-white/30 font-bold leading-relaxed mt-4 uppercase tracking-wider italic">
                                        Custos fixos por pacote (embalagem, pessoal, operacional).
                                    </p>
                                </div>

                                <button type="submit" :disabled="financeForm.processing" class="w-full bg-white text-indigo-700 py-6 rounded-full font-black uppercase tracking-[0.3em] text-xs transition-all hover:bg-slate-50 active:scale-95 disabled:opacity-50 shadow-2xl shadow-indigo-900/40">
                                    {{ financeForm.processing ? 'Calculando...' : 'Atualizar Ecossistema' }}
                                </button>
                            </form>
                        </div>

                        <!-- Info Card -->
                        <div class="bg-white rounded-[2rem] p-8 border border-black/5 shadow-sm">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Informações Organizacionais</h4>
                            <div class="space-y-5">
                                <div class="flex justify-between items-center py-1 border-b border-black/[0.03]">
                                    <span class="text-[11px] text-slate-400 font-bold uppercase tracking-tight">Workspace</span>
                                    <span class="text-xs text-slate-800 font-black italic">{{ company.name }}</span>
                                </div>
                                <div class="flex justify-between items-center py-1 border-b border-black/[0.03]">
                                    <span class="text-[11px] text-slate-400 font-bold uppercase tracking-tight">Licença Alpha</span>
                                    <span class="text-[10px] bg-blue-50 text-blue-600 px-3 py-1 rounded-lg font-black tracking-widest">ENTERPRISE PRO</span>
                                </div>
                                <div class="flex justify-between items-center py-1 border-b border-black/[0.03]">
                                    <span class="text-[11px] text-slate-400 font-bold uppercase tracking-tight">Data Nodes</span>
                                    <span class="text-xs text-slate-800 font-black tracking-tighter">{{ credentials.length }} Accounts</span>
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
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    integrations: Object,    // Platform configs
    credentials: Array,      // Individual connected accounts
    company: Object          // Financial settings
});

// API Keys Form
const meliForm = useForm({
    app_id: props.integrations.mercadolibre?.app_id || '',
    client_secret: '' // Never show back
});

// Financial Rules Form
const financeForm = useForm({
    tax_rate: props.company.tax_rate || 0,
    operational_rate: props.company.operational_rate || 0
});

// Sincroniza o formulário se as props mudarem
watch(() => props.integrations, (newVal) => {
    if (newVal.mercadolibre) {
        meliForm.app_id = newVal.mercadolibre.app_id || '';
    }
}, { deep: true });

const saveKeys = (platform) => {
    meliForm.post(route('settings.update_keys', platform), {
        preserveScroll: true,
        onSuccess: () => {
            // Success feedback managed by AppLayout flash
        }
    });
};

const saveFinance = () => {
    financeForm.post(route('settings.update_finance'), {
        preserveScroll: true
    });
};

const toggleCredential = (cred) => {
    router.patch(route('marketplaces.accounts.toggle', { credential: cred.id }), {}, {
        preserveScroll: true
    });
};

const deleteCredential = (cred) => {
    if (confirm(`Deseja realmente remover a conta "${cred.account_nickname}"?\n\nIsso interromperá a sincronização de produtos e pedidos imediatamente.`)) {
        router.delete(route('marketplaces.accounts.destroy', { credential: cred.id }), {
            preserveScroll: true
        });
    }
};

const formatDate = (date) => {
    if (!date) return 'Nunca';
    return new Date(date).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit' });
};
</script>

<style scoped>
.shadow-premium {
    shadow: 0 20px 50px -12px rgba(0, 0, 0, 0.05);
}

.animate-bounce-horizontal {
    animation: bounce-h 1s infinite;
}

@keyframes bounce-h {
    0%, 100% { transform: translateX(0); }
    50% { transform: translateX(-4px); }
}

input::-webkit-outer-spin-button,
input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.tracking-tighter { letter-spacing: -0.06em; }
</style>
