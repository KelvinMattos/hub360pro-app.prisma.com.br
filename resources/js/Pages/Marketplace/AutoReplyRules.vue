<template>
    <AppLayout title="Automação de Perguntas">
        <div class="p-8 max-w-6xl mx-auto">
            <!-- Header macOS Style -->
            <div class="flex justify-between items-end mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Auto <span class="bg-gradient-to-r from-purple-500 to-pink-600 bg-clip-text text-transparent">Reply</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Automatize respostas e aumente sua conversão 24/7.</p>
                </div>
                
                <button 
                    @click="openAddModal"
                    class="px-8 py-3 bg-slate-900 text-white rounded-2xl text-sm font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-xl shadow-slate-900/20 flex items-center gap-2"
                >
                    <i class="fa-solid fa-robot text-[10px]"></i> Criar Regra
                </button>
            </div>

            <!-- Rules Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div v-for="rule in rules" :key="rule.id" 
                    :class="rule.is_active ? 'border-purple-100 bg-white' : 'border-slate-100 bg-slate-50 opacity-70'"
                    class="border rounded-[2rem] p-8 shadow-sm hover:shadow-md transition-all relative group"
                >
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <span class="text-[10px] font-black uppercase text-purple-400 tracking-widest mb-1 block">Prioridade {{ rule.priority }}</span>
                            <h3 class="text-xl font-bold text-slate-900 tracking-tight">{{ rule.name }}</h3>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" :checked="rule.is_active" @change="toggleRule(rule.id)" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                        </label>
                    </div>

                    <div class="mb-6">
                        <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-2">Palavras-Chave</p>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="kw in rule.keywords.split(',')" :key="kw" class="bg-slate-100 text-slate-600 text-[10px] font-bold px-3 py-1 rounded-full border border-slate-200">
                                {{ kw.trim() }}
                            </span>
                        </div>
                    </div>

                    <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                        <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-reply"></i> Resposta Sugerida
                        </p>
                        <p class="text-xs text-slate-600 leading-relaxed font-medium italic">"{{ rule.reply_text }}"</p>
                    </div>

                    <div class="absolute top-4 right-4 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button @click="editRule(rule)" class="w-8 h-8 rounded-full bg-white shadow-sm border border-slate-100 flex items-center justify-center text-slate-400 hover:text-purple-600">
                            <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                        </button>
                        <button @click="deleteRule(rule.id)" class="w-8 h-8 rounded-full bg-white shadow-sm border border-slate-100 flex items-center justify-center text-slate-400 hover:text-red-500">
                            <i class="fa-solid fa-trash text-[10px]"></i>
                        </button>
                    </div>
                </div>

                <div v-if="rules.length === 0" class="col-span-full py-20 bg-slate-50 border-2 border-dashed border-slate-200 rounded-[2.5rem] flex flex-col items-center justify-center text-slate-400">
                    <i class="fa-solid fa-robot text-4xl mb-4"></i>
                    <p class="font-bold">Nenhuma regra de resposta automática criada.</p>
                    <button @click="openAddModal" class="mt-4 text-purple-600 font-black text-xs uppercase tracking-widest hover:underline">Começar Agora</button>
                </div>
            </div>
        </div>

        <!-- Add/Edit Modal macOS Style -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
            <div class="bg-white/90 backdrop-blur-xl w-full max-w-xl rounded-[2.5rem] shadow-2xl border border-white/20 overflow-hidden animate-in fade-in zoom-in duration-300">
                <div class="p-10">
                    <div class="flex justify-between items-center mb-10">
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter">{{ form.id ? 'Editar Regra' : 'Nova Regra' }}</h3>
                        <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-times text-xl"></i></button>
                    </div>

                    <form @submit.prevent="submitForm">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Nome da Regra</label>
                                <input v-model="form.name" type="text" placeholder="Ex: Tem Estoque?" required class="w-full bg-slate-100 border-none rounded-2xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-purple-500 transition-all">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Palavras-Chave (separadas por vírgula)</label>
                                <input v-model="form.keywords" type="text" placeholder="Ex: estoque, disponível, pronta entrega" required class="w-full bg-slate-100 border-none rounded-2xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-purple-500 transition-all">
                                <p class="mt-2 text-[8px] text-slate-400 font-bold uppercase">Se a pergunta contiver qualquer uma destas palavras, o robô responderá.</p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Texto da Resposta</label>
                                <textarea v-model="form.reply_text" rows="3" placeholder="Olá! Sim, temos o produto em estoque pronto para envio imediato. Aguardamos sua compra!" required class="w-full bg-slate-100 border-none rounded-2xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-purple-500 transition-all resize-none"></textarea>
                            </div>

                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Prioridade</label>
                                    <input v-model="form.priority" type="number" required class="w-full bg-slate-100 border-none rounded-2xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-purple-500 transition-all">
                                </div>
                                <div class="flex items-end pb-4">
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <div class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center border-2 border-transparent peer-checked:border-purple-500 transition-all">
                                            <input type="checkbox" v-model="form.is_active" class="sr-only">
                                            <i v-if="form.is_active" class="fa-solid fa-check text-purple-600 text-xs"></i>
                                        </div>
                                        <span class="text-[10px] font-black uppercase text-slate-500 group-hover:text-purple-600 transition-colors">Ativar Regra</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-12 flex gap-4">
                            <button type="button" @click="showModal = false" class="flex-1 py-4 text-slate-500 font-black text-xs uppercase tracking-widest hover:bg-slate-50 rounded-2xl transition-all">Cancelar</button>
                            <button type="submit" class="flex-2 py-4 px-10 bg-slate-900 text-white font-black text-xs uppercase tracking-widest rounded-2xl shadow-xl shadow-slate-900/20 transition-all active:scale-95">Salvar Regra</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    rules: Array
});

const showModal = ref(false);
const form = reactive({
    id: null,
    name: '',
    keywords: '',
    reply_text: '',
    priority: 0,
    is_active: true
});

const openAddModal = () => {
    form.id = null;
    form.name = '';
    form.keywords = '';
    form.reply_text = '';
    form.priority = 0;
    form.is_active = true;
    showModal.value = true;
};

const editRule = (rule) => {
    Object.assign(form, rule);
    showModal.value = true;
};

const submitForm = () => {
    router.post(route('marketplaces.auto-reply.store'), form, {
        onSuccess: () => {
            showModal.value = false;
        }
    });
};

const deleteRule = (id) => {
    if (confirm('Deseja excluir esta regra de resposta automática?')) {
        router.delete(route('marketplaces.auto-reply.destroy', id));
    }
};

const toggleRule = (id) => {
    router.post(route('marketplaces.auto-reply.toggle', id));
};
</script>
