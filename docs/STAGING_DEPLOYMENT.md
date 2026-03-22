# Staging Deployment

This project includes staging-ready Docker artifacts with PostgreSQL + pgvector and Redis.

## 1) Prepare environment

```bash
cp .env.staging.example .env.staging
# set OPENAI_API_KEY and APP_KEY values
```

Generate app key once:

```bash
docker compose -f docker-compose.staging.yml run --rm app php artisan key:generate --show
```

Copy the output into `APP_KEY` in `.env.staging`.

## 2) Start stack

```bash
docker compose -f docker-compose.staging.yml --env-file .env.staging up --build -d
```

Services:

- `web` on `http://localhost:8080`
- `app` (php-fpm)
- `worker` (managed queue runtime; Horizon preferred, queue worker fallback)
- `scheduler` (`php artisan schedule:work`)
- `db` (`pgvector/pgvector:pg16`)
- `redis`

## 3) Verify

```bash
docker compose -f docker-compose.staging.yml --env-file .env.staging exec app php artisan migrate:status
docker compose -f docker-compose.staging.yml --env-file .env.staging exec app php artisan route:list --path=api
docker compose -f docker-compose.staging.yml --env-file .env.staging logs -f worker
docker compose -f docker-compose.staging.yml --env-file .env.staging logs -f scheduler
curl -sSf http://localhost:8080/up
curl -sSf http://localhost:8080/api/health/live
curl -sSf http://localhost:8080/api/health/ready
```

## 4) Operational notes

- `database/migrations/2026_03_21_000002_enable_pgvector_support.php` enables pgvector and ivfflat indexes on PostgreSQL.
- Queue-backed tasks are required for sync/import/indexing.
- `worker` koristi `queue:run-managed` (Horizon ako postoji, fallback na queue:work).
- Scheduler je vec pokrenut kao poseban `scheduler` container (`schedule:work`), pa cron nije potreban u Docker staging setup-u.

- Manual check for due sync dispatch:

```bash
php artisan integrations:sync-scheduled --dry-run --limit=200
php artisan integrations:sync-scheduled --limit=200
```

- Recommended next step is to run at least one `initial` WooCommerce sync in staging and inspect `import_jobs` and `import_job_rows`.
- Admin action audit trail is available via `GET /api/admin/audit-logs` (admin role, tenant scoped).
- Full pre-release checklist is in `docs/STAGING_READINESS_CHECKLIST.md`.
