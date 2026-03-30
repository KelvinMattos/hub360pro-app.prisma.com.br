<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto">
            <div class="mb-10 flex justify-between items-end">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Central de <span class="bg-gradient-to-r from-amber-400 to-orange-500 bg-clip-text text-transparent">Perguntas</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium text-lg italic">Responda seus clientes em tempo real em todos os canais.</p>
                </div>
                <button @click="syncQuestions" class="bg-amber-500 hover:bg-amber-600 text-slate-900 px-8 py-3 rounded-2xl font-black text-xs uppercase tracking-widest transition-all shadow-lg active:scale-95">
                    <i class="fa-solid fa-sync mr-2"></i> Sincronizar
                </button>
            </div>

            <div class="grid grid-cols-1 gap-6">
                <div v-for="q in questions.data" :key="q.id" class="bg-white shadow-premium border border-slate-200 rounded-3xl p-8 hover:border-amber-500/30 transition-all group shadow-xl">
                    <div class="flex justify-between items-start mb-6">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-[#FFE600] rounded-2xl flex items-center justify-center text-black shadow-lg shadow-[#FFE600]/10">
                                <i class="fa-solid fa-handshake text-xl"></i>
                            </div>
                            <div>
                                <p class="text-slate-900 font-black text-sm uppercase tracking-tight">{{ q.buyer_username }}</p>
                                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-[0.2em] mt-1">{{ q.credential?.account_nickname || 'Mercado Livre' }}</p>
                            </div>
                        </div>
                        <span class="text-[10px] text-slate-500 font-black uppercase tracking-[0.2em]">{{ formatDate(q.received_at) }}</span>
                    </div>

                    <div class="bg-black/20 p-6 rounded-2xl mb-6 italic text-slate-300 border-l-4 border-amber-500">
                        "{{ q.question_text }}"
                    </div>

                    <div class="flex gap-4">
                        <input v-model="answers[q.id]" placeholder="Digite sua resposta premium..." class="flex-1 bg-black/20 border border-slate-700 rounded-2xl px-6 py-4 text-slate-900 text-sm focus:ring-amber-500 transition-all">
                        <button @click="answerQuestion(q)" class="bg-white text-black px-8 py-1 rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-amber-500 hover:text-slate-900 transition-all shadow-xl active:scale-95">
                            Responder
                        </button>
                    </div>
                </div>

                <div v-if="questions.data.length === 0" class="p-20 text-center bg-[#1E293B] rounded-3xl border border-slate-700/50">
                    <i class="fa-solid fa-comments text-6xl text-slate-800 mb-6"></i>
                    <p class="font-black uppercase tracking-[0.2em] text-xs text-slate-600">Nenhuma pergunta pendente.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    questions: Object
});

const answers = ref({});

const formatDate = (date) => {
    return new Date(date).toLocaleString('pt-BR');
};

const syncQuestions = () => {
    router.post(route('marketplaces.questions.sync'));
};

const answerQuestion = (question) => {
    if (!answers.value[question.id]) return;
    router.post(route('marketplaces.questions.answer', question.id), {
        text: answers.value[question.id]
    });
};
</script>
