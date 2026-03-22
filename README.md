# AI Sales Assistant SaaS (Laravel 12)

Ovaj backend je implementacija AI prodajnog asistenta iz tri specifikacije:

- `ai_sales_assistant_laravel_arhitektura.md`
- `pm_dev_specifikacija_ai_sales_assistant_ingestion_i_knowledge.md`
- `jios.md`

## Sta je sada uradjeno

- Multi-tenant osnova (`tenants`, `plans`, `tenant_users`)
- Admin auth + role permissions:
  - bearer token login (`/api/admin/auth/login`)
  - password reset token flow (`/api/admin/auth/password/reset`) + reset page (`/password/reset`)
  - middleware `auth.token` i `tenant.role`
  - role hijerarhija: `support < editor < admin < owner`
  - tenant user management API + UI (create/edit/remove users, role assignment)
- Catalog ingestion:
  - manual product CRUD
  - CSV import (queue + row logs)
  - WooCommerce adapter (real API test + initial sync + delta sync)
  - WordPress REST adapter (real API test + initial sync + delta sync + mapping support)
  - Shopify GraphQL adapter (real API test + initial sync + delta sync)
  - Custom API adapter (real API test + initial sync + delta sync + transform rules)
  - mapping presets API za source field mapiranje
- Knowledge ingestion:
  - text ingest
  - file ingest metadata + async parse hook
  - chunking + embeddings
  - reindex endpoint
- AI orchestration:
  - intent detection
  - entity extraction
  - product + knowledge retrieval
  - structured response validation
  - OpenAI Responses API with fallback
- Embeddings i retrieval:
  - OpenAI embeddings API integration
  - fallback embeddings kada nema API kljuca
  - hybrid scoring (lexical + business + semantic)
  - pgvector support (PostgreSQL) sa ivfflat indeksima
  - graceful fallback na JSON cosine scoring kada pgvector extension nije instaliran
- Widget/public API:
  - session start, message, lead, event, config
  - embeddable script `public/widget.js`
- Analytics i conversation log
- Audit log za admin akcije (ko je sta mijenjao/brisao)
- Health checks:
  - `GET /up`
  - `GET /api/health/live`
  - `GET /api/health/ready`
- Playwright E2E smoke za onboarding + modal CRUD tokove
- Test pokrivenost za:
  - admin product flow
  - knowledge ingest
  - widget message flow
  - WooCommerce connection test
  - WooCommerce initial + delta sync
  - WordPress REST and Shopify sync
  - Custom API sync + transform rules

## Brzi start (lokalno)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
npm install
npm run build
php artisan serve
```

Napomena:

- runtime `.env` je postavljen na PostgreSQL (`chatko`)
- lokalni PostgreSQL 18 (server2) je na portu `5433`
- testovi koriste `.env.testing` (`sqlite :memory:`) za brzu izolaciju

Frontend admin panel:

- otvori `GET /` i prijavi se sa demo kredencijalima
- panel pokriva: dashboard, onboarding, users, integrations, widgets, products, knowledge, conversations, import jobs, audit logs, AI config, widget lab

### PostgreSQL 18 + pgvector (Windows)

`pgvector` je aktiviran na PostgreSQL 18 instanci za bazu `chatko`.

Brza provjera:

```sql
SELECT extname, extversion
FROM pg_extension
WHERE extname = 'vector';
```

## Demo pristup nakon seed-a

Seeder kreira demo owner nalog:

- email: `owner@demo.local`
- password: `password123`
- tenant slug: `demo-shop`

Seeder kreira i platform-level system admin nalog:

- email: `system@demo.local`
- password: `password123`
- tenant slug: `demo-shop`
- `is_system_admin=true` (jedini koji smije raditi tenant onboarding / kreiranje novih tenant-a)

Napomena: ove vrijednosti su sada konfigurabilne kroz env varijable:

- `SEED_DEMO_TENANT`
- `DEMO_TENANT_NAME`
- `DEMO_TENANT_SLUG`
- `DEMO_OWNER_NAME`
- `DEMO_OWNER_EMAIL`
- `DEMO_OWNER_PASSWORD`
- `DEMO_OWNER_IS_SYSTEM_ADMIN`
- `SYSTEM_ADMIN_NAME`
- `SYSTEM_ADMIN_EMAIL`
- `SYSTEM_ADMIN_PASSWORD`
- `SYSTEM_ADMIN_ATTACH_DEMO_TENANT`
- `SYSTEM_ADMIN_DEMO_ROLE`
- `DEMO_SUPPORT_EMAIL`

Login token:

```http
POST /api/admin/auth/login
Content-Type: application/json

{
  "tenant_slug": "demo-shop",
  "email": "owner@demo.local",
  "password": "password123"
}
```

Za admin rute salji:

- `Authorization: Bearer <token>`
- `X-Tenant-Slug: demo-shop`

## API pregled

### Widget/public

- `POST /api/widget/session/start`
- `POST /api/widget/message`
- `GET /api/widget/config/{publicKey}`
- `POST /api/widget/lead`
- `POST /api/widget/event`

### Health

- `GET /up`
- `GET /api/health/live`
- `GET /api/health/ready`

### Admin auth

- `POST /api/admin/auth/login`
- `POST /api/admin/auth/password/reset`
- `GET /api/admin/auth/me`
- `POST /api/admin/auth/logout`

### Admin ingestion/knowledge

- `GET/POST/PUT /api/admin/integrations...`
- `DELETE /api/admin/integrations/{id}`
- `POST /api/admin/integrations/{id}/test`
- `POST /api/admin/integrations/{id}/sync` (`mode=initial|delta`)
- `GET/POST /api/admin/integrations/{id}/mapping-presets`
- `PUT/DELETE /api/admin/mapping-presets/{id}`
- `POST /api/admin/mapping-presets/{id}/apply`
- `GET/PUT/DELETE /api/admin/import-jobs...`
- `GET/POST/PUT/DELETE /api/admin/products...`
- `POST /api/admin/products/import/csv`
- `GET/POST/PUT/DELETE /api/admin/users...`
- `POST /api/admin/users/{id}/password-reset-link`
- `GET/POST/PUT/DELETE /api/admin/knowledge-documents...`
- `POST /api/admin/knowledge-documents/{id}/reindex`
- `GET /api/admin/widgets`
- `POST/PUT/DELETE /api/admin/widgets...`
- `GET /api/admin/ai-config`
- `PUT /api/admin/ai-config`
- `GET/PUT/DELETE /api/admin/conversations...`
- `GET /api/admin/audit-logs`
- `GET /api/admin/analytics/overview`

## Testovi

```bash
cd backend
php artisan test
```

Playwright E2E:

```bash
cd backend
npm run e2e
```

## Staging deployment

Staging Docker stack (Postgres + pgvector + Redis) je spreman:

- `docker-compose.staging.yml`
- `docker/staging/*`
- `.env.staging.example`
- `docs/STAGING_DEPLOYMENT.md`
- `docs/STAGING_READINESS_CHECKLIST.md`
- `docs/ADMIN_OPERATIVNO_UPUTSTVO.md`
- `docs/HORIZON_SETUP.md`

## Operativne komande (queue/scheduler)

Managed queue runtime (Horizon ako postoji, inace queue worker):

```bash
php artisan queue:run-managed --tries=3 --sleep=1 --timeout=120
```

Automatski scheduler worker:

```bash
php artisan schedule:work
```

Auto-sync dispatch (manual check):

```bash
php artisan integrations:sync-scheduled --dry-run --limit=200
php artisan integrations:sync-scheduled --limit=200
```

Freshness report (koliko su integracije azurne):

```bash
php artisan integrations:freshness-report --limit=200
```

## Trenutna ogranicenja

- PDF/DOCX parser je trenutno hook (TXT i text ingest rade potpuno)
- Custom API adapter je implementiran; sledeci veci korak je UI za napredna transform pravila i preview mapiranja
