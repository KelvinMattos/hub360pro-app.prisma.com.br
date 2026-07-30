# CLAUDE.md — PrismaHUB 360 PRO

Memória operacional do projeto. **Cada regra aqui nasceu de um incidente real** — a
referência ao caso fica junto, porque regra sem contexto vira superstição e acaba
sendo revertida por alguém que não viveu o problema.

> Leia as seções **Regras invioláveis** e **Armadilhas conhecidas** antes de mexer em
> deploy, importação, coleta de preço ou repricing.

---

## 1. Stack e infraestrutura

| Item | Valor |
|---|---|
| Backend | Laravel 11 (PHP 8.x) |
| Frontend | Vue 3 (Composition API) + Inertia.js + Tailwind |
| Rotas no front | Ziggy (`route()`) |
| Build | Vite (`npm run build`) — **`public/build` é versionado no repo** |
| Banco | MySQL (produção) |
| Sessão | driver `database` (**sem lock bloqueante**) |
| Fila | `database` — **worker não é confiável no cPanel** |
| Hospedagem | cPanel — `/home2/kelvi593/app.prismaads.com.br` |
| Domínio | app.prismaads.com.br |
| Planilhas | `openspout` v4 (streaming, já no `composer.lock`) |

**Laravel 11:** não existe `app/Console/Kernel.php`. Comandos em
`app/Console/Commands` são auto-registrados; middleware é configurado em
`bootstrap/app.php`.

**Ambiente de desenvolvimento (sandbox):** `vendor/` não está instalado — dá para
rodar `php -l` e `npm run build`, mas **não** `php artisan`. O proxy de saída
**bloqueia `netshoes.com.br`**, então validação contra o site real só em produção.

---

## 2. Regras invioláveis

### 2.1 Nunca falhar em silêncio
Toda falha precisa aparecer: status real, log e estado persistido.

- **Incidente:** o `deploy.sh` usava `git pull origin main || echo "..."`. A chave SSH
  do servidor pedia passphrase, o pull falhava, o script imprimia **"Deploy concluído
  com sucesso"** e rodava migrations em cima de código velho. **A produção ficou
  congelada no commit `2f5fc53` por ~1 semana** sem ninguém perceber.
- **Regra:** em `deploy.sh` é proibido `|| true` / `|| echo` em qualquer etapa que
  atualize código ou dependências. Hoje usa `git fetch --prune` + `git merge --ff-only`
  e `set -euo pipefail`. Só o `storage:link` pode falhar de forma tolerada.

### 2.2 Nunca gravar dado quando a origem falhou
Se a coleta/importação não teve sucesso comprovado, **não escreve valor** — escreve o erro.

- **Regra:** o coletor só retorna `ok: true` com **HTTP 200 + preço extraído**.
  Qualquer outro caso grava `market_error` e **jamais toca** em `market_price` /
  `market_source`.

### 2.3 Nunca culpar o sistema externo por erro nosso
- **Incidente:** o POST de diagnóstico voltava **419** (CSRF), e a tela mostrava
  *"Não foi possível capturar"* — ou seja, **acusava a Netshoes de um bug nosso**.
- **Regra:** distinguir a **camada** da falha (`transporte` vs `coleta`) e exibir o
  status HTTP real com mensagem específica.

### 2.4 Validar premissas contra dado real antes de construir em cima
- **Incidente:** o parser foi escrito por suposição. Ao conferir o HTML real,
  **3 das 4 premissas estavam erradas** (ver §5).
- **Regra:** quando a fonte real não estiver acessível, escrever o parser **defensivo**,
  entregar **tela de diagnóstico** e deixar explícito no PR que não foi validado
  contra o real. Nunca afirmar que funciona sem ter visto funcionar.

### 2.5 Trava antes de automação
Qualquer automação que altere preço nasce **desligada**, em **dry-run**, com **piso**,
**auditoria** e **rollback**.

### 2.6 Não contornar bloqueio de terceiros
O cliente foi explícito: **nada de proxy, rotação de IP ou headless disfarçado**.
Bloqueio → trocar por canal autorizado (API oficial / relatório do Seller Center).

### 2.7 Segredos
- **Nunca** usar credenciais de banco de produção (o cliente colou uma vez; foi
  orientado a rotacionar — considere-a comprometida).
- Credenciais de API vão na tela **Conexões**, **nunca** hardcoded nem no repo.

### 2.8 Não criar loops de check-in automáticos
- **Incidente:** `send_later` re-armado a cada hora consumiu os créditos do cliente.
- **Regra:** só agendar quando pedido explicitamente. Inscrição em PR (webhook) é ok —
  ela só acorda em evento real.

---

## 3. Fluxo de trabalho

- **Branch de desenvolvimento:** `claude/project-system-integration-awjpzh`.
- O cliente pede **"envie tudo para a main"** — o padrão é: commit → push → PR →
  **squash-merge na `main`** (o merge dispara o deploy).
- **Após um squash-merge**, a branch local diverge da `main`. Antes de novo trabalho:
  ```bash
  git fetch origin main && git checkout -B <branch> origin/main
  ```
  Pular isso gera **conflito no PR** (aconteceu no PR #6).
- Assets **buildados entram no commit** (`public/build` versionado).
- Sempre `php -l` nos arquivos PHP e `npm run build` antes de commitar.

### 3.1 Autorização permanente: corrigir e mergear sem perguntar (desde 30/07/2026)

O cliente pediu explicitamente, e confirmou como regra permanente: **sempre que
encontrar algo errado no sistema, corrigir, e ao final mandar tudo para a `main`** —
sem precisar abrir PR e esperar aprovação item a item. Isso substitui, daqui pra
frente, a antiga exigência de "abra a PR e me diga o número que eu verifico" que
valia para o diagnóstico do PR #9.

- Fluxo padrão passa a ser: commit → push → PR → **squash-merge direto na `main`**,
  sem pausa para aprovação — desde que a suíte de testes (MySQL real) esteja verde e
  `php -l`/`npm run build` limpos.
- O cliente também pediu **auditoria mais ampla por conta própria daqui pra frente** —
  não ficar restrito só ao que foi explicitamente apontado; ao mexer em qualquer
  área, vale revisar o entorno em busca de outros problemas reais (validando contra
  código/dado real, nunca por suposição — ver §2.4) e corrigir também.
- **Isso NÃO suspende nenhuma regra de segurança da seção 2** — piso de repricing,
  `dry_run`/`repricing_enabled` desligados por padrão, nunca contornar bloqueio de
  terceiros, nunca commitar segredo, nunca rodar `catalog:reset` ou operação
  destrutiva sem confirmação explícita continuam valendo exatamente como antes.
  A autorização é para **corrigir bugs e mergear**, não para automação de risco.
- O deploy em si continua manual (`bash deploy.sh` no cPanel) — merge na `main`
  dispara o workflow, que hoje falha de propósito porque os secrets de SSH nunca
  foram configurados (ver §2.1).

---

## 4. Resiliência de schema (crítico)

O schema de produção **diverge** dos models. Leituras e escritas precisam ser defensivas.

- Use `Schema::hasColumn()` / `Schema::getColumnListing()` antes de referenciar coluna.
- **Padrão `prune()`**: filtrar o payload para as colunas existentes antes do `update`.
  - **Incidente:** a importação "Produtos & Datas" gravava `brand`, coluna que não
    existia. O erro derrubava a **transação inteira** → `launched_at` nunca era salvo
    → a tela de Aging ficava 100% em "Sem data". O sintoma não apontava para a causa.
- Prefira `DB::table()` a Eloquent em leituras pesadas.
  - **Incidente:** `Product` tem `$with = ['medias', 'channel_settings']`; o eager-load
    derrubava a tela de Aging com 500.

---

## 5. Conhecimento de domínio

### 5.1 Exports Magazord (CSV)
Encoding **ISO-8859-1** → converter para UTF-8. Delimitador **`;`**. Números em
padrão BR (`1.674,14` → `1674.14`). Parsing por streaming (`fgetcsv` + generator).

Tipos: `estoque`, `custos`, `precos`, `descontos`, `produtos`, `vendas`.

**Data do pedido:** em `orders`, `date_created` é a **data real** do pedido;
`created_at` é o **timestamp da importação**.
- **Incidente:** a Análise de Vendas agrupava por `created_at` e jogava **o ano inteiro
  no mês da importação**. O dado no banco estava certo — o bug era só na leitura.
- **Regra:** para data de pedido use `date_created` → `order_date` → (só então) `created_at`.

### 5.2 Netshoes
- **O SKU Netshoes é universal entre sellers** — todos anunciam o mesmo SKU no mesmo
  produto. É a chave para cruzar Buy Box.
- **Código do produto:** o SKU pode ter sufixo de tamanho. Remover **apenas quando
  houver mais de 3 blocos**.
  - `39V-24AJ-205-43` → `39V-24AJ-205` ✅
  - `I6E-7247-060` → **fica intacto** (o `060` é parte do código, não tamanho) ✅
  - **Incidente:** a regra original cortava qualquer bloco numérico final e quebrava
    todo SKU de 3 blocos.
- **URL da PDP:** `/p/{slug}-{código sem tamanho}`; a busca `/busca?q={código}` funciona.

**Estrutura do HTML (validada em produção):**
- ❌ **Não existe `__NEXT_DATA__`** na página.
- ✅ Existe **JSON-LD**, mas só com `AggregateOffer`:
  `{"lowPrice":"125.47","highPrice":"154.90","offerCount":2}` — **sem vendedor**.
- 🔴 **`lowPrice` é o preço à vista/PIX, NÃO o preço do anúncio.** O preço real da
  Buy Box é o **`highPrice`**.
  - **Incidente:** usar `lowPrice` gravaria **~19% abaixo do real em todo o catálogo** —
    e o repricing usaria isso como base.
  - **Regra:** preço de mercado = `highPrice`. O `lowPrice` volta separado como
    `pix_price`, apenas informativo, e **nunca** entra em `market_price`.
- ⚠️ **`offerCount` conta faixas de preço, não sellers.** Não use como "nº de concorrentes".
- O **vendedor** só aparece no texto renderizado: `"Vendido por <loja>"` /
  `"Vendido e entregue por <loja>"` (seguido de `"Enviado por Netshoes"`).

**Bloqueio:** a Netshoes responde **HTTP 403 (Access Denied — borda Akamai)** a
requisições server-side, com qualquer conjunto de headers. O scraper direto fica
**desligado por padrão**. Fonte oficial = relatório de Buy Box do Seller Center /
API autorizada / planilha.

---

## 6. Armadilhas conhecidas (Laravel/Inertia)

### 6.1 `Session store not set on request`
- **Incidente:** uma rota de polling foi criada com
  `->withoutMiddleware([StartSession::class])` para "evitar lock de sessão". Mas o
  `VerifyCsrfToken` continua no grupo `web` e **sempre** tenta enfileirar o cookie
  `XSRF-TOKEN`, chamando `$request->session()` → exceção **a cada poll**, inundando o
  `laravel.log`.
- **Regra:** **não remova `StartSession` de rotas web.** O driver de sessão é
  `database`, que **não usa lock bloqueante** — um POST longo não trava um GET de
  polling. A premissa que motivou a remoção era falsa.

### 6.2 HTTP 419 em POST fora do Inertia
- **Incidente:** o `app.blade.php` **não tinha** `<meta name="csrf-token">`. Chamadas
  `fetch`/`axios` liam o token de lá, mandavam header vazio e levavam **419**.
- **Regra:** a meta precisa existir no layout. Para POST fora do Inertia, use `axios`
  enviando `X-CSRF-TOKEN` a partir dela. GET de polling não precisa de CSRF.

### 6.3 Importações longas em cPanel
- Sem worker de fila confiável → **lote síncrono** com `set_time_limit(0)` +
  progresso em **cache de arquivo** (`Cache::store('file')`), consultado por polling GET.
- Cloudflare corta a origem em ~100s: o **resultado final também vai pelo polling**,
  não só pelo redirect.
- Overlay precisa de `<Teleport to="body">` — dentro do `<main>` o stacking context
  deixa a sidebar por cima.
- URL do polling precisa de **cache-busting** (`?t=Date.now()`) e `cache:'no-store'`.

---

## 7. Arquitetura do módulo de Monitoramento

```
products.market_*        preço de mercado, vendedor, origem, verificado em, URL, erro
products.buybox_winner   true = nós ganhamos | false = outro | null = sem dado
products.netshoes_*      SKU/preço/estoque/status do canal Netshoes
market_snapshots         histórico por coleta (evolução, otimizações, recuperados)
monitoring_settings      config por empresa (JSON) — nome da loja, scraper, repricing
brand_margins            margem mínima por marca (piso do repricing)
repricing_batches/logs   auditoria por lote + rollback
```

**Status de competitividade é calculado, não persistido** (`MarketMonitorService`):
`desconhecido` (sem mercado) → `alerta` (sem estoque) → `perdendo` (preço > mercado)
→ `vendendo`.

**Classificação de concorrência** usa **apenas o gap de preço** — deixou de usar
`offerCount` (ver §5.2).

**Fluxo de dados obrigatório:**
```
Magazord (catálogo/custo/estoque)  →  produtos
Netshoes "Portal" (export)         →  netshoes_sku   ← PRÉ-REQUISITO de tudo
Relatório Buy Box / Hooklab        →  market_price + market_seller + buybox_winner
                                       ↓
                          Dashboard / Otimizar / Relatório / Repricing
```

**Repricing — travas (todas registram o motivo do bloqueio na auditoria):**
1. **Piso** = custo × (1 + margem da marca); fallback margem global.
2. **Frescor** — ignora sem `market_checked_at` ou mais velho que N horas.
3. **Variação máxima** — acima do limite (padrão 15%) exige aprovação manual.
4. **Fonte confiável** — e o preço PIX nunca chega aqui (separado na coleta).

Padrões: `repricing_enabled = false`, `dry_run = true`, `scraper_enabled = false`.

---

## 8. Estado atual e próximos passos

### Bloqueador
🔴 **`netshoes_sku` = 0 em todos os produtos.** Sem isso não há o que monitorar nem
reprecificar. **Primeiro passo:** rodar *Importações Netshoes → Importar Produtos
Netshoes* (export "Portal").

### Pendências de informação (do cliente)
- Amostra do **relatório de Buy Box** (Seller Center ou Hooklab) — para calibrar os
  aliases de coluna do importador.
- **Docs da API de seller Netshoes/Magalu** — o cliente HTTP **não foi implementado
  de propósito**: sem o contrato real seria chute.

### Roadmap Hooklab
| # | Item | Estado |
|---|---|---|
| 1 | Dashboard | ✅ |
| 2 | Produtos monitorados | ✅ |
| 3 | Otimizar | ✅ |
| 4 | Relatório (marca + competitividade + Buy Box) | ✅ |
| 5 | Gerenciar (template de custos, add SKU manual, visibilidade) | ⏳ parcial (margem por marca ✅) |
| 6 | Conta (fatura estimada) | ⏳ |
| 7 | Suporte / FAQ | ⏳ |

### Oportunidades levantadas (ordem de impacto)
1. **Alertas proativos** — avisar ao perder Buy Box de produto curva A. Monitorar é
   bom; **ser avisado** é o que gera ação. **Não depende de API nem de scraper.**
2. **Repricing automático** — motor pronto; falta a fonte de preço confiável.
3. **Curva ABC × Buy Box** — perder Buy Box num produto que vende 300/mês ≠ num que
   vende 2. Priorizar por **impacto em receita**, não por contagem.
4. **Elasticidade** — com `market_snapshots` acumulando: "quando baixei 5%, vendi
   quanto a mais?" — em vez de chutar desconto.
5. **Estender a coleta a outros canais** (Centauro, Amazon) — mesma arquitetura,
   troca o parser.

---

## 9. Motor de precificação (Cálculo Promo)

Fórmulas validadas contra a planilha original, célula a célula:

- **Ponto de equilíbrio** = `Custo / (1 − (imposto + MC + comissão)/100)`
  - ⚠️ dividir por 100 — o protótipo não dividia e zerava o cálculo.
- **PV Meta** = `equilíbrio × (1 + markup/100)`
- **PV Promo** = `MAX( ROUNDDOWN(PV × 0,8 − 0,9) + 0,9 ; equilíbrio × 0,9 )`
- **Custo** vem da coluna **"Valor Atual"** (não "Valor Estoque").
- **Comissão Shopee = 20%**; ML e Shopee têm faixas de taxa fixa.
- **Tempo de estoque** a partir de `launched_at`.

Config de canais é **persistida por empresa** (`pricing_settings` / `channel_settings`),
não fica fixa no código.

---

## 10. Comandos úteis

```bash
# Deploy (no cPanel, dentro da pasta do projeto)
bash deploy.sh                       # falha alto de propósito
PHP=/usr/local/bin/ea-php83 bash deploy.sh

# Coleta de Buy Box (hoje bloqueada pelo site; desligada por padrão)
php artisan netshoes:buybox --limit=300
php artisan netshoes:buybox --company=1 --force

# Emergência: limpar catálogo e vendas (preserva usuários/empresa/config)
# UI: Configurações do Sistema → Zona de Perigo (confirmação "LIMPAR TUDO")
php artisan catalog:reset

# Validação local
php -l <arquivo.php>
npm run build
```

---

## 11. Como o cliente trabalha

- Comunicação em **português**; escreve rápido e manda correções em sequência —
  releia a última mensagem antes de responder.
- **Testa em produção e traz diagnóstico técnico de qualidade** (HTTP status, tinker,
  HTML real). Trate esses relatos como fonte confiável: **três bugs sérios vieram
  dele, não de mim**.
- Quer **honestidade sobre limitações** — prefere "não validei isso" a uma entrega que
  finge funcionar.
- Prefere **entrega direto na `main`**.
- Aceita e pede **sugestões de melhoria** ao fim de cada entrega.
