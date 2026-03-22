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
        Schema::table('orders_attributed', function (Blueprint $table): void {
            $table->string('last_status', 32)->nullable()->after('attributed_model');
            $table->timestamp('last_status_at')->nullable()->after('last_status');
            $table->json('status_payload_json')->nullable()->after('last_status_at');
            $table->index(['tenant_id', 'last_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_attributed', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'last_status']);
            $table->dropColumn(['last_status', 'last_status_at', 'status_payload_json']);
        });
    }
};
