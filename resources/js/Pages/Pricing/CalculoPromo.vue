<template>
    <AppLayout>
        <div class="p-6 lg:p-8 max-w-[1400px] mx-auto">
            <!-- Header -->
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-tags text-emerald-500"></i>
                        Cálculo <span class="bg-gradient-to-r from-emerald-500 to-teal-600 bg-clip-text text-transparent">Promo</span>
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Substitui a planilha de PROCV. Importe os modelos que o sistema exporta, calcule as margens de todos os canais no navegador e exporte a planilha de saída.
                    </p>
                </div>
                <div class="text-xs text-slate-400 font-mono bg-white/70 border border-slate-200 rounded-xl px-3 py-2">
                    {{ fileSummary }}
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 mb-6 border-b border-slate-200">
                <button v-for="t in tabs" :key="t.id" @click="activeTab = t.id"
                    :class="['px-4 py-2.5 text-sm font-semibold transition-colors relative -mb-px border-b-2',
                        activeTab === t.id ? 'text-emerald-600 border-emerald-500' : 'text-slate-400 border-transparent hover:text-slate-600']">
                    <span class="font-mono text-[11px] mr-2 opacity-60">0{{ t.n }}</span>{{ t.label }}
                </button>
            </div>

            <!-- ============ 01 · CONFIG ============ -->
            <section v-show="activeTab === 'config'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Parâmetros globais -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Parâmetros globais</h2>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-4">
                                <label class="text-sm text-slate-600">Imposto (%)</label>
                                <input v-model.number="cfg.imposto" type="number" step="0.01" class="cfg-input">
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <label class="text-sm text-slate-600">MC — margem de contribuição (%)</label>
                                <input v-model.number="cfg.mc" type="number" step="0.01" class="cfg-input">
                            </div>
                        </div>
                        <p class="text-xs text-slate-400 mt-3">Aplicados sobre o preço de venda em todos os canais, junto com a comissão específica de cada um.</p>

                        <h3 class="text-sm font-bold text-slate-700 mt-6 mb-3">Regra padrão — PV Promo Sugerido</h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-4">
                                <label class="text-sm text-slate-600">Desconto sobre PV atual (%)</label>
                                <input v-model.number="cfg.descAtualDefault" type="number" step="0.01" class="cfg-input">
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <label class="text-sm text-slate-600">Desconto sobre ponto de equilíbrio (%)</label>
                                <input v-model.number="cfg.descEquilDefault" type="number" step="0.01" class="cfg-input">
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <label class="text-sm text-slate-600">Terminação do preço (R$)</label>
                                <input v-model.number="cfg.rounding" type="number" step="0.01" class="cfg-input">
                            </div>
                        </div>
                        <p class="text-xs text-slate-400 mt-3">
                            PV Promo = MAIOR( arredondar(PV atual × (1−desc.) para baixo, terminando em ,90) ; Ponto de Equilíbrio × (1−desc. equilíbrio) ).
                            São os valores padrão — cada canal pode ter os seus na tabela abaixo, e campanhas podem sobrepor por período.
                        </p>
                    </div>

                    <!-- Campanhas -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-4">Campanhas salvas</h2>
                        <div class="flex items-center gap-3 mb-3">
                            <label class="text-sm text-slate-600 whitespace-nowrap">Campanha ativa</label>
                            <select v-model="activeCampaign" @change="applyCampaign(activeCampaign)" class="cfg-select flex-1">
                                <option value="__default__">Padrão (config. por canal)</option>
                                <option v-for="(v, name) in campaigns" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </div>
                        <p class="text-xs text-slate-400 mb-4">
                            Uma campanha guarda um conjunto alternativo de "desconto sobre PV atual" e "desconto sobre equilíbrio" por canal — útil para Black Friday, aniversário etc.
                            Ajuste a tabela de canais abaixo, depois salve.
                        </p>
                        <div class="flex items-center gap-3 mb-3">
                            <label class="text-sm text-slate-600 whitespace-nowrap">Nova campanha</label>
                            <input v-model="newCampaignName" type="text" placeholder="ex: Black Friday 2026" class="cfg-input flex-1 !w-auto">
                        </div>
                        <div class="flex gap-2">
                            <button @click="saveCampaign" class="btn-primary text-sm">Salvar campanha atual</button>
                            <button @click="deleteCampaign" class="btn-ghost text-sm">Excluir selecionada</button>
                        </div>
                        <div v-if="campaignMsg" :class="['mt-3 text-xs px-3 py-2 rounded-lg', campaignMsg.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600']">
                            {{ campaignMsg.text }}
                        </div>
                    </div>
                </div>

                <!-- Tabela de canais -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">Canais</h2>
                    <p class="text-xs text-slate-400 mb-4">
                        Comissão, markup da meta de lucro e regra de promoção — tudo editável por canal.
                        Itens com <span class="warn-badge">atenção</span> são pontos identificados na planilha original que vale confirmar.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-200">
                                    <th class="py-2 pr-3 font-semibold">Canal</th>
                                    <th class="py-2 px-3 font-semibold">Origem do PV atual</th>
                                    <th class="py-2 px-3 font-semibold">Comissão %</th>
                                    <th class="py-2 px-3 font-semibold">Taxa fixa por faixa</th>
                                    <th class="py-2 px-3 font-semibold">Markup meta %</th>
                                    <th class="py-2 px-3 font-semibold">Desc. s/ PV %</th>
                                    <th class="py-2 px-3 font-semibold">Desc. s/ equil. %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(ch, i) in channels" :key="ch.id" class="border-b border-slate-100 hover:bg-slate-50/60">
                                    <td class="py-2 pr-3 font-medium text-slate-700 whitespace-nowrap">
                                        {{ ch.label }}
                                        <span v-if="ch.warn" class="warn-badge" :title="ch.warn">atenção</span>
                                    </td>
                                    <td class="py-2 px-3">
                                        <select v-if="ch.origem === 'pvatual'" v-model="ch.col" class="cfg-select">
                                            <option v-for="c in PV_ATUAL_COLS" :key="c" :value="c">{{ c }}</option>
                                        </select>
                                        <span v-else class="text-slate-400">aba {{ ch.origem.toUpperCase() }}</span>
                                    </td>
                                    <td class="py-2 px-3"><input v-model.number="ch.comissao" type="number" step="0.01" class="tbl-input"></td>
                                    <td class="py-2 px-3">
                                        <span v-if="ch.temFaixa === 'none'" class="text-slate-300">— nenhuma —</span>
                                        <button v-else @click="openFaixa(ch)" class="btn-ghost !py-1 !px-2 text-xs">
                                            editar ({{ ch.temFaixa === 'ml' ? 'ML' : 'Shopee' }})
                                        </button>
                                    </td>
                                    <td class="py-2 px-3"><input v-model.number="ch.markup" type="number" step="0.001" class="tbl-input"></td>
                                    <td class="py-2 px-3"><input v-model.number="ch.descAtual" type="number" step="0.01" class="tbl-input"></td>
                                    <td class="py-2 px-3"><input v-model.number="ch.descEquil" type="number" step="0.01" class="tbl-input"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ============ 02 · IMPORT ============ -->
            <section v-show="activeTab === 'import'" class="space-y-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">Arquivos de entrada</h2>
                    <p class="text-xs text-slate-400 mb-4">
                        Use os mesmos modelos que o sistema já exporta hoje. Aceita <code class="k">.xlsx</code> e <code class="k">.csv</code>.
                        Os marcados <span class="req-badge">obrigatório</span> são necessários para calcular; os demais enriquecem canais específicos.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <label v-for="spec in FILES_SPEC" :key="spec.key"
                            :class="['dz', data[spec.key] ? 'dz-filled' : '']">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-sm text-slate-700">{{ spec.name }}</span>
                                <span :class="spec.req ? 'text-amber-500' : 'text-slate-400'" class="text-[10px] font-mono">
                                    {{ spec.req ? 'obrigatório' : 'opcional' }}
                                </span>
                            </div>
                            <div :class="['text-xs mt-2 font-mono', data[spec.key] ? 'text-emerald-600' : 'text-slate-400']">
                                {{ data[spec.key] ? data[spec.key].length.toLocaleString('pt-BR') + ' linhas carregadas' : 'clique para escolher o arquivo' }}
                            </div>
                            <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onFile(spec, $event)">
                        </label>
                    </div>
                </div>
                <div v-if="importError" class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl">{{ importError }}</div>
                <div class="flex gap-3">
                    <button @click="calcular" :disabled="!canCalc" class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-gears mr-2"></i>Calcular
                    </button>
                    <button @click="limpar" class="btn-ghost">Limpar tudo</button>
                </div>
            </section>

            <!-- ============ 03 · RESULTS ============ -->
            <section v-show="activeTab === 'results'" class="space-y-4">
                <div v-if="!results.length" class="bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-3 rounded-xl">
                    Ainda não há cálculo. Importe os arquivos e clique em <b>Calcular</b> na aba Importar.
                </div>
                <template v-else>
                    <div class="flex flex-wrap gap-4">
                        <div class="stat"><div class="stat-v">{{ stats.total.toLocaleString('pt-BR') }}</div><div class="stat-l">SKUs calculados</div></div>
                        <div class="stat"><div class="stat-v">{{ stats.promosAbaixo.toLocaleString('pt-BR') }}</div><div class="stat-l">com promo abaixo do PV atual</div></div>
                        <div class="stat"><div class="stat-v">{{ stats.semCusto.toLocaleString('pt-BR') }}</div><div class="stat-l">sem custo encontrado</div></div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <input v-model="search" type="text" placeholder="Buscar SKU ou produto..." class="cfg-input !w-64 font-mono">
                        <select v-model="channelFilter" class="cfg-select">
                            <option value="">Todos os canais (visão resumida)</option>
                            <option v-for="ch in channels" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                        </select>
                        <div class="flex-1"></div>
                        <button @click="exportXlsx" class="btn-primary text-sm"><i class="fa-solid fa-file-export mr-2"></i>Exportar planilha (.xlsx)</button>
                    </div>
                    <div class="overflow-auto max-h-[64vh] border border-slate-200 rounded-2xl bg-white">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-slate-50 z-10">
                                <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                                    <template v-if="!channelFilter">
                                        <th class="th-l">SKU</th><th class="th-l">Produto</th><th class="th-r">Custo</th>
                                        <th v-for="ch in channels" :key="ch.id" class="th-r">{{ ch.label }}<br>PV Promo</th>
                                    </template>
                                    <template v-else>
                                        <th class="th-l">SKU</th><th class="th-l">Produto</th><th class="th-r">Custo</th><th class="th-r">PV Atual</th>
                                        <th class="th-r">Ponto Equil.</th><th class="th-r">Meta Lucro</th><th class="th-r">PV Promo Sug.</th>
                                        <th class="th-r">Result. Promo</th><th class="th-r">% Desc.</th><th class="th-r">Promo &lt; PV?</th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in visibleRows" :key="r.sku" class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <template v-if="!channelFilter">
                                        <td class="td-l">{{ r.sku }}</td><td class="td-l">{{ r.produto }}</td><td class="td-r">{{ money(r.custo) }}</td>
                                        <td v-for="ch in channels" :key="ch.id" class="td-r">{{ money(r.canais[ch.id].promoSugerido) }}</td>
                                    </template>
                                    <template v-else>
                                        <td class="td-l">{{ r.sku }}</td><td class="td-l">{{ r.produto }}</td>
                                        <td class="td-r">{{ money(r.custo) }}</td>
                                        <td class="td-r">{{ money(r.canais[channelFilter].pvAtual) }}</td>
                                        <td class="td-r">{{ money(r.canais[channelFilter].pontoEquilibrio) }}</td>
                                        <td class="td-r">{{ money(r.canais[channelFilter].metaLucro) }}</td>
                                        <td class="td-r">{{ money(r.canais[channelFilter].promoSugerido) }}</td>
                                        <td class="td-r">{{ money(r.canais[channelFilter].resultadoPromo) }}</td>
                                        <td class="td-r">{{ pct(r.canais[channelFilter].percDesc) }}</td>
                                        <td :class="['td-r font-bold', r.canais[channelFilter].promoMenor === 'SIM' ? 'text-red-500' : 'text-emerald-600']">
                                            {{ r.canais[channelFilter].promoMenor || '—' }}
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="filteredRows.length > 500" class="text-xs text-slate-400">Exibindo os primeiros 500 de {{ filteredRows.length.toLocaleString('pt-BR') }} resultados. Refine a busca ou exporte a planilha completa.</p>
                </template>
            </section>

            <!-- ============ 04 · BUY BOX ============ -->
            <section v-show="activeTab === 'buybox'" class="space-y-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">Buy Box</h2>
                    <p class="text-xs text-slate-400 mb-4">
                        Acompanhe o preço do concorrente que está ganhando a buy box em Netshoes e Amazon, e veja se o <code class="k">PV Promo Sugerido</code>
                        seria suficiente para retomar a venda. O SKU externo de cada marketplace é diferente do SKU interno (o mesmo de Custo / PV Atual) —
                        por isso cada canal faz o cruzamento antes de puxar custo, estoque e sugestão de preço.
                    </p>
                    <div class="flex items-center gap-3">
                        <label class="text-sm text-slate-600">Canal</label>
                        <select v-model="bbChannel" class="cfg-select w-56">
                            <option value="netshoes">Netshoes</option>
                            <option value="amazon">Amazon</option>
                        </select>
                    </div>
                    <p class="text-xs text-slate-400 mt-3">{{ bbCrosswalkStatus }}</p>
                </div>

                <div v-show="bbChannel === 'amazon'" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">De-Para SKU Amazon <span class="req-badge">opcional</span></h2>
                    <p class="text-xs text-slate-400 mb-4">
                        O arquivo original não tinha tabela de cruzamento para Amazon (só Netshoes, via "NETSHOES - TABELA PORTAL").
                        Se a Amazon usa um SKU/ASIN próprio, importe aqui um de-para com as colunas <code class="k">SKU Amazon</code> e <code class="k">SKU Interno</code>.
                        Sem esse arquivo, assumimos que o código informado já é o SKU interno.
                    </p>
                    <label :class="['dz max-w-md', data.amazonCrosswalk ? 'dz-filled' : '']">
                        <span class="font-semibold text-sm text-slate-700">DE-PARA AMAZON</span>
                        <div :class="['text-xs mt-2 font-mono', data.amazonCrosswalk ? 'text-emerald-600' : 'text-slate-400']">
                            {{ data.amazonCrosswalk ? data.amazonCrosswalk.length.toLocaleString('pt-BR') + ' linhas carregadas' : 'csv/xlsx com colunas "SKU Amazon" e "SKU Interno"' }}
                        </div>
                        <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onAmazonCrosswalk($event)">
                    </label>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-xs font-black uppercase tracking-[0.15em] text-slate-400 mb-1">Acompanhamento manual</h2>
                    <p class="text-xs text-slate-400 mb-4">O preço do concorrente que ganha a buy box não existe em planilha — você confere no marketplace e digita aqui. O resto é calculado.</p>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <input v-model="bbNewSku" type="text" placeholder="SKU externo (ex: I6E-8021-044)" class="cfg-input !w-56 font-mono">
                        <input v-model.number="bbNewPreco" type="number" step="0.01" placeholder="Preço do concorrente" class="cfg-input !w-44 font-mono">
                        <button @click="bbAddRow" class="btn-primary text-sm">Adicionar</button>
                        <label class="btn-ghost text-sm cursor-pointer">
                            Importar lista (.csv)
                            <input type="file" accept=".csv,.xlsx" class="hidden" @change="bbImport($event)">
                        </label>
                    </div>
                    <div class="overflow-auto max-h-[52vh] border border-slate-200 rounded-2xl">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-slate-50 z-10">
                                <tr class="text-slate-400 text-[10px] uppercase tracking-wide">
                                    <th class="th-l">SKU Externo</th><th class="th-l">SKU Interno</th><th class="th-r">Tempo Estoque</th>
                                    <th class="th-r">Estoque</th><th class="th-r">CMV</th><th class="th-r">PV Atual</th><th class="th-r">Preço Concorrente</th>
                                    <th class="th-r">PV Sugestão</th><th class="th-r">Ganhamos?</th><th class="th-r">Result. Pós Sug.</th><th class="th-r"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in bbRows" :key="i" class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td class="td-l">{{ r.skuExterno }}</td>
                                    <td class="td-l">{{ r.skuInterno }}<span v-if="!r.encontrado" class="warn-badge">não achado</span></td>
                                    <td class="td-r">{{ r.tempoEstoque || '—' }}</td>
                                    <td class="td-r">{{ r.estoque ?? '—' }}</td>
                                    <td class="td-r">{{ money(r.custo) }}</td>
                                    <td class="td-r">{{ money(r.pvAtual) }}</td>
                                    <td class="td-r">{{ money(r.precoConcorrente) }}</td>
                                    <td class="td-r">{{ money(r.pvSugestao) }}</td>
                                    <td :class="['td-r font-bold', r.ganhamos === 'SIM' ? 'text-emerald-600' : r.ganhamos === 'NÃO' ? 'text-red-500' : '']">{{ r.ganhamos || '—' }}</td>
                                    <td class="td-r">{{ money(r.resultadoPosSugestao) }}</td>
                                    <td class="td-r"><button @click="bbDel(i)" class="text-slate-300 hover:text-red-500"><i class="fa-solid fa-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <button @click="bbExport" class="btn-primary text-sm"><i class="fa-solid fa-file-export mr-2"></i>Exportar Buy Box (.xlsx)</button>
            </section>

            <p class="text-xs text-slate-400 mt-8">
                O motor de cálculo roda inteiramente no seu navegador — nada é enviado para o servidor. Os arquivos ficam na memória da aba enquanto ela estiver aberta.
            </p>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    defaults: { type: Object, required: true },
    launchedDates: { type: Object, default: () => ({}) },
});

/* ---------------- constantes de modelo ---------------- */
const PV_ATUAL_COLS = ['Site', 'Shopee', 'Mercado Livre', 'Centauro', 'Via Varejo', 'Magalu', 'Dafiti', 'Amazon', 'Netshoes'];
const ML_TIER = [{ max: 12.5, mode: 'half' }, { max: 29, fee: 6.25 }, { max: 50, fee: 6.5 }, { max: 79, fee: 6.75 }, { max: Infinity, fee: 0 }];
const SHOPEE_TIER = [{ max: 79.99, fee: 4 }, { max: 99.99, fee: 16 }, { max: 199.99, fee: 20 }, { max: Infinity, fee: 26 }];

const FILES_SPEC = [
    { key: 'pvatual', name: 'PV ATUAL', req: true },
    { key: 'custo', name: 'CUSTO', req: true },
    { key: 'centauro', name: 'CENTAURO', req: false },
    { key: 'renner', name: 'RENNER', req: false },
    { key: 'netshoesPortal', name: 'NETSHOES - TABELA PORTAL', req: false },
    { key: 'pvdepor', name: 'PV DE-POR', req: false },
];

const tabs = [
    { id: 'config', n: 1, label: 'Configurações' },
    { id: 'import', n: 2, label: 'Importar' },
    { id: 'results', n: 3, label: 'Resultados' },
    { id: 'buybox', n: 4, label: 'Buy Box' },
];

/* ---------------- estado reativo ---------------- */
const activeTab = ref('config');
const cfg = reactive({
    imposto: props.defaults.imposto,
    mc: props.defaults.mc,
    descAtualDefault: props.defaults.descAtualDefault,
    descEquilDefault: props.defaults.descEquilDefault,
    rounding: props.defaults.rounding,
});
const channels = reactive(props.defaults.channels.map(c => ({ ...c })));

const data = reactive({ custo: null, pvatual: null, pvdepor: null, netshoesPortal: null, renner: null, centauro: null, amazonCrosswalk: null });
const results = ref([]);
const resultsBySku = new Map();

const search = ref('');
const channelFilter = ref('');
const importError = ref('');

const campaigns = reactive({});
const activeCampaign = ref('__default__');
const newCampaignName = ref('');
const campaignMsg = ref(null);

const buybox = reactive({ netshoes: [], amazon: [] });
const bbChannel = ref('netshoes');
const bbNewSku = ref('');
const bbNewPreco = ref(null);
const bbTick = ref(0); // força recomputo da tabela buybox

const CAMP_KEY = 'calculo-promo-campanhas';
const BB_KEY = 'calculo-promo-buybox';

/* ---------------- helpers de leitura ---------------- */
function getXLSX() {
    if (typeof window.XLSX === 'undefined') {
        throw new Error('Biblioteca de planilhas (SheetJS) não carregada. Recarregue a página.');
    }
    return window.XLSX;
}

function toNum(v) {
    if (v == null || v === '') return null;
    if (typeof v === 'number') return v;
    const n = parseFloat(String(v).replace(/\./g, '').replace(',', '.'));
    return isNaN(n) ? null : n;
}

function findHeaderRow(rows, mustHave) {
    for (let r = 0; r < Math.min(rows.length, 10); r++) {
        const row = rows[r].map(c => (c == null ? '' : String(c).trim()));
        if (mustHave.some(h => row.includes(h))) return r;
    }
    return 0;
}

function sheetToObjects(ws, expectedCols) {
    const XLSX = getXLSX();
    const rows = XLSX.utils.sheet_to_json(ws, { header: 1, raw: true, defval: null });
    if (!rows.length) return [];
    const headerRowIdx = findHeaderRow(rows, expectedCols);
    const headers = rows[headerRowIdx].map(h => (h == null ? '' : String(h).trim()));
    const out = [];
    for (let r = headerRowIdx + 1; r < rows.length; r++) {
        const row = rows[r];
        if (!row || row.every(c => c == null || c === '')) continue;
        const obj = {};
        headers.forEach((h, i) => { if (h) obj[h] = row[i]; });
        out.push(obj);
    }
    return out;
}

function readFile(file, expectedCols) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = e => {
            try {
                const XLSX = getXLSX();
                const wb = XLSX.read(e.target.result, { type: 'array' });
                const ws = wb.Sheets[wb.SheetNames[0]];
                resolve(sheetToObjects(ws, expectedCols));
            } catch (err) { reject(err); }
        };
        reader.onerror = () => reject(new Error('Falha ao ler o arquivo.'));
        reader.readAsArrayBuffer(file);
    });
}

/* ---------------- import ---------------- */
const COLS_HINT = {
    pvatual: ['Código', 'Produto', 'Estoque', 'Netshoes'],
    custo: ['Código', 'Valor Atual'],
    centauro: ['Código Produto', 'Preço'],
    renner: ['Produto Código', 'Preço Venda'],
    netshoesPortal: ['SKU Netshoes', 'SKU Referência'],
    pvdepor: ['Produto Código', 'Preço Venda'],
};

async function onFile(spec, event) {
    const file = event.target.files[0];
    if (!file) return;
    importError.value = '';
    try {
        data[spec.key] = await readFile(file, COLS_HINT[spec.key] || []);
    } catch (err) {
        importError.value = `Erro ao ler ${spec.name}: ${err.message}`;
    } finally {
        event.target.value = '';
    }
}

async function onAmazonCrosswalk(event) {
    const file = event.target.files[0];
    if (!file) return;
    try {
        data.amazonCrosswalk = await readFile(file, ['SKU Amazon', 'SKU Interno']);
    } catch (err) {
        importError.value = `Erro ao ler De-Para Amazon: ${err.message}`;
    } finally {
        event.target.value = '';
    }
}

const canCalc = computed(() => !!(data.pvatual && data.custo));
const fileSummary = computed(() => {
    const parts = FILES_SPEC.filter(s => data[s.key]).map(s => `${s.name} (${data[s.key].length})`);
    return parts.length ? parts.join(' · ') : 'nenhum dado importado';
});

function limpar() {
    Object.keys(data).forEach(k => { data[k] = null; });
    results.value = [];
    resultsBySku.clear();
    importError.value = '';
}

/* ---------------- índices ---------------- */
function buildCustoIndex(rows) {
    const exact = new Map();
    const codes = [];
    rows.forEach(r => {
        // A planilha CALCULO PROMO puxa o custo de CUSTO!G ("Valor Atual"), não de "Valor Estoque".
        const code = r['Código']; const val = toNum(r['Valor Atual']);
        if (code == null || val == null) return;
        const key = String(code);
        if (!exact.has(key)) exact.set(key, val);
        codes.push({ code: key, val });
    });
    codes.sort((a, b) => (a.code < b.code ? -1 : a.code > b.code ? 1 : 0));
    return { exact, codes };
}
function lookupCustoPrefix(idx, sku) {
    if (idx.exact.has(sku)) return idx.exact.get(sku);
    let lo = 0, hi = idx.codes.length;
    while (lo < hi) { const mid = (lo + hi) >> 1; if (idx.codes[mid].code < sku) lo = mid + 1; else hi = mid; }
    if (lo < idx.codes.length && idx.codes[lo].code.startsWith(sku)) return idx.codes[lo].val;
    return null;
}
function buildSimpleIndex(rows, keyField, valField) {
    const map = new Map();
    rows.forEach(r => {
        const k = r[keyField]; if (k == null) return;
        if (!map.has(String(k))) map.set(String(k), r[valField]);
    });
    return map;
}

/* ---------------- motor de cálculo ---------------- */
function bucketFromMonths(months) {
    if (months < 6) return 'Menos de 6 meses';
    if (months < 12) return 'Mais de 6 meses';
    if (months < 18) return '1 ano';
    if (months < 24) return '1 ano e meio';
    if (months < 30) return '2 anos';
    return '+2 anos';
}

function tempoEstoque(sku) {
    // 1) Preferência: data real de lançamento importada do Magazord (launched_at).
    const d = props.launchedDates ? props.launchedDates[sku] : null;
    if (d) {
        const launch = new Date(d + 'T00:00:00');
        if (!isNaN(launch.getTime())) {
            const now = new Date();
            const months = (now.getFullYear() - launch.getFullYear()) * 12 + (now.getMonth() - launch.getMonth());
            return bucketFromMonths(Math.max(0, months));
        }
    }
    // 2) Fallback: heurística por prefixo de SKU (VALUE(LEFT(sku, FIND("-") ou FIND("_") -1))).
    const s = String(sku);
    const iDash = s.indexOf('-');
    const iUnder = s.indexOf('_');
    let cut;
    if (iDash >= 0) cut = iDash;
    else if (iUnder >= 0) cut = iUnder;
    else cut = s.length;
    const prefix = s.slice(0, cut).trim();
    if (prefix === '' || !/^\d+$/.test(prefix)) return '';
    const codigo = parseInt(prefix, 10);
    if (isNaN(codigo)) return '';
    if (codigo > 16500) return 'Menos de 6 meses';
    if (codigo >= 16000) return 'Mais de 6 meses';
    if (codigo >= 15500) return '1 ano';
    if (codigo >= 15000) return '1 ano e meio';
    if (codigo >= 14500) return '2 anos';
    return '+2 anos';
}

// Ponto de equilíbrio — imposto/mc/comissão em %, divididos por 100 (corrige o bug do protótipo).
function calcularPontoEquilibrio(custo, imposto, mc, comissao, temFaixa) {
    const aliq = 1 - (imposto + mc + comissao) / 100;
    if (aliq <= 0) return null;
    const base = custo / aliq;
    if (temFaixa === 'ml') {
        if (base < 12.5) return base + base / 2;
        for (const t of ML_TIER) { if (t.fee != null && base < t.max) return (custo + t.fee) / aliq; }
        return base;
    }
    if (temFaixa === 'shopee') {
        for (const t of SHOPEE_TIER) { if (base <= t.max) return (custo + t.fee) / aliq; }
        return base;
    }
    return base;
}

function calcularPromoSugerido(pvAtual, pontoEquilibrio, descAtual, descEquil, rounding) {
    if (pvAtual == null || pvAtual === 0 || pontoEquilibrio == null) return null;
    const parte1 = Math.floor(pvAtual * (1 - descAtual / 100) - rounding) + rounding;
    const parte2 = pontoEquilibrio * (1 - descEquil / 100);
    return Math.max(parte1, parte2);
}

function resultadoLiquido(pv, imposto, mc, comissao, custo) {
    if (pv == null || pv === 0) return null;
    return pv - (pv * (imposto / 100) + pv * (mc / 100) + pv * (comissao / 100)) - custo;
}

function calcular() {
    importError.value = '';
    try { getXLSX(); } catch (e) { importError.value = e.message; return; }

    const imposto = cfg.imposto || 0;
    const mc = cfg.mc || 0;
    const rounding = cfg.rounding || 0.9;

    const custoIdx = buildCustoIndex(data.custo || []);
    const pvAtualRows = data.pvatual || [];

    let centauroPreco = null, rennerPreco = null;
    if (data.centauro) centauroPreco = buildSimpleIndex(data.centauro, 'Código Produto', 'Preço');
    if (data.renner) rennerPreco = buildSimpleIndex(data.renner, 'Produto Código', 'Preço Venda');

    const out = [];
    pvAtualRows.forEach(row => {
        const sku = row['Código'];
        if (sku == null || sku === '') return;
        const skuStr = String(sku);
        const custo = lookupCustoPrefix(custoIdx, skuStr) || 0;

        const rec = {
            sku: skuStr,
            produto: row['Produto'] || '',
            marca: row['Marca'] || '',
            estoque: toNum(row['Estoque']),
            custo,
            tempoEstoque: tempoEstoque(skuStr),
            canais: {},
        };

        channels.forEach(ch => {
            let pvAtual = null;
            if (ch.origem === 'pvatual') pvAtual = toNum(row[ch.col]);
            else if (ch.origem === 'centauro' && centauroPreco) pvAtual = toNum(centauroPreco.get(skuStr));
            else if (ch.origem === 'renner' && rennerPreco) pvAtual = toNum(rennerPreco.get(skuStr));

            const pontoEquilibrio = calcularPontoEquilibrio(custo, imposto, mc, ch.comissao, ch.temFaixa);
            const metaLucro = pontoEquilibrio != null ? pontoEquilibrio * (1 + ch.markup / 100) : null;
            const promoSugerido = calcularPromoSugerido(pvAtual, pontoEquilibrio, ch.descAtual, ch.descEquil, rounding);
            const resultadoAtual = resultadoLiquido(pvAtual, imposto, mc, ch.comissao, custo);
            const resultadoPromo = promoSugerido != null ? resultadoLiquido(promoSugerido, imposto, mc, ch.comissao, custo) : null;
            const percDesc = (promoSugerido != null && pvAtual) ? (promoSugerido / pvAtual - 1) : null;
            const promoMenor = (promoSugerido != null && pvAtual != null) ? (promoSugerido < pvAtual ? 'SIM' : 'NÃO') : null;

            rec.canais[ch.id] = { pvAtual, pontoEquilibrio, metaLucro, promoSugerido, resultadoAtual, resultadoPromo, percDesc, promoMenor };
        });

        out.push(rec);
    });

    results.value = out;
    resultsBySku.clear();
    out.forEach(r => resultsBySku.set(r.sku, r));
    activeTab.value = 'results';
    bbTick.value++;
}

/* ---------------- resultados (view) ---------------- */
const stats = computed(() => {
    let promosAbaixo = 0, semCusto = 0;
    results.value.forEach(r => {
        if (!r.custo) semCusto++;
        if (Object.values(r.canais).some(c => c.promoMenor === 'SIM')) promosAbaixo++;
    });
    return { total: results.value.length, promosAbaixo, semCusto };
});

const filteredRows = computed(() => {
    const s = search.value.trim().toLowerCase();
    if (!s) return results.value;
    return results.value.filter(r => r.sku.toLowerCase().includes(s) || String(r.produto).toLowerCase().includes(s));
});
const visibleRows = computed(() => filteredRows.value.slice(0, 500));

function money(v) { return v == null ? '—' : 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function pct(v) { return v == null ? '—' : (v * 100).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }

/* ---------------- export ---------------- */
function exportXlsx() {
    const XLSX = getXLSX();
    const header1 = ['SKU', 'Produto', 'Marca', 'Estoque', 'Custo'];
    const header2 = ['', '', '', '', ''];
    channels.forEach(ch => {
        header1.push(ch.label, '', '', '', '', '', '', '');
        header2.push('PV Atual', 'Ponto Equilíbrio', 'Meta Lucro', 'PV Promo Sugerido', 'Resultado Atual', 'Resultado Promo', '% Desc.', 'Promo < PV?');
    });
    const dataRows = results.value.map(r => {
        const row = [r.sku, r.produto, r.marca, r.estoque, r.custo];
        channels.forEach(ch => {
            const c = r.canais[ch.id];
            row.push(c.pvAtual, c.pontoEquilibrio, c.metaLucro, c.promoSugerido, c.resultadoAtual, c.resultadoPromo, c.percDesc, c.promoMenor);
        });
        return row;
    });
    const ws = XLSX.utils.aoa_to_sheet([header1, header2, ...dataRows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'CALCULO PROMO');
    XLSX.writeFile(wb, 'calculo_promo_resultado.xlsx');
}

/* ---------------- faixas ---------------- */
function openFaixa(ch) {
    const tier = ch.temFaixa === 'ml' ? ML_TIER : SHOPEE_TIER;
    let msg = `Faixas de taxa fixa de ${ch.label}:\n`;
    tier.forEach((t, i) => { msg += `${i + 1}) até R$ ${t.max === Infinity ? '∞' : t.max} → ${t.mode === 'half' ? 'metade do preço' : 'R$ ' + t.fee}\n`; });
    alert(msg);
}

/* ---------------- campanhas (localStorage) ---------------- */
function loadCampaigns() {
    try {
        const raw = localStorage.getItem(CAMP_KEY);
        if (raw) Object.assign(campaigns, JSON.parse(raw));
    } catch (e) { /* noop */ }
}
function saveCampaign() {
    const name = newCampaignName.value.trim();
    if (!name) { campaignMsg.value = { ok: false, text: 'Dê um nome para a campanha.' }; return; }
    const snapshot = {};
    channels.forEach(ch => { snapshot[ch.id] = { descAtual: ch.descAtual, descEquil: ch.descEquil }; });
    campaigns[name] = snapshot;
    try {
        localStorage.setItem(CAMP_KEY, JSON.stringify(campaigns));
        campaignMsg.value = { ok: true, text: `Campanha "${name}" salva.` };
    } catch (e) {
        campaignMsg.value = { ok: false, text: 'Não foi possível salvar: ' + e.message };
    }
    activeCampaign.value = name;
    newCampaignName.value = '';
}
function deleteCampaign() {
    const name = activeCampaign.value;
    if (name === '__default__') return;
    delete campaigns[name];
    localStorage.setItem(CAMP_KEY, JSON.stringify(campaigns));
    activeCampaign.value = '__default__';
    campaignMsg.value = { ok: true, text: `Campanha "${name}" excluída.` };
}
function applyCampaign(name) {
    if (name === '__default__') return;
    const snap = campaigns[name];
    if (!snap) return;
    channels.forEach(ch => {
        if (snap[ch.id]) { ch.descAtual = snap[ch.id].descAtual; ch.descEquil = snap[ch.id].descEquil; }
    });
}

/* ---------------- Buy Box: cruzamento SKU externo -> interno ---------------- */
function buildNetshoesCrosswalk() {
    if (!data.netshoesPortal) return null;
    const exact = new Map();
    const codes = [];
    data.netshoesPortal.forEach(r => {
        const ext = r['SKU Netshoes'];
        // interno: prioriza "ID Sku"; se ausente, usa "SKU Referência"
        const interno = r['ID Sku'] != null ? r['ID Sku'] : r['SKU Referência'];
        if (ext == null || interno == null) return;
        const k = String(ext);
        if (!exact.has(k)) exact.set(k, String(interno));
        codes.push({ code: k, val: String(interno) });
    });
    codes.sort((a, b) => (a.code < b.code ? -1 : a.code > b.code ? 1 : 0));
    return { exact, codes };
}
function buildAmazonCrosswalk() {
    if (!data.amazonCrosswalk) return null;
    const map = new Map();
    data.amazonCrosswalk.forEach(r => {
        const ext = r['SKU Amazon']; const interno = r['SKU Interno'];
        if (ext == null || interno == null) return;
        map.set(String(ext), String(interno));
    });
    return map;
}
function resolveInterno(crosswalk, skuExterno) {
    if (!crosswalk) return skuExterno;
    if (crosswalk instanceof Map) return crosswalk.get(skuExterno) || skuExterno;
    if (crosswalk.exact.has(skuExterno)) return crosswalk.exact.get(skuExterno);
    let lo = 0, hi = crosswalk.codes.length;
    while (lo < hi) { const mid = (lo + hi) >> 1; if (crosswalk.codes[mid].code < skuExterno) lo = mid + 1; else hi = mid; }
    if (lo < crosswalk.codes.length && crosswalk.codes[lo].code.startsWith(skuExterno)) return crosswalk.codes[lo].val;
    return skuExterno;
}

const bbCrosswalkStatus = computed(() => {
    if (bbChannel.value === 'netshoes') {
        return data.netshoesPortal
            ? `Cruzamento ativo via NETSHOES - TABELA PORTAL (${data.netshoesPortal.length} linhas).`
            : 'Nenhum arquivo NETSHOES - TABELA PORTAL importado (aba Importar) — sem ele, o código digitado será tratado como se já fosse o SKU interno.';
    }
    return data.amazonCrosswalk
        ? `Cruzamento ativo via arquivo De-Para Amazon (${data.amazonCrosswalk.length} linhas).`
        : 'Nenhum de-para importado — o código digitado será tratado como se já fosse o SKU interno.';
});

function bbComputeRow(entry) {
    // eslint-disable-next-line no-unused-expressions
    bbTick.value;
    const ch = bbChannel.value;
    const crosswalk = ch === 'netshoes' ? buildNetshoesCrosswalk() : buildAmazonCrosswalk();
    const skuInterno = resolveInterno(crosswalk, entry.skuExterno);
    const rec = resultsBySku.get(skuInterno);
    const canal = rec ? rec.canais[ch] : null;
    return {
        skuExterno: entry.skuExterno,
        skuInterno,
        tempoEstoque: rec ? rec.tempoEstoque : '',
        estoque: rec ? rec.estoque : null,
        custo: rec ? rec.custo : null,
        pvAtual: canal ? canal.pvAtual : null,
        precoConcorrente: entry.precoConcorrente,
        pvSugestao: canal ? canal.promoSugerido : null,
        ganhamos: (canal && canal.promoSugerido != null && entry.precoConcorrente != null) ? (canal.promoSugerido < entry.precoConcorrente ? 'SIM' : 'NÃO') : null,
        resultadoPosSugestao: canal ? canal.resultadoPromo : null,
        encontrado: !!rec,
    };
}

const bbRows = computed(() => {
    // depende de bbChannel/bbTick para recomputar
    return buybox[bbChannel.value].map(bbComputeRow);
});

function saveBuybox() {
    try { localStorage.setItem(BB_KEY, JSON.stringify(buybox)); } catch (e) { /* noop */ }
}
function loadBuybox() {
    try {
        const raw = localStorage.getItem(BB_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            buybox.netshoes = parsed.netshoes || [];
            buybox.amazon = parsed.amazon || [];
        }
    } catch (e) { /* noop */ }
}

function bbAddRow() {
    const sku = bbNewSku.value.trim();
    if (!sku) { alert('Informe o SKU externo.'); return; }
    buybox[bbChannel.value].push({ skuExterno: sku, precoConcorrente: (bbNewPreco.value == null || isNaN(bbNewPreco.value)) ? null : bbNewPreco.value });
    bbNewSku.value = '';
    bbNewPreco.value = null;
    saveBuybox();
    bbTick.value++;
}
function bbDel(i) {
    buybox[bbChannel.value].splice(i, 1);
    saveBuybox();
    bbTick.value++;
}
function bbImport(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        try {
            const XLSX = getXLSX();
            const wb = XLSX.read(e.target.result, { type: 'array' });
            const ws = wb.Sheets[wb.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(ws, { header: 1, raw: true, defval: null });
            const ch = bbChannel.value;
            rows.forEach(row => {
                if (!row || !row[0]) return;
                const sku = String(row[0]).trim();
                if (sku.toLowerCase().includes('sku')) return;
                buybox[ch].push({ skuExterno: sku, precoConcorrente: toNum(row[1]) });
            });
            saveBuybox();
            bbTick.value++;
        } catch (err) {
            alert('Erro ao importar lista: ' + err.message);
        }
    };
    reader.readAsArrayBuffer(file);
    event.target.value = '';
}
function bbExport() {
    const XLSX = getXLSX();
    const rows = buybox[bbChannel.value].map(bbComputeRow);
    const header = ['SKU Externo', 'SKU Interno', 'Tempo Estoque', 'Estoque', 'CMV', 'PV Atual', 'Preço Concorrente (Buy Box)', 'PV Sugestão', 'Ganhamos com a sugestão?', 'Resultado Pós Sugestão'];
    const body = rows.map(r => [r.skuExterno, r.skuInterno, r.tempoEstoque, r.estoque, r.custo, r.pvAtual, r.precoConcorrente, r.pvSugestao, r.ganhamos, r.resultadoPosSugestao]);
    const ws = XLSX.utils.aoa_to_sheet([header, ...body]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'BUYBOX ' + bbChannel.value.toUpperCase());
    XLSX.writeFile(wb, 'buybox_' + bbChannel.value + '.xlsx');
}

// limpa a mensagem de campanha automaticamente
watch(campaignMsg, v => { if (v) setTimeout(() => { campaignMsg.value = null; }, 4000); });

loadCampaigns();
loadBuybox();
</script>

<style scoped>
.cfg-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-1.5 text-sm w-28 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 transition; }
.cfg-select { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-3 py-1.5 text-sm outline-none focus:border-emerald-400 transition; }
.tbl-input { @apply bg-slate-50 border border-slate-200 text-slate-800 rounded-lg px-2 py-1 text-sm w-20 font-mono outline-none focus:border-emerald-400 transition; }
.btn-primary { @apply bg-emerald-500 hover:bg-emerald-600 text-white font-semibold rounded-lg px-4 py-2 transition shadow-sm; }
.btn-ghost { @apply bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 transition; }
.warn-badge { @apply inline-block bg-amber-100 text-amber-700 text-[10px] font-mono px-1.5 py-0.5 rounded ml-1.5 align-middle; }
.req-badge { @apply inline-block bg-slate-100 text-slate-500 text-[10px] font-mono px-1.5 py-0.5 rounded ml-1.5 align-middle; }
.dz { @apply border-[1.5px] border-dashed border-slate-200 rounded-xl p-4 cursor-pointer transition hover:border-emerald-300 block; }
.dz-filled { @apply border-solid border-emerald-300 bg-emerald-50/50; }
.stat { @apply bg-white border border-slate-200 rounded-2xl px-5 py-3 min-w-[150px] shadow-sm; }
.stat-v { @apply font-mono text-2xl font-bold text-slate-900; }
.stat-l { @apply text-slate-400 text-[11px] uppercase tracking-wide mt-0.5; }
.th-l { @apply text-left font-semibold px-3 py-2 whitespace-nowrap; }
.th-r { @apply text-right font-semibold px-3 py-2 whitespace-nowrap; }
.td-l { @apply text-left px-3 py-1.5 whitespace-nowrap; }
.td-r { @apply text-right px-3 py-1.5 font-mono whitespace-nowrap; }
.k { @apply font-mono bg-slate-100 text-emerald-700 px-1.5 py-0.5 rounded text-[11px]; }
</style>
