# PrismaHUB 360 PRO 🚀

Central de Inteligência Artificial e Gestão de Ecossistemas para E-commerce.

## 📌 Sobre o Projeto
O **PrismaHUB 360 PRO** é uma plataforma avançada de gestão integrada (ERP/BI) focada em performance para marketplaces. Recentemente migrado para uma arquitetura **Inertia.js**, o sistema oferece uma experiência de Single Page Application (SPA) fluida, moderna e reativa.

## 🛠️ Stack Tecnológica
- **Backend:** Laravel 11 (PHP 8.2+)
- **Frontend:** Vue 3 (Composition API)
- **Comunicação:** Inertia.js (Protocolo SPA)
- **Estilização:** Tailwind CSS (Dark Mode & Glassmorphism)
- **Build Tool:** Vite
- **Rotas:** Ziggy (Laravel Routes in JS)

## 📁 Estrutura do Projeto
- `app/Http/Controllers`: Lógica de negócio e retornos Inertia.
- `resources/js/Pages`: Componentes de página em Vue 3.
- `resources/js/Layouts`: `AppLayout.vue` (Layout mestre persistente).
- `resources/js/Components`: Componentes reutilizáveis (NavLink, Pagination, etc).
- `routes/web.php`: Definição de rotas do sistema.

## 🚀 Instalação e Desenvolvimento
### Requisitos
- PHP 8.2+
- Node.js 18+
- Composer & NPM

### Comandos Principais
```bash
# Instalação de dependências
composer install
npm install

# Iniciar servidor de desenvolvimento
php artisan serve
npm run dev

# Build de produção (Vite)
npm run build
```

## 📄 Documentação Técnica
Para detalhes profundos sobre a arquitetura, padrões de código e fluxos de dados, consulte o arquivo [TECH_DOC.md](./TECH_DOC.md).

---
&copy; 2026 Prisma Inteligência Artificial.
