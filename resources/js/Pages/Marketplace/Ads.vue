<template>
    <AppLayout title="Ads & Marketing Intelligence">
        <div class="p-8 max-w-7xl mx-auto min-h-screen">
            <!-- Header macOS Style -->
            <div class="mb-12 flex justify-between items-end animate-in fade-in slide-in-from-top-4 duration-700">
                <div>
                    <h1 class="text-5xl font-black text-slate-900 tracking-tightest">
                        Ads <span class="bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent">Intelligence</span>
                    </h1>
                    <p class="text-slate-400 mt-3 font-semibold text-lg tracking-tight">Otimização e ROAS em nível avançado.</p>
                </div>
                
                <div class="flex gap-4">
                    <div class="bg-white/80 backdrop-blur-xl border border-white/40 px-6 py-4 rounded-[2rem] flex items-center gap-4 shadow-premium transition-all hover:scale-105 active:scale-95 cursor-pointer">
                        <i class="fa-solid fa-bullhorn text-orange-500"></i>
                        <span class="text-slate-600 font-black text-[10px] uppercase tracking-[0.2em]">{{ metrics.ads_count }} Pedidos Publicitários</span>
                    </div>
                </div>
            </div>

            <!-- Metrics Highlight -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-12">
                <!-- ROAS Card -->
                <div class="bg-white/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-white/60 shadow-premium relative group">
                    <p class="text-slate-400 text-[11px] font-black uppercase tracking-[0.2em] mb-4">ROAS (Retorno)</p>
                    <div class="flex items-end gap-3">
                        <h3 class="text-5xl font-black text-slate-900 tracking-tighter">{{ metrics.roas }}x</h3>
                        <span class="text-emerald-500 font-black text-xs mb-1 bg-emerald-500/10 px-2 py-1 rounded-lg" v-if="metrics.roas > 10">Excelente</span>
                    </div>
                    <div class="mt-4 w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500" :style="{ width: Math.min(100, (metrics.roas / 20) * 100) + '%' }"></div>
                    </div>
                </div>

                <!-- ACOS Card -->
                <div class="bg-white/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-white/60 shadow-premium relative group">
                    <p class="text-slate-400 text-[11px] font-black uppercase tracking-[0.2em] mb-4">ACOS (Custo)</p>
                    <div class="flex items-end gap-3">
                        <h3 class="text-5xl font-black tracking-tighter" :class="metrics.acos > 15 ? 'text-orange-500' : 'text-slate-900'">{{ metrics.acos }}%</h3>
                    </div>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-4 tracking-widest italic">Quanto menor, melhor.</p>
                </div>

                <!-- Ad Spend -->
                <div class="bg-white/40 backdrop-blur-2xl p-8 rounded-[2.5rem] border border-white/60 shadow-premium relative group">
                    <p class="text-slate-400 text-[11px] font-black uppercase tracking-[0.2em] mb-4">Investimento</p>
                    <div class="flex items-end gap-3">
                        <h3 class="text-4xl font-black text-slate-900 tracking-tighter">R$ {{ metrics.total_spend.toLocaleString() }}</h3>
                    </div>
                </div>

                <!-- Ad Sales -->
                <div class="bg-slate-900 p-8 rounded-[2.5rem] border border-slate-800 shadow-premium relative overflow-hidden group">
                    <div class="absolute -bottom-4 -right-4 opacity-10 group-hover:scale-125 transition-transform rotate-12">
                        <i class="fa-solid fa-rocket text-8xl text-white"></i>
                    </div>
                    <p class="text-white/40 text-[11px] font-black uppercase tracking-[0.2em] mb-4">Vendas Ads</p>
                    <div class="flex items-end gap-3">
                        <h3 class="text-4xl font-black text-white tracking-tighter">R$ {{ metrics.total_sales.toLocaleString() }}</h3>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">
                <!-- Main Chart -->
                <div class="lg:col-span-2 bg-white/40 backdrop-blur-2xl p-10 rounded-[3rem] border border-white/60 shadow-premium">
                    <h4 class="text-slate-900 font-black uppercase tracking-[0.2em] text-[10px] mb-12">Investimento vs Retorno (7 dias)</h4>
                    <div class="h-80 w-full flex items-end justify-between gap-6 px-4">
                        <div v-for="day in chart_data" :key="day.date" class="flex-1 flex flex-col items-center gap-6 group">
                            <div class="w-full flex items-end gap-1.5 relative h-full">
                                <!-- Spend Bar -->
                                <div 
                                    class="flex-1 bg-slate-300/40 rounded-t-xl hover:bg-orange-500 transition-colors cursor-help"
                                    :style="{ height: Math.max(10, (day.spend / (Math.max(...chart_data.map(d => d.spend)) || 1)) * 100) + '%' }"
                                ></div>
                                <!-- Sales Bar -->
                                <div 
                                    class="flex-1 bg-gradient-to-t from-slate-900 to-slate-700 rounded-t-xl hover:brightness-125 transition-all cursor-help"
                                    :style="{ height: Math.max(10, (day.sales / (Math.max(...chart_data.map(d => d.sales)) || 1)) * 100) + '%' }"
                                ></div>
                            </div>
                            <span class="text-slate-400 text-[10px] font-black uppercase tracking-widest">{{ day.date }}</span>
                        </div>
                    </div>
                </div>

                <!-- Top Performing Listings -->
                <div class="bg-white/60 backdrop-blur-3xl p-8 rounded-[2.5rem] border border-white/80 shadow-premium flex flex-col">
                    <h4 class="text-slate-900 font-black uppercase tracking-[0.2em] text-[10px] mb-8">Top Performance Ads</h4>
                    <div class="space-y-6 flex-1 overflow-y-auto">
                        <div v-for="prod in top_products" :key="prod.product_external_id" class="flex items-center gap-4 group cursor-pointer hover:bg-slate-50/50 p-3 rounded-2xl transition-all">
                            <div class="w-12 h-12 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 font-black text-[10px]">ML</div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-black text-slate-900 truncate tracking-tight">{{ prod.product_external_id }}</p>
                                <div class="flex gap-4 mt-1">
                                    <span class="text-[9px] font-bold text-emerald-600">ROAS: {{ (prod.sales / (prod.spend || 1)).toFixed(1) }}x</span>
                                    <span class="text-[9px] font-bold text-slate-400">ACOS: {{ ((prod.spend / (prod.sales || 1)) * 100).toFixed(1) }}%</span>
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
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    metrics: Object,
    chart_data: Array,
    top_products: Array
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
