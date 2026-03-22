<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedInteger('max_products')->default(500);
            $table->unsignedInteger('max_messages_monthly')->default(5000);
            $table->unsignedInteger('max_widgets')->default(1);
            $table->json('features_json')->nullable();
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('locale')->default('bs');
            $table->string('timezone')->default('Europe/Sarajevo');
            $table->string('brand_name')->nullable();
            $table->string('support_email')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('base_url')->nullable();
            $table->text('credentials_encrypted')->nullable();
            $table->string('auth_type')->nullable();
            $table->json('config_json')->nullable();
            $table->json('mapping_json')->nullable();
            $table->string('sync_frequency')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('job_type');
            $table->string('source_type');
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('processed_records')->default(0);
            $table->unsignedInteger('success_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->unsignedInteger('skipped_records')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->text('log_summary')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'source_type']);
        });

        Schema::create('import_job_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->string('external_row_ref')->nullable();
            $table->unsignedInteger('row_index');
            $table->json('raw_payload_json')->nullable();
            $table->json('normalized_payload_json')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['import_job_id', 'status']);
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->foreignId('source_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type')->default('manual');
            $table->foreignId('source_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('slug')->nullable();
            $table->string('name');
            $table->text('short_description')->nullable();
            $table->longText('long_description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('currency', 8)->default('BAM');
            $table->integer('stock_qty')->nullable();
            $table->boolean('in_stock')->default(true);
            $table->string('availability_label')->nullable();
            $table->string('product_url')->nullable();
            $table->string('primary_image_url')->nullable();
            $table->string('category_text')->nullable();
            $table->string('brand_text')->nullable();
            $table->json('attributes_json')->nullable();
            $table->json('specs_json')->nullable();
            $table->json('tags_json')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'in_stock']);
            $table->index(['tenant_id', 'price']);
            $table->index(['tenant_id', 'category_text']);
            $table->unique(['tenant_id', 'source_type', 'external_id']);
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('media_url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('alt_text')->nullable();
            $table->timestamps();
        });

        Schema::create('product_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->longText('embedded_text');
            $table->json('embedding_vector')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id']);
        });

        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type')->default('manual');
            $table->string('title');
            $table->string('type')->default('faq');
            $table->string('language', 16)->default('bs');
            $table->string('visibility')->default('public_for_ai');
            $table->boolean('ai_allowed')->default(true);
            $table->boolean('internal_only')->default(false);
            $table->string('status')->default('uploaded');
            $table->unsignedInteger('version')->default(1);
            $table->string('original_file_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('source_ref')->nullable();
            $table->json('tags_json')->nullable();
            $table->longText('content_raw')->nullable();
            $table->longText('content_clean')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'visibility']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('knowledge_document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('chunk_text');
            $table->json('metadata_json')->nullable();
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->json('embedding_vector')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'knowledge_document_id']);
        });

        Schema::create('source_mapping_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('name');
            $table->json('mapping_json');
            $table->timestamps();
        });

        Schema::create('sync_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('frequency_type')->default('manual');
            $table->string('frequency_value')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('public_key')->unique();
            $table->string('secret_key')->unique();
            $table->json('allowed_domains_json')->nullable();
            $table->json('theme_json')->nullable();
            $table->string('default_locale', 16)->default('bs');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider')->default('openai');
            $table->string('model_name')->default('gpt-5-mini');
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->decimal('temperature', 4, 2)->default(0.30);
            $table->unsignedInteger('max_output_tokens')->default(600);
            $table->decimal('top_p', 4, 2)->default(1.00);
            $table->json('safety_rules_json')->nullable();
            $table->longText('system_prompt_template')->nullable();
            $table->json('sales_rules_json')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();
            $table->uuid('visitor_uuid');
            $table->string('session_id');
            $table->string('channel')->default('web_widget');
            $table->string('locale', 16)->default('bs');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('source_url')->nullable();
            $table->json('utm_json')->nullable();
            $table->string('status')->default('active');
            $table->boolean('lead_captured')->default(false);
            $table->boolean('converted')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'widget_id']);
            $table->index(['tenant_id', 'visitor_uuid']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('role');
            $table->longText('message_text');
            $table->longText('normalized_text')->nullable();
            $table->string('intent')->nullable();
            $table->json('metadata_json')->nullable();
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'conversation_id']);
            $table->index(['tenant_id', 'intent']);
        });

        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->boolean('consent')->default(false);
            $table->string('lead_status')->default('new');
            $table->timestamps();
            $table->index(['tenant_id', 'lead_status']);
        });

        Schema::create('orders_attributed', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('external_order_id');
            $table->decimal('order_value', 12, 2)->default(0);
            $table->string('currency', 8)->default('BAM');
            $table->string('attributed_model')->default('last_touch_assistant');
            $table->timestamps();
            $table->index(['tenant_id', 'external_order_id']);
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->uuid('visitor_uuid')->nullable();
            $table->string('event_name');
            $table->decimal('event_value', 12, 2)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'event_name']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('orders_attributed');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('ai_configs');
        Schema::dropIfExists('widgets');
        Schema::dropIfExists('sync_schedules');
        Schema::dropIfExists('source_mapping_presets');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('product_embeddings');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('import_job_rows');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};

