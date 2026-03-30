<template>
    <div class="min-h-screen relative flex items-center justify-center p-6 font-sans overflow-hidden bg-[#F5F5F7]">
        <!-- Background Decor (Lighter macOS Style) -->
        <div class="absolute inset-0 z-0">
            <div class="absolute top-[-10%] right-[-10%] w-[50%] h-[50%] bg-blue-200/20 rounded-full blur-[120px]"></div>
            <div class="absolute bottom-[-10%] left-[-10%] w-[40%] h-[40%] bg-indigo-200/20 rounded-full blur-[100px]"></div>
        </div>

        <div class="w-full max-w-[1100px] grid grid-cols-1 lg:grid-cols-2 bg-white/80 backdrop-blur-3xl rounded-[48px] shadow-[0_32px_80px_-20px_rgba(0,0,0,0.08)] border border-white overflow-hidden relative z-10">
            
            <!-- Left Side: Branding & Welcome (Light Version) -->
            <div class="hidden lg:flex flex-col justify-between p-16 bg-white relative border-r border-black/[0.03]">
                <div class="absolute inset-0 opacity-[0.03] pointer-events-none">
                    <svg width="100%" height="100%"><pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M 40 0 L 0 0 0 40" fill="none" stroke="black" stroke-width="1"/></pattern><rect width="100%" height="100%" fill="url(#grid)" /></svg>
                </div>
                
                <div class="relative z-10">
                    <div class="flex items-center gap-3 mb-16">
                        <div class="w-12 h-12 bg-gradient-to-tr from-blue-600 to-blue-400 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-500/20 animate-float">
                            <i class="fa-solid fa-layer-group text-white text-xl"></i>
                        </div>
                        <span class="text-2xl font-black tracking-tighter text-slate-900">Hub360<span class="font-light text-slate-400">Evolution</span></span>
                    </div>
                    
                    <h2 class="text-5xl font-bold leading-tight mb-8 tracking-tight text-slate-900">
                        A inteligência que sua <span class="text-blue-600">operação</span> merece.
                    </h2>
                    <p class="text-slate-500 text-lg font-medium leading-relaxed max-w-sm">
                        Controle total de Marketplaces, Financeiro e ERP em uma única interface impecável e profissional.
                    </p>
                </div>

                <div class="relative z-10">
                    <div class="flex items-center gap-4 p-5 bg-black/[0.02] rounded-3xl border border-black/[0.04]">
                        <div class="flex -space-x-3">
                            <div v-for="i in 3" :key="i" class="w-10 h-10 rounded-full border-2 border-white bg-slate-200 overflow-hidden shadow-sm">
                                <img :src="'https://i.pravatar.cc/100?u=' + (i + 10)" alt="user">
                            </div>
                        </div>
                        <p class="text-[11px] font-bold text-slate-500 uppercase tracking-widest">Junte-se a +500 <span class="text-blue-600">sellers PRO</span> hoje.</p>
                    </div>
                </div>
            </div>

            <!-- Right Side: Login Form -->
            <div class="p-8 md:p-20 flex flex-col justify-center bg-white/40">
                <div class="mb-14">
                    <h3 class="text-3xl font-bold text-slate-900 mb-3 tracking-tight">Bem-vindo de volta</h3>
                    <p class="text-slate-500 font-medium">Insira suas credenciais corporativas.</p>
                </div>

                <form @submit.prevent="submit" class="space-y-8">
                    <div>
                        <label class="mac-label">E-mail Corporativo</label>
                        <div class="group relative">
                            <input 
                                v-model="form.email" 
                                type="email" 
                                required
                                class="mac-input w-full pl-12"
                                placeholder="exemplo@empresa.com"
                            >
                            <i class="fa-solid fa-envelope absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                        </div>
                        <p v-if="form.errors.email" class="text-red-500 text-[11px] mt-2 font-bold ml-1 uppercase tracking-wider">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <div class="flex justify-between items-end mb-2 ml-1">
                            <label class="mac-label !mb-0">Senha de Acesso</label>
                            <a href="#" class="text-[10px] text-blue-600 hover:text-blue-700 font-black uppercase tracking-widest transition-colors">Esqueceu?</a>
                        </div>
                        <div class="group relative">
                            <input 
                                v-model="form.password" 
                                type="password" 
                                required
                                class="mac-input w-full pl-12"
                                placeholder="••••••••"
                            >
                            <i class="fa-solid fa-lock absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                        </div>
                    </div>

                    <div class="flex items-center px-1">
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <div class="relative flex items-center">
                                <input type="checkbox" v-model="form.remember" class="peer h-5 w-5 cursor-pointer appearance-none rounded-lg border border-black/[0.1] transition-all checked:bg-blue-600 checked:border-blue-600">
                                <i class="fa-solid fa-check absolute text-[10px] text-white opacity-0 peer-checked:opacity-100 left-1.5 transition-opacity"></i>
                            </div>
                            <span class="text-sm text-slate-600 group-hover:text-slate-900 transition-colors font-medium">Mantenha-me conectado</span>
                        </label>
                    </div>

                    <button 
                        type="submit" 
                        :disabled="form.processing"
                        class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-bold py-5 rounded-2xl shadow-xl shadow-blue-600/20 transition-all transform active:scale-[0.98] flex items-center justify-center gap-3"
                    >
                        <span v-if="form.processing" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                        <template v-else>
                            Acessar Painel <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i>
                        </template>
                    </button>
                </form>

                <div class="mt-20 text-center lg:text-left flex items-center justify-between">
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">
                        &copy; 2026 Prisma IA • Hub360 Evolution
                    </p>
                    <div class="flex gap-4">
                        <i class="fa-brands fa-apple text-slate-300 text-lg"></i>
                        <i class="fa-solid fa-shield-halved text-slate-300 text-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.animate-float {
    animation: float 6s ease-in-out infinite;
}
@keyframes float {
    0% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
    100% { transform: translateY(0px); }
}
</style>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>
