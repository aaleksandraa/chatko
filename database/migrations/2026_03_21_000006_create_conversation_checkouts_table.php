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
        Schema::create('conversation_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('status')->default('collecting_customer');
            $table->json('items_json')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_postal_code')->nullable();
            $table->string('delivery_country', 2)->nullable();
            $table->text('customer_note')->nullable();
            $table->string('payment_method')->default('cod');
            $table->decimal('estimated_total', 12, 2)->default(0);
            $table->string('currency', 8)->default('BAM');
            $table->string('external_order_id')->nullable();
            $table->string('external_checkout_url')->nullable();
            $table->json('external_response_json')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'conversation_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_checkouts');
    }
};
