## Project ERP

### Instalação

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
npm run dev
```

### Evolução futura

A auditoria centralizada de ações financeiras será avaliada junto com a etapa de multiusuários, perfis e permissões. Nesta fase, permanece apenas a auditoria específica do fechamento mensal.

As decisões de produto para a evolução financeira, incluindo a distinção entre
parcelamentos e contas recorrentes esperadas, estão registradas no
[roadmap financeiro](docs/financial-roadmap.md).
