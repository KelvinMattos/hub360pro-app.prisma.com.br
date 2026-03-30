<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto min-h-screen">
            <!-- Header Section -->
            <div class="mb-12 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-5xl font-black text-white tracking-tighter">
                        Contas <span class="bg-gradient-to-r from-blue-400 via-indigo-500 to-purple-600 bg-clip-text text-transparent">Conectadas</span>
                    </h1>
                    <p class="text-slate-400 mt-3 font-medium text-lg max-w-2xl">
                        Centralize a gestão de todos os seus canais de venda. Conecte múltiplas contas e monitore a saúde de suas integrações em tempo real.
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="bg-slate-800/50 backdrop-blur-xl border border-slate-700/50 px-6 py-3 rounded-2xl flex items-center gap-3">
                        <div class="w-3 h-3 bg-emerald-500 rounded-full animate-pulse shadow-[0_0_15px_rgba(16,185,129,0.5)]"></div>
                        <span class="text-xs font-black text-slate-300 uppercase tracking-widest">{{ credentials.length }} Contas Ativas</span>
                    </div>
                </div>
            </div>

            <!-- Marketplace Grid -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-8 mb-12">
                <!-- Platform Section: Mercado Livre -->
                <div class="xl:col-span-1 space-y-6">
                    <div class="flex items-center justify-between mb-4 px-2">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#FFE600]/10 flex items-center justify-center border border-[#FFE600]/20">
                                <i class="fa-solid fa-handshake text-[#FFE600] text-lg"></i>
                            </div>
                            <h2 class="text-xl font-black text-white">Mercado Livre</h2>
                        </div>
                        <button @click="openConfig('mercadolivre')" class="text-[10px] font-black text-blue-400 hover:text-blue-300 uppercase tracking-widest transition-colors flex items-center gap-2">
                            <i class="fa-solid fa-gear"></i> Configurar API
                        </button>
                    </div>

                    <!-- Connect Button (If keys configured) -->
                    <button 
                        v-if="configs.mercadolivre"
                        @click="connectAccount('mercadolivre')"
                        class="w-full bg-gradient-to-r from-[#FFE600] to-yellow-500 hover:from-yellow-400 hover:to-yellow-600 text-slate-900 py-4 rounded-2xl font-black uppercase tracking-widest text-[11px] transition-all shadow-xl shadow-yellow-500/10 active:scale-[0.98] flex items-center justify-center gap-3 group"
                    >
                        <i class="fa-solid fa-plus-circle text-lg group-hover:rotate-90 transition-transform"></i>
                        Conectar Nova Conta ML
                    </button>

                    <!-- Accounts List -->
                    <div v-if="getAccounts('mercadolivre').length > 0" class="space-y-4">
                        <div v-for="cred in getAccounts('mercadolivre')" :key="cred.id" class="bg-slate-800/40 border border-slate-700/50 p-5 rounded-3xl hover:border-slate-600 transition-all group relative overflow-hidden">
                            <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                            <div class="relative z-10">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full border-2 border-slate-700 p-0.5">
                                            <img :src="`https://ui-avatars.com/api/?name=${cred.account_nickname || 'ML'}&background=1e293b&color=fff`" class="w-full h-full rounded-full object-cover">
                                        </div>
                                        <div>
                                            <p class="text-white font-black text-sm">{{ cred.account_nickname }}</p>
                                            <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest">ID: {{ cred.external_user_id }}</p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <button @click="toggleCredential(cred)" class="w-8 h-8 rounded-lg bg-slate-900/50 text-slate-400 hover:text-blue-400 flex items-center justify-center transition-colors">
                                            <i class="fa-solid fa-power-off text-[10px]"></i>
                                        </button>
                                        <button @click="deleteCredential(cred)" class="w-8 h-8 rounded-lg bg-slate-900/50 text-slate-400 hover:text-red-400 flex items-center justify-center transition-colors">
                                            <i class="fa-solid fa-trash text-[10px]"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span :class="cred.is_active ? 'text-emerald-400' : 'text-slate-500'" class="text-[9px] font-black uppercase tracking-tighter">
                                            {{ cred.is_active ? 'Sincronizando' : 'Pausado' }}
                                        </span>
                                    </div>
                                    <div class="text-[9px] text-slate-500 font-bold italic">
                                        Expira em: {{ formatDate(cred.token_expires_at) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="bg-slate-800/20 border-2 border-dashed border-slate-700/50 p-12 rounded-3xl flex flex-col items-center text-center">
                        <i class="fa-solid fa-link-slash text-slate-600 text-3xl mb-4"></i>
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-widest">Nenhuma conta ML ativa</p>
                    </div>
                </div>

                <!-- Platform Section: Shopee -->
                <div class="xl:col-span-1 space-y-6">
                    <div class="flex items-center justify-between mb-4 px-2">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#EE4D2D]/10 flex items-center justify-center border border-[#EE4D2D]/20">
                                <i class="fa-solid fa-bag-shopping text-[#EE4D2D] text-lg"></i>
                            </div>
                            <h2 class="text-xl font-black text-white">Shopee</h2>
                        </div>
                        <span class="text-[9px] bg-slate-800 text-slate-500 px-2 py-0.5 rounded-full font-black tracking-widest uppercase">Breve</span>
                    </div>
                     <div class="bg-slate-800/10 border-2 border-dashed border-slate-800 p-20 rounded-3xl flex flex-col items-center opacity-40 grayscale">
                        <i class="fa-solid fa-clock text-slate-700 text-3xl mb-4"></i>
                        <p class="text-slate-700 text-[10px] font-black uppercase tracking-[0.3em]">Em Desenvolvimento</p>
                    </div>
                </div>

                <!-- Platform Section: Amazon -->
                <div class="xl:col-span-1 space-y-6">
                    <div class="flex items-center justify-between mb-4 px-2">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center border border-white/10">
                                <i class="fa-brands fa-amazon text-white text-lg"></i>
                            </div>
                            <h2 class="text-xl font-black text-white">Amazon</h2>
                        </div>
                        <span class="text-[9px] bg-slate-800 text-slate-500 px-2 py-0.5 rounded-full font-black tracking-widest uppercase">Breve</span>
                    </div>
                    <div class="bg-slate-800/10 border-2 border-dashed border-slate-800 p-20 rounded-3xl flex flex-col items-center opacity-40 grayscale">
                        <i class="fa-solid fa-clock text-slate-700 text-3xl mb-4"></i>
                        <p class="text-slate-700 text-[10px] font-black uppercase tracking-[0.3em]">Em Desenvolvimento</p>
                    </div>
                </div>
            </div>

            <!-- Config Modal -->
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-300">
                    <div class="p-8 pb-0 flex justify-between items-start">
                        <div>
                            <h3 class="text-2xl font-black text-white uppercase tracking-tight">API <span class="text-blue-500">{{ activePlatformName }}</span></h3>
                            <p class="text-slate-500 text-xs font-bold mt-1 tracking-widest uppercase">Configure suas credenciais de parceiro</p>
                        </div>
                        <button @click="showModal = false" class="text-slate-500 hover:text-white transition-colors">
                            <i class="fa-solid fa-times text-xl"></i>
                        </button>
                    </div>

                    <form @submit.prevent="saveConfig" class="p-8 space-y-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">App ID / Client ID</label>
                            <input v-model="form.app_id" type="text" placeholder="Insira o ID do App" class="w-full bg-slate-950 border border-slate-800 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all placeholder:text-slate-700">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">Client Secret</label>
                            <input v-model="form.client_secret" type="password" placeholder="••••••••••••••••" class="w-full bg-slate-950 border border-slate-800 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all placeholder:text-slate-700">
                        </div>

                        <div class="bg-blue-500/5 border border-blue-500/10 rounded-2xl p-4 flex gap-4">
                            <i class="fa-solid fa-info-circle text-blue-500 mt-1"></i>
                            <p class="text-[10px] text-slate-400 font-medium leading-relaxed italic">
                                Estas chaves são enviadas apenas uma vez e armazenadas de forma segura. Elas permitirão que você conecte múltiplas contas deste marketplace.
                            </p>
                        </div>

                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="w-full bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white py-4 rounded-full font-black uppercase tracking-[0.2em] text-xs transition-all shadow-xl shadow-blue-600/20 active:scale-95"
                        >
                            {{ form.processing ? 'Salvando...' : 'Salvar e Validar' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    credentials: Array,
    configs: Object,
    company: Object
});

const showModal = ref(false);
const activePlatform = ref(null);

const form = useForm({
    app_id: '',
    client_secret: ''
});

const activePlatformName = computed(() => {
    switch (activePlatform.value) {
        case 'mercadolivre': return 'Mercado Livre';
        case 'shopee': return 'Shopee';
        case 'amazon': return 'Amazon';
        default: return '';
    }
});

const getAccounts = (platform) => {
    return props.credentials.filter(c => c.platform === platform);
};

const openConfig = (platform) => {
    activePlatform.value = platform;
    form.app_id = props.configs[platform]?.app_id || '';
    form.client_secret = ''; // Never show secret back
    showModal.value = true;
};

const saveConfig = () => {
    form.post(route('settings.update_keys', activePlatform.value), {
        onSuccess: () => {
            showModal.value = false;
        }
    });
};

const connectAccount = (platform) => {
    if (platform === 'mercadolivre') {
        // Redireciona via Inertia para a URL de autorização
        router.visit(route('ml.connect'));
    }
};

const toggleCredential = (cred) => {
    router.patch(route('marketplaces.accounts.toggle', { credential: cred.id }), {}, {
        preserveScroll: true
    });
};

const deleteCredential = (cred) => {
    if (confirm(`Deseja realmente remover a conta "${cred.account_nickname}"? Esta ação não pode ser desfeita.`)) {
        router.delete(route('marketplaces.accounts.destroy', { credential: cred.id }), {
            preserveScroll: true
        });
    }
};

const formatDate = (date) => {
    if (!date) return 'Nunca';
    return new Date(date).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
};
</script>

<style scoped>
.tracking-tighter { letter-spacing: -0.05em; }
</style>
