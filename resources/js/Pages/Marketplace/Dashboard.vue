<template>
    <AppLayout title="Marketplace Dashboard">
        <div class="p-8 max-w-7xl mx-auto min-h-screen">
            <!-- Header macOS Style -->
            <div class="mb-12 flex justify-between items-end animate-in fade-in slide-in-from-top-4 duration-700">
                <div>
                    <h1 class="text-5xl font-black text-slate-900 tracking-tightest">
                        Command <span class="bg-gradient-to-r from-blue-600 via-indigo-500 to-purple-600 bg-clip-text text-transparent">Center</span>
                    </h1>
                    <p class="text-slate-400 mt-3 font-semibold text-lg tracking-tight"> Inteligência Omnichannel em Tempo Real.</p>
                </div>
                <div class="flex gap-4">
                    <div class="bg-white/80 backdrop-blur-xl border border-white/40 px-6 py-4 rounded-[2rem] flex items-center gap-4 shadow-premium transition-all hover:scale-105 active:scale-95 cursor-pointer">
                        <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full animate-pulse shadow-[0_0_15px_rgba(16,185,129,0.5)]"></div>
                        <span class="text-slate-600 font-black text-[10px] uppercase tracking-[0.2em]">Sincronização Ativa</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
                
                <!-- Main Stats Column (3 cols) -->
                <div class="lg:col-span-3 space-y-8">
                    
                    <!-- Top KPIs - Glassmorphism -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Revenue Today -->
                        <div class="bg-white/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-white/60 shadow-premium relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-8 opacity-10 group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-chart-line text-6xl text-blue-600"></i>
                            </div>
                            <p class="text-slate-400 text-[11px] font-black uppercase tracking-[0.2em] mb-4">Vendas Hoje</p>
                            <div class="flex items-end gap-3">
                                <h3 class="text-4xl font-black text-slate-900 tracking-tighter">R$ {{ stats.sales_today.toLocaleString() }}</h3>
                                <span class="text-emerald-500 text-xs font-black bg-emerald-500/10 px-2 py-1 rounded-lg mb-1">+{{ stats.growth_percent }}%</span>
                            </div>
                        </div>

                        <!-- Profit Today (Mercado Turbo Style) -->
                        <div class="bg-emerald-50/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-emerald-100/60 shadow-premium relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-8 opacity-10 group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-sack-dollar text-6xl text-emerald-600"></i>
                            </div>
                            <p class="text-emerald-600/60 text-[11px] font-black uppercase tracking-[0.2em] mb-4">Lucro Líquido</p>
                            <div class="flex items-end gap-3">
                                <h3 class="text-4xl font-black text-emerald-700 tracking-tighter">R$ {{ stats.profit_today.toLocaleString() }}</h3>
                                <div class="h-6 w-12 bg-emerald-500/20 rounded-full flex items-center justify-center mb-2">
                                    <span class="text-[9px] font-black text-emerald-600">{{ Math.round((stats.profit_today / stats.sales_today) * 100) }}%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Pending Questions (Alert) -->
                        <div class="bg-indigo-50/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-indigo-100/60 shadow-premium relative overflow-hidden group hover:border-indigo-300 transition-all cursor-pointer">
                            <div class="absolute top-0 right-0 p-8 opacity-10 group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-comments text-6xl text-indigo-600"></i>
                            </div>
                            <p class="text-indigo-600/60 text-[11px] font-black uppercase tracking-[0.2em] mb-4">SAC Pendente</p>
                            <div class="flex items-end gap-3">
                                <h3 class="text-4xl font-black text-indigo-900 tracking-tighter">{{ stats.pending_questions }}</h3>
                                <span class="text-indigo-500 text-[10px] font-black uppercase mb-1">Perguntas</span>
                            </div>
                            <div class="mt-4 flex items-center gap-2">
                                <div class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-ping"></div>
                                <Link :href="route('marketplaces.auto-reply.index')" class="text-[9px] font-black text-indigo-600 uppercase tracking-widest hover:underline">Configurar Robô AI</Link>
                            </div>
                        </div>
                    </div>

                    <!-- Main Sales Chart -->
                    <div class="bg-white/40 backdrop-blur-2xl p-10 rounded-[3rem] border border-white/60 shadow-premium">
                        <div class="flex justify-between items-center mb-12">
                            <h4 class="text-slate-900 font-black uppercase tracking-[0.2em] text-xs">Performance Semanal (Faturamento)</h4>
                            <div class="flex gap-2">
                                <button class="px-4 py-1.5 bg-white rounded-full text-[10px] font-bold text-slate-500 shadow-sm border border-slate-100">7 Dias</button>
                                <button class="px-4 py-1.5 text-[10px] font-bold text-slate-400">30 Dias</button>
                            </div>
                        </div>
                        
                        <div class="h-80 w-full flex items-end justify-between gap-6 px-4">
                            <div v-for="day in chart_data" :key="day.date" class="flex-1 flex flex-col items-center gap-6 group">
                                <div class="w-full bg-slate-200/40 rounded-3xl relative h-full flex flex-col justify-end overflow-hidden border border-white/40">
                                    <div 
                                        class="w-full bg-gradient-to-t from-blue-600 via-indigo-500 to-cyan-400 rounded-2xl transition-all duration-1000 ease-out group-hover:brightness-110 shadow-[0_0_20px_rgba(37,99,235,0.2)]" 
                                        :style="{ height: Math.max(10, (day.total / (Math.max(...chart_data.map(d => d.total)) || 1)) * 100) + '%' }"
                                    ></div>
                                    <div class="absolute inset-x-0 bottom-full mb-4 opacity-0 group-hover:opacity-100 transition-all scale-75 group-hover:scale-100">
                                        <div class="bg-slate-900 text-white text-[10px] font-black px-4 py-2 rounded-2xl shadow-2xl whitespace-nowrap text-center">
                                            R$ {{ day.total.toLocaleString() }}
                                        </div>
                                    </div>
                                </div>
                                <span class="text-slate-400 text-[10px] font-black uppercase tracking-widest">{{ day.date }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar (Reputation & Heatmap) -->
                <div class="space-y-8 animate-in fade-in slide-in-from-right-4 duration-1000">
                    <!-- Reputation Index -->
                    <div class="bg-white/60 backdrop-blur-3xl p-8 rounded-[2.5rem] border border-white/80 shadow-premium">
                        <h4 class="text-slate-900 font-black uppercase tracking-[0.2em] text-[10px] mb-8">Hub Seller Index</h4>
                        
                        <div class="space-y-8">
                            <div v-for="acc in accounts" :key="acc.id" class="group cursor-pointer">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-2xl bg-yellow-400 flex items-center justify-center text-slate-900 text-lg shadow-lg shadow-yellow-400/20 group-hover:scale-110 transition-transform">
                                            <i class="fa-solid fa-handshake-simple"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-black text-slate-900 leading-tight">{{ acc.account_nickname || 'Meli Account' }}</p>
                                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-tighter">{{ acc.power_seller_status || 'Regular User' }}</span>
                                        </div>
                                    </div>
                                    <div 
                                        class="w-3 h-3 rounded-full shadow-[0_0_10px_rgba(16,185,129,0.5)]"
                                        :class="acc.reputation_level === 'green' ? 'bg-emerald-500' : 'bg-orange-500'"
                                    ></div>
                                </div>
                                
                                <!-- Mini Metrics -->
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="bg-slate-50/50 p-2 rounded-xl border border-slate-100/50">
                                        <p class="text-[8px] font-bold text-slate-400 uppercase mb-1">Cancelamentos</p>
                                        <p class="text-xs font-black" :class="acc.cancellation_rate > 2 ? 'text-red-500' : 'text-slate-900'">{{ acc.cancellation_rate }}%</p>
                                    </div>
                                    <div class="bg-slate-50/50 p-2 rounded-xl border border-slate-100/50">
                                        <p class="text-[8px] font-bold text-slate-400 uppercase mb-1">Reclamações</p>
                                        <p class="text-xs font-black" :class="acc.claims_rate > 1 ? 'text-red-500' : 'text-slate-900'">{{ acc.claims_rate }}%</p>
                                    </div>
                                </div>
                            </div>

                            <div v-if="accounts.length === 0" class="py-10 text-center opacity-50">
                                <p class="text-xs font-bold text-slate-400 italic">Nenhuma conta conectada para análise.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tools -->
                    <div class="bg-gradient-to-br from-indigo-600 to-purple-700 p-8 rounded-[2.5rem] text-white shadow-premium relative overflow-hidden">
                        <div class="relative z-10">
                            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] opacity-60 mb-6">Próximos Passos</h4>
                            <div class="space-y-4">
                                <Link :href="route('marketplaces.listings.bulk')" class="flex items-center justify-between p-4 bg-white/10 rounded-2xl hover:bg-white/20 transition-all group">
                                    <span class="text-xs font-bold">Nivelador de Anúncios</span>
                                    <i class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-1 transition-transform"></i>
                                </Link>
                                <Link :href="route('financial.fixed-expenses.index')" class="flex items-center justify-between p-4 bg-white/10 rounded-2xl hover:bg-white/20 transition-all group">
                                    <span class="text-xs font-bold">Ajustar DRE (Custos Fixos)</span>
                                    <i class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-1 transition-transform"></i>
                                </Link>
                            </div>
                        </div>
                        <!-- Background decoration -->
                        <div class="absolute -bottom-10 -right-10 w-40 h-40 bg-white/10 rounded-full blur-3xl"></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: Object,
    chart_data: Array,
    accounts: Array
});
</script>

<style scoped>
.shadow-premium {
    box-shadow: 0 10px 40px -10px rgba(0,0,0,0.05);
}
.tracking-tightest {
    letter-spacing: -0.05em;
}
</style>
