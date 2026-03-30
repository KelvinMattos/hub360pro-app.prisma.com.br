<template>
    <AppLayout>
        <div class="p-8 max-w-7xl mx-auto min-h-screen">
            <!-- Header Section -->
            <div class="mb-12">
                <h1 class="text-5xl font-black text-white tracking-tighter">
                    Minha <span class="bg-gradient-to-r from-emerald-400 via-teal-500 to-cyan-600 bg-clip-text text-transparent">Conta</span>
                </h1>
                <p class="text-slate-400 mt-3 font-medium text-lg max-w-2xl">
                    Gerencie suas informações pessoais e segurança da conta.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
                <!-- Left: Profile Info -->
                <div class="lg:col-span-7 space-y-8">
                    <div class="bg-slate-800/40 border border-slate-700/50 p-8 rounded-[2.5rem] shadow-2xl backdrop-blur-sm">
                        <h3 class="text-white font-black text-xs uppercase tracking-[0.2em] mb-8 flex items-center gap-2">
                            <i class="fa-solid fa-user-gear text-emerald-400"></i> Informações do Perfil
                        </h3>

                        <form @submit.prevent="updateProfile" class="space-y-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">Nome Completo</label>
                                <input v-model="profileForm.name" type="text" class="w-full bg-slate-950 border border-slate-800 focus:border-emerald-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all">
                                <div v-if="profileForm.errors.name" class="text-red-500 text-[10px] font-black uppercase mt-1 ml-2">{{ profileForm.errors.name }}</div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">Endereço de E-mail</label>
                                <input v-model="profileForm.email" type="email" class="w-full bg-slate-950 border border-slate-800 focus:border-emerald-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all">
                                <div v-if="profileForm.errors.email" class="text-red-500 text-[10px] font-black uppercase mt-1 ml-2">{{ profileForm.errors.email }}</div>
                            </div>

                            <button 
                                type="submit" 
                                :disabled="profileForm.processing"
                                class="w-full bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white py-4 rounded-full font-black uppercase tracking-[0.2em] text-xs transition-all shadow-xl shadow-emerald-600/20 active:scale-95"
                            >
                                {{ profileForm.processing ? 'Atualizando...' : 'Salvar Alterações' }}
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Right: Security -->
                <div class="lg:col-span-5 space-y-8">
                    <div class="bg-slate-800/40 border border-slate-700/50 p-8 rounded-[2.5rem] shadow-2xl backdrop-blur-sm">
                        <h3 class="text-white font-black text-xs uppercase tracking-[0.2em] mb-8 flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-blue-400"></i> Segurança
                        </h3>

                        <form @submit.prevent="updatePassword" class="space-y-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">Senha Atual</label>
                                <input v-model="passwordForm.current_password" type="password" class="w-full bg-slate-950 border border-slate-800 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all">
                                <div v-if="passwordForm.errors.current_password" class="text-red-500 text-[10px] font-black uppercase mt-1 ml-2">{{ passwordForm.errors.current_password }}</div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">Nova Senha</label>
                                <input v-model="passwordForm.password" type="password" class="w-full bg-slate-950 border border-slate-800 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all">
                                <div v-if="passwordForm.errors.password" class="text-red-500 text-[10px] font-black uppercase mt-1 ml-2">{{ passwordForm.errors.password }}</div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-2">Confirmar Nova Senha</label>
                                <input v-model="passwordForm.password_confirmation" type="password" class="w-full bg-slate-950 border border-slate-800 focus:border-blue-500 text-white rounded-2xl py-4 px-6 font-bold outline-none transition-all">
                            </div>

                            <button 
                                type="submit" 
                                :disabled="passwordForm.processing"
                                class="w-full bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white py-4 rounded-full font-black uppercase tracking-[0.2em] text-xs transition-all shadow-xl shadow-blue-600/20 active:scale-95"
                            >
                                {{ passwordForm.processing ? 'Atualizando...' : 'Atualizar Senha' }}
                            </button>
                        </form>
                    </div>

                    <!-- Additional Info -->
                    <div class="bg-indigo-600/10 border border-indigo-500/20 p-8 rounded-[2.5rem]">
                        <div class="flex items-center gap-4 mb-4">
                            <i class="fa-solid fa-building text-indigo-400 text-2xl"></i>
                            <div>
                                <p class="text-xs font-black text-slate-500 uppercase tracking-widest">Empresa Vinculada</p>
                                <p class="text-lg font-black text-white uppercase italic">{{ user.company?.name || 'Não vinculada' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    user: Object
});

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updateProfile = () => {
    profileForm.put(route('settings.account.update'), {
        preserveScroll: true,
        onSuccess: () => profileForm.reset('password'),
    });
};

const updatePassword = () => {
    passwordForm.put(route('settings.account.password'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};
</script>
