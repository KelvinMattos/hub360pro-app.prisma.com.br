<template>
    <AppLayout title="Custos Fixos (DRE)">
        <div class="p-8 max-w-6xl mx-auto">
            <!-- Header macOS Style -->
            <div class="flex justify-between items-end mb-10">
                <div>
                    <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">
                        Custos <span class="bg-gradient-to-r from-blue-500 to-indigo-600 bg-clip-text text-transparent">Fixos</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">Gerencie sua operação e garanta um DRE preciso.</p>
                </div>
                
                <button 
                    @click="showAddModal = true"
                    class="px-8 py-3 bg-slate-900 text-white rounded-2xl text-sm font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-xl shadow-slate-900/20 flex items-center gap-2"
                >
                    <i class="fa-solid fa-plus text-[10px]"></i> Nova Despesa
                </button>
            </div>

            <!-- Stats/Summary bar -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white/70 backdrop-blur-md border border-white/40 p-6 rounded-3xl shadow-sm">
                    <p class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1">Mês Atual</p>
                    <p class="text-2xl font-black text-slate-900">R$ {{ totalMonth }}</p>
                </div>
                <div class="bg-white/70 backdrop-blur-md border border-white/40 p-6 rounded-3xl shadow-sm">
                    <p class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1">Quantidade</p>
                    <p class="text-2xl font-black text-slate-900">{{ expenses.length }} itens</p>
                </div>
                <div class="bg-blue-50/50 backdrop-blur-md border border-blue-100/40 p-6 rounded-3xl shadow-sm">
                    <p class="text-[10px] font-black uppercase text-blue-400 tracking-widest mb-1">DRE Impacto</p>
                    <p class="text-2xl font-black text-blue-600">Alta Precisão</p>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm">
                <table class="w-full text-left order-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[10px] font-black uppercase text-slate-500 tracking-widest">
                            <th class="p-6">Data</th>
                            <th class="p-6">Descrição / Categoria</th>
                            <th class="p-6 text-right">Valor</th>
                            <th class="p-6 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="exp in expenses" :key="exp.id" class="hover:bg-slate-50/50 transition-all">
                            <td class="p-6">
                                <span class="bg-slate-100 text-slate-600 text-[10px] font-black px-2 py-1 rounded-lg uppercase">
                                    {{ formatDate(exp.expense_date) }}
                                </span>
                            </td>
                            <td class="p-6">
                                <p class="text-slate-900 font-bold text-sm">{{ exp.description }}</p>
                                <span class="text-[10px] text-slate-400 font-bold uppercase">{{ exp.category || 'Geral' }}</span>
                            </td>
                            <td class="p-6 text-right">
                                <p class="text-slate-900 font-mono font-black">R$ {{ exp.amount.toFixed(2) }}</p>
                            </td>
                            <td class="p-6 text-right">
                                <button @click="deleteExpense(exp.id)" class="text-red-500 hover:text-red-600 font-black text-xs uppercase tracking-widest">Remover</button>
                            </td>
                        </tr>
                        <tr v-if="expenses.length === 0">
                            <td colspan="4" class="p-20 text-center text-slate-400">
                                <i class="fa-solid fa-receipt text-4xl mb-4"></i>
                                <p class="font-bold">Nenhum custo fixo registrado.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add Modal macOS Style -->
        <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
            <div class="bg-white/90 backdrop-blur-xl w-full max-w-lg rounded-[2.5rem] shadow-2xl border border-white/20 overflow-hidden animate-in fade-in zoom-in duration-300">
                <div class="p-10">
                    <div class="flex justify-between items-center mb-10">
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter">Novo Custo</h3>
                        <button @click="showAddModal = false" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-times text-xl"></i></button>
                    </div>

                    <form @submit.prevent="submitForm">
                        <div class="space-y-6">
                            <div>
                                <label class="mac-label">Descrição da Despesa</label>
                                <input v-model="form.description" type="text" placeholder="Ex: Aluguel do Galpão" required class="mac-input w-full">
                            </div>

                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="mac-label">Valor (R$)</label>
                                    <input v-model="form.amount" type="number" step="0.01" required class="mac-input w-full">
                                </div>
                                <div>
                                    <label class="mac-label">Data Competência</label>
                                    <input v-model="form.expense_date" type="date" required class="mac-input w-full">
                                </div>
                            </div>

                            <div>
                                <label class="mac-label">Categoria / Classificação</label>
                                <select v-model="form.category" class="mac-input w-full appearance-none">
                                    <option value="Operacional">Operacional</option>
                                    <option value="Marketing">Marketing</option>
                                    <option value="RH">Recursos Humanos (Salários)</option>
                                    <option value="Logistica">Logística</option>
                                    <option value="Software">Softwares / SaaS</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-12 flex gap-4">
                            <button type="button" @click="showAddModal = false" class="flex-1 py-4 text-slate-500 font-black text-xs uppercase tracking-widest hover:bg-slate-50 rounded-2xl transition-all">Cancelar</button>
                            <button type="submit" class="flex-2 py-4 px-10 bg-slate-900 text-white font-black text-xs uppercase tracking-widest rounded-2xl shadow-xl shadow-slate-900/20 transition-all active:scale-95">Salvar Dados</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    expenses: Array
});

const showAddModal = ref(false);
const form = reactive({
    description: '',
    amount: '',
    category: 'Operacional',
    expense_date: new Date().toISOString().split('T')[0]
});

const totalMonth = computed(() => {
    return props.expenses.reduce((acc, exp) => acc + parseFloat(exp.amount), 0).toFixed(2);
});

const submitForm = () => {
    router.post(route('financial.fixed-expenses.store'), form, {
        onSuccess: () => {
            showAddModal.value = false;
            form.description = '';
            form.amount = '';
        }
    });
};

const deleteExpense = (id) => {
    if (confirm('Deseja remover esta despesa fixa?')) {
        router.delete(route('financial.fixed-expenses.destroy', id));
    }
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('pt-BR');
};
</script>
