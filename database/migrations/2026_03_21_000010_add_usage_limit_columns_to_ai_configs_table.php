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
        Schema::table('ai_configs', function (Blueprint $table): void {
            $table->unsignedInteger('max_messages_monthly')->nullable()->after('max_output_tokens');
            $table->unsignedInteger('max_tokens_daily')->nullable()->after('max_messages_monthly');
            $table->unsignedInteger('max_tokens_monthly')->nullable()->after('max_tokens_daily');
            $table->boolean('block_on_limit')->default(true)->after('max_tokens_monthly');
            $table->boolean('alert_on_limit')->default(true)->after('block_on_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_configs', function (Blueprint $table): void {
            $table->dropColumn([
                'max_messages_monthly',
                'max_tokens_daily',
                'max_tokens_monthly',
                'block_on_limit',
                'alert_on_limit',
            ]);
        });
    }
};

