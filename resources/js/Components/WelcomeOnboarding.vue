<template>
    <div class="bg-white/80 backdrop-blur-xl border border-black/[0.05] rounded-[40px] p-8 md:p-16 shadow-2xl relative overflow-hidden">
        <!-- Background Decor -->
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-blue-500/5 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-indigo-500/5 rounded-full blur-3xl"></div>

        <div class="max-w-3xl mx-auto text-center mb-16 relative z-10">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-600 rounded-full text-[10px] font-black uppercase tracking-widest mb-6">
                <i class="fa-solid fa-sparkles"></i> Bem-vindo à Evolução
            </div>
            <h2 class="text-4xl md:text-5xl font-bold text-slate-900 tracking-tight mb-6">Sua central de inteligência está pronta.</h2>
            <p class="text-slate-500 text-lg font-medium leading-relaxed">Siga estes 3 passos simples para ativar o poder total do Hub360 PRO na sua operação e ver seus lucros reais.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative z-10">
            <!-- Passo 1 -->
            <div :class="['group relative p-8 rounded-[32px] border transition-all duration-500 transform hover:-translate-y-2', integrationsCount > 0 ? 'bg-emerald-50/50 border-emerald-100 hover:shadow-emerald-100' : 'border-black/[0.03] bg-black/[0.01] hover:bg-white hover:shadow-2xl']">
                <div :class="['w-16 h-16 rounded-2xl flex items-center justify-center text-2xl font-black mb-8 shadow-lg transition-transform group-hover:scale-110', integrationsCount > 0 ? 'bg-emerald-500 text-white shadow-emerald-500/20' : 'bg-blue-600 text-white shadow-blue-600/20']">
                    <i v-if="integrationsCount > 0" class="fa-solid fa-check"></i>
                    <span v-else>1</span>
                </div>
                <h3 class="text-xl font-bold text-slate-900 mb-4">Conexão Meli</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-10">Conecte sua primeira conta do Mercado Livre para importar anúncios, pedidos e reputação de forma automática.</p>
                
                <div v-if="integrationsCount > 0" class="flex items-center gap-2 text-emerald-600 font-bold text-xs">
                    <i class="fa-solid fa-circle-check"></i> Conta Vinculada com Sucesso
                </div>
                <Link v-else :href="route('ml.connect')" class="inline-flex items-center gap-2 bg-slate-900 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-blue-600 transition-all shadow-lg shadow-black/5">
                    Conectar Agora <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </Link>
            </div>

            <!-- Passo 2 -->
            <div :class="['group relative p-8 rounded-[32px] border transition-all duration-500 transform hover:-translate-y-2', integrationsCount > 0 ? 'border-black/[0.03] bg-white shadow-xl' : 'border-black/[0.03] bg-black/[0.01] opacity-60 hover:opacity-100']">
                <div :class="['w-16 h-16 rounded-2xl flex items-center justify-center text-2xl font-black mb-8 transition-colors', integrationsCount > 0 ? 'bg-blue-600 text-white shadow-blue-600/20 shadow-lg' : 'bg-slate-100 text-slate-400']">2</div>
                <h3 class="text-xl font-bold text-slate-900 mb-4">Sincronização</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-10">Nossa IA irá processar seus anúncios e histórico de vendas para gerar os primeiros cálculos de margem.</p>
                
                <button v-if="integrationsCount > 0" @click="syncData" :disabled="syncing" class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/10">
                    <i :class="['fa-solid fa-rotate', syncing ? 'animate-spin' : '']"></i>
                    {{ syncing ? 'Sincronizando...' : 'Sincronizar Agora' }}
                </button>
                <div v-else class="flex items-center gap-2 text-slate-400 font-bold text-xs italic">
                    <span class="w-1.5 h-1.5 bg-slate-300 rounded-full animate-pulse"></span>
                    Aguardando Passo 1
                </div>
            </div>

            <!-- Passo 3 -->
            <div :class="['group relative p-8 rounded-[32px] border border-black/[0.03] bg-black/[0.01] hover:bg-white hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2', integrationsCount > 0 ? 'opacity-100' : 'opacity-60 hover:opacity-100']">
                <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-2xl flex items-center justify-center text-2xl font-black mb-8 group-hover:bg-blue-100 group-hover:text-blue-600 transition-colors">3</div>
                <h3 class="text-xl font-bold text-slate-900 mb-4">Custos Operacionais</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-10">Cadastre seus custos fixos e taxas extras para que o sistema mostre seu lucro real no "DRE Turbo".</p>
                <Link :href="route('financial.fixed-expenses.index')" :class="['font-bold text-xs transition-colors', integrationsCount > 0 ? 'text-blue-600 hover:text-blue-700 underline' : 'text-slate-400 hover:text-blue-600']">
                    Configurar Custos Agora
                </Link>
            </div>
        </div>

        <!-- Footer Note -->
        <div class="mt-16 text-center text-slate-400 text-[10px] font-bold uppercase tracking-widest pt-8 border-t border-black/[0.03]">
            Hub360 Evolution • Tecnologia Prisma IA
        </div>
    </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    integrationsCount: {
        type: Number,
        default: 0
    }
});

const syncing = ref(false);
const syncData = () => {
    syncing.value = true;
    router.post(route('dashboard.sync'), {}, {
        onFinish: () => syncing.value = false
    });
};
</script>
