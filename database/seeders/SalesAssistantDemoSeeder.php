<?php

namespace Database\Seeders;

use App\Models\AiConfig;
use App\Models\KnowledgeDocument;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SalesAssistantDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantName = (string) env('DEMO_TENANT_NAME', 'Demo Shop');
        $tenantSlug = (string) env('DEMO_TENANT_SLUG', 'demo-shop');
        $ownerName = (string) env('DEMO_OWNER_NAME', 'Demo Owner');
        $ownerEmail = (string) env('DEMO_OWNER_EMAIL', 'owner@demo.local');
        $ownerPassword = (string) env('DEMO_OWNER_PASSWORD', 'password123');
        $ownerIsSystemAdmin = filter_var(env('DEMO_OWNER_IS_SYSTEM_ADMIN', false), FILTER_VALIDATE_BOOL);
        $systemAdminName = (string) env('SYSTEM_ADMIN_NAME', 'System Admin');
        $systemAdminEmail = (string) env('SYSTEM_ADMIN_EMAIL', 'system@demo.local');
        $systemAdminPassword = (string) env('SYSTEM_ADMIN_PASSWORD', 'password123');
        $systemAdminAttachDemoTenant = filter_var(env('SYSTEM_ADMIN_ATTACH_DEMO_TENANT', true), FILTER_VALIDATE_BOOL);
        $systemAdminDemoRoleRaw = strtolower(trim((string) env('SYSTEM_ADMIN_DEMO_ROLE', 'admin')));
        $systemAdminDemoRole = in_array($systemAdminDemoRoleRaw, ['support', 'editor', 'admin', 'owner'], true)
            ? $systemAdminDemoRoleRaw
            : 'admin';
        $supportEmail = (string) env('DEMO_SUPPORT_EMAIL', 'support@demo.local');
        $ownerPasswordHash = Hash::make($ownerPassword);
        $systemAdminPasswordHash = Hash::make($systemAdminPassword);

        $plan = Plan::query()->firstOrCreate(
            ['code' => 'starter'],
            [
                'name' => 'Starter',
                'max_products' => 500,
                'max_messages_monthly' => 5000,
                'max_widgets' => 1,
                'features_json' => ['knowledge_upload' => true, 'csv_import' => true],
            ],
        );

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $tenantSlug],
            [
                'uuid' => (string) Str::uuid(),
                'name' => $tenantName,
                'domain' => 'demo.local',
                'status' => 'active',
                'plan_id' => $plan->id,
                'locale' => 'bs',
                'timezone' => 'Europe/Sarajevo',
                'brand_name' => $tenantName,
                'support_email' => $supportEmail,
            ],
        );

        $tenant->update([
            'name' => $tenantName,
            'brand_name' => $tenantName,
            'support_email' => $supportEmail,
        ]);

        $owner = User::query()->updateOrCreate(
            ['email' => $ownerEmail],
            [
                'name' => $ownerName,
                'password' => $ownerPasswordHash,
                'is_system_admin' => $ownerIsSystemAdmin,
            ],
        );

        $tenant->users()->syncWithoutDetaching([
            $owner->id => ['role' => 'owner'],
        ]);

        $systemAdmin = User::query()->updateOrCreate(
            ['email' => $systemAdminEmail],
            [
                'name' => $systemAdminName,
                'password' => $systemAdminPasswordHash,
                'is_system_admin' => true,
            ],
        );

        if ($systemAdminAttachDemoTenant) {
            $tenant->users()->syncWithoutDetaching([
                $systemAdmin->id => ['role' => $systemAdminDemoRole],
            ]);
        }

        AiConfig::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'provider' => 'openai',
                'model_name' => (string) config('services.openai.default_model', 'gpt-5-mini'),
                'embedding_model' => 'text-embedding-3-small',
                'temperature' => 0.30,
                'max_output_tokens' => max(64, (int) config('services.openai.default_max_output_tokens', 350)),
                'top_p' => 1.00,
                'sales_rules_json' => [
                    'max_recommendations' => 3,
                    'offer_alternative_if_no_fit' => true,
                ],
            ],
        );

        $widget = Widget::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Main Widget'],
            [
                'public_key' => 'wpk_demo_'.Str::random(12),
                'secret_key' => 'wsk_demo_'.Str::random(24),
                'allowed_domains_json' => ['http://localhost', 'https://localhost'],
                'theme_json' => [
                    'primary_color' => '#0E9F6E',
                    'accent_color' => '#063F2B',
                ],
                'default_locale' => 'bs',
                'is_active' => true,
            ],
        );

        Product::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => 'SERUM-001'],
            [
                'source_type' => 'manual',
                'name' => 'Hydra Serum Sensitive',
                'short_description' => 'Serum za suhu i osjetljivu kozu.',
                'long_description' => 'Lagana formula sa hijaluronom i pantenolom za svakodnevnu hidrataciju.',
                'price' => 34.90,
                'currency' => 'BAM',
                'stock_qty' => 24,
                'in_stock' => true,
                'product_url' => 'https://demo.local/products/hydra-serum-sensitive',
                'primary_image_url' => 'https://images.unsplash.com/photo-1556228720-da4e85f25e4f',
                'category_text' => 'njega-koze',
                'brand_text' => 'DermaLab',
                'status' => 'active',
            ],
        );

        Product::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => 'CREAM-002'],
            [
                'source_type' => 'manual',
                'name' => 'Barrier Repair Krema',
                'short_description' => 'Obnavljajuca krema za suhu kozu.',
                'long_description' => 'Krema sa ceramidima za obnovu zastitne barijere i smanjenje iritacija.',
                'price' => 29.50,
                'currency' => 'BAM',
                'stock_qty' => 15,
                'in_stock' => true,
                'product_url' => 'https://demo.local/products/barrier-repair-krema',
                'primary_image_url' => 'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9',
                'category_text' => 'njega-koze',
                'brand_text' => 'SkinCare Pro',
                'status' => 'active',
            ],
        );

        KnowledgeDocument::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Dostava i povrat'],
            [
                'source_type' => 'manual_text',
                'type' => 'shipping_policy',
                'language' => 'bs',
                'visibility' => 'public_for_ai',
                'ai_allowed' => true,
                'internal_only' => false,
                'status' => 'indexed',
                'content_raw' => 'Dostava traje 1-3 radna dana u BiH. Povrat je moguc u roku od 14 dana uz fiskalni racun.',
                'content_clean' => 'Dostava traje 1-3 radna dana u BiH. Povrat je moguc u roku od 14 dana uz fiskalni racun.',
            ],
        );

        $this->command->info('Demo tenant created.');
        $this->command->info('Widget public key: '.$widget->public_key);
        $this->command->info('Use header X-Tenant-Slug: '.$tenant->slug.' for admin API routes.');
        $this->command->info("System admin login: {$systemAdminEmail} / {$systemAdminPassword} (tenant_slug: {$tenantSlug})");
        $this->command->info("Admin login: {$ownerEmail} / {$ownerPassword} (tenant_slug: {$tenantSlug})");
    }
}

