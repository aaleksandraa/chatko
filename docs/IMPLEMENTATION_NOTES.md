# Implementation Notes

## Scope delivered (MVP+)

- Multi-tenant foundation with `tenant_id` isolation
- Admin bearer-token auth with tenant role permissions
- Ingestion backbone:
  - manual products
  - CSV import
  - integration lifecycle (test/sync/log)
  - WooCommerce real adapter (test + initial + delta)
  - WordPress REST real adapter (test + initial + delta + mapping support)
  - Shopify GraphQL real adapter (test + initial + delta)
  - Custom API real adapter (test + initial + delta + transform rules)
  - mapping presets API and persistence
- Knowledge ingest:
  - text ingest
  - file metadata ingest + async parse hook
  - chunking + embeddings + reindex
- Retrieval-first orchestration with hybrid scoring
- OpenAI Responses + OpenAI Embeddings integration (fallback mode without key)
- Optional pgvector support on PostgreSQL (extension + ivfflat indexes)
- PostgreSQL runtime profile in `.env` (`chatko`), SQLite isolated for tests in `.env.testing`
- Widget/session/message/lead/event APIs
- Analytics basics and conversation logs
- Staging Docker deployment artifacts

## Core files

- Routing: `routes/api.php`
- Auth middleware:
  - `app/Http/Middleware/EnsureApiTokenAuthenticated.php`
  - `app/Http/Middleware/EnsureTenantRole.php`
- Tenancy middleware: `app/Http/Middleware/EnsureTenantContext.php`
- Main migration: `database/migrations/2026_03_21_000001_create_sales_assistant_core_tables.php`
- pgvector migration: `database/migrations/2026_03_21_000002_enable_pgvector_support.php`
- API token migration: `database/migrations/2026_03_21_000003_create_api_tokens_table.php`
- Woo adapter: `app/Services/Integrations/Adapters/WooCommerceProductSourceAdapter.php`
- WordPress adapter: `app/Services/Integrations/Adapters/WordPressRestProductSourceAdapter.php`
- Shopify adapter: `app/Services/Integrations/Adapters/ShopifyProductSourceAdapter.php`
- Custom API adapter: `app/Services/Integrations/Adapters/CustomApiProductSourceAdapter.php`
- Mapping transform engine: `app/Services/Integrations/Mapping/FieldMappingResolverService.php`
- Mapping preset controller: `app/Http/Controllers/Admin/SourceMappingPresetController.php`
- Sync job: `app/Jobs/RunIntegrationSyncJob.php`
- Orchestrator: `app/Services/Conversation/ConversationOrchestratorService.php`
- Retrieval services:
  - `app/Services/Retrieval/ProductRetrievalService.php`
  - `app/Services/Retrieval/KnowledgeRetrievalService.php`
- Embedding service: `app/Services/AI/EmbeddingGenerationService.php`
- OpenAI response service: `app/Services/AI/OpenAIResponseService.php`
- Widget script: `public/widget.js`

## Remaining increments

1. Build advanced mapping UI preview (sample payload -> transformed output)
2. Add production-grade PDF/DOCX parser integration
3. Add handoff/CRM connectors and deeper sales analytics
