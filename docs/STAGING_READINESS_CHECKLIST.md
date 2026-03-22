# Staging Readiness Checklist

Use this checklist before every staging rollout.

## 1) Environment and Secrets

- [ ] `.env.staging` created from `.env.staging.example`.
- [ ] `APP_KEY` generated and set.
- [ ] `OPENAI_API_KEY` set (if real LLM behavior is required).
- [ ] PostgreSQL and Redis credentials validated.
- [ ] Demo seed credentials overridden from defaults:
  - `DEMO_OWNER_EMAIL`
  - `DEMO_OWNER_PASSWORD`
  - `DEMO_TENANT_SLUG`

## 2) Database and Seed

- [ ] Migrations are up to date:

```bash
docker compose -f docker-compose.staging.yml --env-file .env.staging exec app php artisan migrate --force
```

- [ ] Demo tenant seeded (if `SEED_DEMO_TENANT=true`):

```bash
docker compose -f docker-compose.staging.yml --env-file .env.staging exec app php artisan db:seed --class=SalesAssistantDemoSeeder --force
```

- [ ] pgvector extension enabled:

```sql
SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';
```

## 3) Service Health Checks

- [ ] Laravel liveness endpoint:

```bash
curl -sSf http://localhost:8080/up
```

- [ ] API liveness endpoint:

```bash
curl -sSf http://localhost:8080/api/health/live
```

- [ ] API readiness endpoint (DB + storage + queue metadata):

```bash
curl -sSf http://localhost:8080/api/health/ready
```

Expected: `status: "ok"` and all checks marked `ok: true`.

## 4) Background Jobs and Ingestion

- [ ] Worker process is running and healthy.
- [ ] Run one integration `initial` sync and confirm:
  - records in `import_jobs`
  - no systemic errors in `import_job_rows`
- [ ] Reindex one knowledge document and verify chunk generation.

## 5) Security and Audit

- [ ] Admin auth and tenant role checks validated.
- [ ] Audit log entries created for at least one `updated` and one `deleted` admin action.
- [ ] `GET /api/admin/audit-logs` is accessible for admin role and scoped by tenant.

## 6) Frontend and E2E Smoke

- [ ] Frontend build completed successfully.
- [ ] Playwright smoke passes:

```bash
npm run e2e
```

This E2E suite covers:
- onboarding wizard flow
- modal edit/delete clicks
- user management (create/edit/remove)
- authenticated admin navigation and CRUD interactions

## 7) Go/No-Go

- [ ] No failing feature tests:

```bash
php artisan test
```

- [ ] Deployment notes captured (version/date/operator).
- [ ] Rollback command prepared and verified.
