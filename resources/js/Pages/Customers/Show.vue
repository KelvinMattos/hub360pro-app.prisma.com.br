<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex items-center gap-4 mb-8">
                <Link :href="route('customers.index')" class="w-11 h-11 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-900 hover:border-blue-400 transition-all shadow-sm">
                    <i class="fa-solid fa-chevron-left"></i>
                </Link>
                <div>
                    <h1 class="text-2xl lg:text-3xl font-extrabold text-slate-900 tracking-tight">{{ customer.customer_name || 'Cliente' }}</h1>
                    <p class="text-slate-400 font-black uppercase tracking-[0.15em] text-[10px] mt-1 font-mono">{{ formatDoc(customer.billing_doc_number) }}</p>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="kpi"><div class="kpi-l">LTV (Total Gasto)</div><div class="kpi-v text-emerald-600">{{ money(stats.total_spent) }}</div></div>
                <div class="kpi"><div class="kpi-l">Pedidos</div><div class="kpi-v">{{ n(stats.total_orders) }}</div></div>
                <div class="kpi"><div class="kpi-l">Ticket Médio</div><div class="kpi-v">{{ money(stats.avg_ticket) }}</div></div>
                <div class="kpi"><div class="kpi-l">Primeira Compra</div><div class="kpi-v text-base">{{ fmtDate(stats.first_purchase) }}</div></div>
                <div class="kpi" :class="recencyWarn ? 'kpi-warn' : ''">
                    <div class="kpi-l">Última Compra</div>
                    <div class="kpi-v text-base">{{ fmtDate(stats.last_purchase) }}</div>
                    <div v-if="stats.days_since_last_purchase !== null" class="kpi-s" :class="recencyWarn ? 'text-amber-600' : ''">
                        há {{ n(stats.days_since_last_purchase) }} dia(s)
                    </div>
                </div>
            </div>

            <!-- Perfil de consumo: canal + produtos -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-store mr-1.5"></i>Canais mais usados
                    </h3>
                    <BarList :items="por_canal" label-key="canal" value-key="total" count-key="pedidos" empty-text="Sem canal registrado." />
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">
                        <i class="fa-solid fa-bag-shopping mr-1.5"></i>Produtos comprados
                    </h3>
                    <div v-if="!produtos.length" class="text-slate-400 text-sm py-4 text-center">Sem itens de pedido vinculados (histórico anterior à Vendas por Item).</div>
                    <table v-else class="w-full text-sm">
                        <tbody>
                            <tr v-for="p in produtos" :key="p.sku" class="border-b border-slate-100">
                                <td class="py-2 pr-2 max-w-[200px]">
                                    <p class="font-semibold text-slate-700 truncate" :title="p.titulo">{{ p.titulo }}</p>
                                    <p class="text-[10px] text-slate-400 font-mono">{{ p.sku || '—' }}</p>
                                </td>
                                <td class="py-2 text-right font-mono text-slate-500 whitespace-nowrap">{{ n(p.unidades) }} un.</td>
                                <td class="py-2 pl-3 text-right font-mono font-semibold text-slate-700 whitespace-nowrap">{{ money(p.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Histórico de pedidos -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100">
                    <h3 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400">Histórico completo de pedidos</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-400 text-[10px] font-black uppercase tracking-wide">
                                <th class="th-l">Data</th>
                                <th class="th-l">Pedido</th>
                                <th class="th-l">Canal</th>
                                <th class="th-l">Status</th>
                                <th class="th-r">Total</th>
                                <th class="th-r">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="order in orders" :key="order.id" class="hover:bg-slate-50/60">
                                <td class="td-l">{{ fmtDate(order.date_created) }}</td>
                                <td class="td-l font-mono">{{ order.ml_order_id || order.id }}</td>
                                <td class="td-l">{{ order.selling_channel || '—' }}</td>
                                <td class="td-l"><span class="pill" :class="statusClass(order.status)">{{ statusLabel(order.status) }}</span></td>
                                <td class="td-r font-semibold">{{ money(order.total_amount) }}</td>
                                <td class="td-r">
                                    <Link :href="route('orders.show', order.id)" class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 inline-flex items-center justify-center hover:bg-blue-500 hover:text-white transition-all">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarList from '@/Components/Sales/BarList.vue';

const props = defineProps({
    customer: { type: Object, required: true },
    orders: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    por_canal: { type: Array, default: () => [] },
    produtos: { type: Array, default: () => [] },
});

const recencyWarn = computed(() => (props.stats.days_since_last_purchase ?? 0) > 90);

function n(v) { return (v ?? 0).toLocaleString('pt-BR'); }
function money(v) { return v == null ? 'R$ 0,00' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) { if (!d) return '—'; return new Date(d).toLocaleDateString('pt-BR'); }
function formatDoc(doc) {
    if (!doc) return '—';
    const d = String(doc);
    if (d.length === 11) return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    if (d.length === 14) return d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    return d;
}

const STATUS = {
    approved: ['Aprovado', 'pill-green'], paid: ['Pago', 'pill-green'], delivered: ['Entregue', 'pill-blue'],
    shipped: ['Enviado', 'pill-blue'], cancelled: ['Cancelado', 'pill-red'], pending: ['Pendente', 'pill-idle'],
};
function statusLabel(s) { return STATUS[s]?.[0] || (s || '—'); }
function statusClass(s) { return STATUS[s]?.[1] || 'pill-idle'; }
</script>

<style scoped>
.kpi { @apply bg-white border border-slate-200 rounded-2xl px-5 py-4 shadow-sm; }
.kpi-warn { @apply border-amber-200 bg-amber-50/40; }
.kpi-l { @apply text-slate-400 text-[11px] uppercase tracking-wide; }
.kpi-v { @apply font-mono text-xl font-bold text-slate-900 mt-1; }
.kpi-s { @apply text-slate-400 text-[10px] mt-0.5 font-mono; }
.th-l { @apply text-left font-semibold px-4 py-3 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-4 py-3 whitespace-nowrap; }
.td-l { @apply text-left px-4 py-2.5 whitespace-nowrap; }
.td-r { @apply text-right px-4 py-2.5 font-mono whitespace-nowrap; }
.pill { @apply text-[10px] font-bold px-2 py-0.5 rounded-full; }
.pill-green { @apply bg-emerald-100 text-emerald-700; }
.pill-blue { @apply bg-blue-100 text-blue-700; }
.pill-red { @apply bg-red-100 text-red-700; }
.pill-idle { @apply bg-slate-100 text-slate-500; }
</style>
