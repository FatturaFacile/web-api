<?php

declare(strict_types=1);

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
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('subscription_number', 50);
            $table->string('name');
            $table->string('status', 20)->default('active'); // active, paused, cancelled, expired
            $table->string('interval', 20)->default('monthly'); // monthly, quarterly, semiannual, yearly
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->string('currency', 3)->default('EUR');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->timestamp('last_billed_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('notify_days_before')->default(30);
            $table->timestamp('renewal_notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'subscription_number']);
            $table->index(['status', 'next_billing_date']);
        });

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20); // success, failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start']); // idempotent billing
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subscription_id');
        });

        Schema::dropIfExists('subscription_runs');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
