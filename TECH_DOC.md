# Documentação Técnica: PrismaHUB 360 PRO 💎

## 1. Arquitetura do Sistema
O sistema opera sob uma arquitetura **SPA (Single Page Application)** utilizando o **Inertia.js**. Isso permite que o Laravel gerencie as rotas, autenticação e dados, enquanto o **Vue 3** cuida da interface reativa.

### Fluxo de Comunicação
1. **Requisição:** O frontend envia uma requisição via `router.get()` ou link do Inertia.
2. **Controller:** O Laravel processa a lógica e retorna `Inertia::render('Pasta/Componente', $props)`.
3. **Renderização:** O Inertia recebe o JSON e monta o componente Vue correspondente sem recarregar a página.

## 2. Controladores de Negócio (Camada Inertia)

### MeliIntelligenceController
Gerencia a inteligência competitiva no Mercado Livre.
- `calculator()`: Renderiza a calculadora de taxas e lucro do ML.
- `warRoom()`: Interface de monitoramento de concorrentes em tempo real.
- `searchCompetitors()`: Endpoint que consome a API oficial do Mercado Livre para análise de GAP de preço.

### FinancialDashboardController
Centraliza a visão financeira da empresa.
- Utiliza o `FinancialProrationService` para calcular o **Lucro Líquido** real, considerando rateio de custos fixos, impostos e taxas de marketplace.
- Renderiza componentes de alta performance como o **Painel CFO** e a **Visão DRE**.

### PricingSimulationController
Simulador estratégico de preços.
- Permite criar cenários hipotéticos ("What-if") para prever margens de lucro antes de publicar anúncios.
- Persistência em banco de dados isolada por empresa (Multi-Tenancy).

## 3. Frontend: Vue 3 & Composition API

### Padrão de Componentização
- **Pages:** Localizadas em `resources/js/Pages`. Cada arquivo `.vue` representa uma URL no sistema.
- **Layouts:** O `AppLayout.vue` é o contêiner persistente que mantém o estado da Sidebar e Notifications entre as navegações.

### Resiliência e UX
- **Optional Chaining:** Implementamos o operador `?.` em propriedades altamente dinâmicas (ex: `order.channel_icon?.color`). Isso evita que o sistema "quebre" caso a integração externa retorne dados incompletos.
- **Debounced Search:** Filtros de busca utilizam um atraso de 300ms (`debounce`) para evitar múltiplas requisições desnecessárias durante a digitação.

## 4. Segurança e Isolamento (Multi-Tenancy)
O sistema utiliza **Global Scopes** no Eloquent (Laravel) para garantir que um usuário veja apenas os dados de sua própria empresa.
```php
static::addGlobalScope('company', function (Builder $builder) {
    if (Auth::check()) {
        $builder->where('company_id', Auth::user()->company_id);
    }
});
```

## 5. Manutenção e Deployment

### Preparação do Ambiente
Ao realizar alterações no frontend (Vue/CSS), é necessário rodar:
```bash
npm run build
```

### Limpeza de Cache
Sempre que houver mudanças em rotas ou configurações do Laravel:
```bash
php artisan optimize:clear
```

---
*Atualizado em: 10 de Março de 2026*
