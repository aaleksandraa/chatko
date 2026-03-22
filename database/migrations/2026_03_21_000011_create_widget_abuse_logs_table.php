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
        Schema::create('widget_abuse_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('widget_id')->nullable()->constrained('widgets')->nullOnDelete();
            $table->string('public_key')->nullable();
            $table->string('route', 255);
            $table->string('http_method', 16);
            $table->string('reason', 96);
            $table->string('ip_address', 64)->nullable();
            $table->string('origin', 255)->nullable();
            $table->string('referer', 512)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['widget_id', 'created_at']);
            $table->index(['reason', 'created_at']);
            $table->index(['public_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_abuse_logs');
    }
};

