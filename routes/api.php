<?php

use App\Http\Controllers\Admin\AiConfigController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ConversationAdminController;
use App\Http\Controllers\Admin\ImportJobController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\KnowledgeDocumentController;
use App\Http\Controllers\Admin\OrderStatusEventController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SourceMappingPresetController;
use App\Http\Controllers\Admin\TenantAdminController;
use App\Http\Controllers\Admin\TenantUserController;
use App\Http\Controllers\Admin\WidgetAdminController;
use App\Http\Controllers\Admin\WidgetAbuseLogController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\System\HealthController;
use App\Http\Controllers\Webhooks\IntegrationOrderWebhookController;
use App\Http\Controllers\Widget\WidgetConfigController;
use App\Http\Controllers\Widget\WidgetCheckoutController;
use App\Http\Controllers\Widget\WidgetEventController;
use App\Http\Controllers\Widget\WidgetLeadController;
use App\Http\Controllers\Widget\WidgetMessageController;
use App\Http\Controllers\Widget\WidgetSessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('widget')->group(function (): void {
    Route::post('/session/start', [WidgetSessionController::class, 'start'])->middleware(['throttle:widget-session-start', 'widget.guard:none', 'widget.challenge']);
    Route::post('/message', [WidgetMessageController::class, 'message'])->middleware(['throttle:widget-message', 'widget.guard:required']);
    Route::post('/checkout', [WidgetCheckoutController::class, 'upsert'])->middleware(['throttle:widget-checkout', 'widget.guard:required']);
    Route::post('/checkout/confirm', [WidgetCheckoutController::class, 'confirm'])->middleware(['throttle:widget-checkout', 'widget.guard:required']);
    Route::get('/config/{publicKey}', [WidgetConfigController::class, 'show'])->middleware(['throttle:widget-config', 'widget.guard:none']);
    Route::post('/lead', [WidgetLeadController::class, 'store'])->middleware(['throttle:widget-lead', 'widget.guard:required']);
    Route::post('/event', [WidgetEventController::class, 'store'])->middleware(['throttle:widget-event', 'widget.guard:required']);
});

Route::prefix('health')->group(function (): void {
    Route::get('/live', [HealthController::class, 'live']);
    Route::get('/ready', [HealthController::class, 'ready']);
});

Route::prefix('admin/auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/request', [AuthController::class, 'requestPasswordResetLink']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    Route::middleware('auth.token')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/tenants', [TenantAdminController::class, 'index']);
        Route::post('/switch-tenant', [TenantAdminController::class, 'switch']);
        Route::put('/tenants/{id}', [TenantAdminController::class, 'update']);
        Route::delete('/tenants/{id}', [TenantAdminController::class, 'destroy']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::post('/onboarding/bootstrap', [OnboardingController::class, 'bootstrap'])->middleware(['auth.token', 'system.admin']);
Route::post('/webhooks/integrations/{connectionId}/orders/status', [IntegrationOrderWebhookController::class, 'status']);

Route::prefix('admin')->middleware(['auth.token', 'tenant', 'tenant.role:support'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('tenant.role:admin');
    Route::get('/widget-abuse-logs', [WidgetAbuseLogController::class, 'index'])->middleware('tenant.role:admin');
    Route::get('/order-status-events', [OrderStatusEventController::class, 'index'])->middleware('tenant.role:admin');
    Route::get('/users', [TenantUserController::class, 'index'])->middleware('tenant.role:admin');
    Route::post('/users', [TenantUserController::class, 'store'])->middleware('tenant.role:admin');
    Route::put('/users/{id}', [TenantUserController::class, 'update'])->middleware('tenant.role:admin');
    Route::delete('/users/{id}', [TenantUserController::class, 'destroy'])->middleware('tenant.role:admin');
    Route::post('/users/{id}/password-reset-link', [TenantUserController::class, 'sendPasswordResetLink'])->middleware('tenant.role:admin');

    Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('tenant.role:admin');
    Route::post('/integrations', [IntegrationController::class, 'store'])->middleware('tenant.role:admin');
    Route::put('/integrations/{id}', [IntegrationController::class, 'update'])->middleware('tenant.role:admin');
    Route::delete('/integrations/{id}', [IntegrationController::class, 'destroy'])->middleware('tenant.role:admin');
    Route::post('/integrations/{id}/test', [IntegrationController::class, 'test'])->middleware('tenant.role:admin');
    Route::post('/integrations/{id}/sync', [IntegrationController::class, 'sync'])->middleware('tenant.role:admin');
    Route::get('/integrations/{id}/mapping-presets', [SourceMappingPresetController::class, 'index'])->middleware('tenant.role:editor');
    Route::post('/integrations/{id}/mapping-presets', [SourceMappingPresetController::class, 'store'])->middleware('tenant.role:editor');
    Route::put('/mapping-presets/{id}', [SourceMappingPresetController::class, 'update'])->middleware('tenant.role:editor');
    Route::post('/mapping-presets/{id}/apply', [SourceMappingPresetController::class, 'apply'])->middleware('tenant.role:editor');
    Route::delete('/mapping-presets/{id}', [SourceMappingPresetController::class, 'destroy'])->middleware('tenant.role:editor');

    Route::get('/import-jobs', [ImportJobController::class, 'index'])->middleware('tenant.role:editor');
    Route::get('/import-jobs/{id}', [ImportJobController::class, 'show'])->middleware('tenant.role:editor');
    Route::put('/import-jobs/{id}', [ImportJobController::class, 'update'])->middleware('tenant.role:admin');
    Route::delete('/import-jobs/{id}', [ImportJobController::class, 'destroy'])->middleware('tenant.role:admin');

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store'])->middleware('tenant.role:editor');
    Route::delete('/products', [ProductController::class, 'destroyAll'])->middleware('tenant.role:owner');
    Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('tenant.role:editor');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('tenant.role:editor');
    Route::post('/products/import/csv', [ProductController::class, 'importCsv'])->middleware('tenant.role:editor');

    Route::get('/knowledge-documents', [KnowledgeDocumentController::class, 'index']);
    Route::post('/knowledge-documents/upload', [KnowledgeDocumentController::class, 'upload'])->middleware('tenant.role:editor');
    Route::post('/knowledge-documents/text', [KnowledgeDocumentController::class, 'storeText'])->middleware('tenant.role:editor');
    Route::put('/knowledge-documents/{id}', [KnowledgeDocumentController::class, 'update'])->middleware('tenant.role:editor');
    Route::delete('/knowledge-documents/{id}', [KnowledgeDocumentController::class, 'destroy'])->middleware('tenant.role:editor');
    Route::post('/knowledge-documents/{id}/reindex', [KnowledgeDocumentController::class, 'reindex'])->middleware('tenant.role:editor');

    Route::get('/widgets', [WidgetAdminController::class, 'index'])->middleware('tenant.role:admin');
    Route::post('/widgets', [WidgetAdminController::class, 'store'])->middleware('tenant.role:admin');
    Route::put('/widgets/{id}', [WidgetAdminController::class, 'update'])->middleware('tenant.role:admin');
    Route::delete('/widgets/{id}', [WidgetAdminController::class, 'destroy'])->middleware('tenant.role:admin');
    Route::get('/ai-config', [AiConfigController::class, 'show'])->middleware('tenant.role:admin');
    Route::put('/ai-config', [AiConfigController::class, 'update'])->middleware('tenant.role:admin');

    Route::get('/conversations', [ConversationAdminController::class, 'index']);
    Route::get('/conversations/{id}/messages', [ConversationAdminController::class, 'showMessages']);
    Route::put('/conversations/{id}', [ConversationAdminController::class, 'update'])->middleware('tenant.role:editor');
    Route::delete('/conversations/{id}', [ConversationAdminController::class, 'destroy'])->middleware('tenant.role:admin');

    Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
});
