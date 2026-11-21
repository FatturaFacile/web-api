<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_rfqs', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('incoterms', 16)->nullable();
            $table->timestampTz('submission_deadline')->nullable()->index();
            $table->date('expected_delivery_date')->nullable();
            $table->json('evaluation_weights')->nullable();
            $table->boolean('sealed_bidding')->default(false);
            $table->uuid('buyer_user_id')->nullable()->index();
            $table->timestampTz('awarded_at')->nullable();
            $table->uuid('awarded_by_user_id')->nullable();
            $table->string('award_mode', 20)->nullable();
        });

        Schema::table('procurement_rfq_lines', function (Blueprint $table): void {
            $table->uuid('item_id')->nullable()->index();
            $table->string('custom_item_code', 100)->nullable();
            $table->text('technical_specifications')->nullable();
            $table->decimal('target_unit_price', 18, 4)->nullable();
            $table->uuid('awarded_supplier_id')->nullable()->index();
            $table->uuid('awarded_bid_line_id')->nullable()->index();
            $table->uuid('generated_purchase_order_id')->nullable()->index();
        });

        Schema::create('procurement_rfq_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path', 500);
            $table->string('sha256_hash', 64);
            $table->boolean('is_visible_to_suppliers')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'rfq_id'], 'idx_rfq_attachments_tenant_rfq');
        });

        Schema::table('procurement_rfq_suppliers', function (Blueprint $table): void {
            $table->string('access_token_hash', 64)->nullable()->unique('uq_rfq_supplier_token_hash');
            $table->timestampTz('token_expires_at')->nullable()->index();
            $table->timestampTz('first_viewed_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->text('declined_reason')->nullable();
            $table->timestampTz('notified_outcome_at')->nullable();
        });

        Schema::table('procurement_supplier_quotes', function (Blueprint $table): void {
            $table->uuid('invitation_id')->nullable()->index();
            $table->decimal('exchange_rate_to_rfq', 18, 6)->default(1.000000);
            $table->integer('payment_terms_days')->default(30);
            $table->string('incoterms', 16)->nullable();
            $table->decimal('shipping_cost', 18, 4)->default(0);
            $table->decimal('total_net_amount', 18, 4)->default(0);
            $table->decimal('total_landed_cost', 18, 4)->default(0);
            $table->decimal('weighted_overall_score', 5, 2)->nullable();
            $table->string('submitted_ip_address', 45)->nullable();
            $table->text('submitted_user_agent')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->string('quote_attachment_file_name', 255)->nullable();
            $table->string('quote_attachment_mime_type', 120)->nullable();
            $table->unsignedBigInteger('quote_attachment_size_bytes')->nullable();
            $table->string('quote_attachment_disk', 50)->nullable();
            $table->string('quote_attachment_path', 500)->nullable();
            $table->string('quote_attachment_sha256', 64)->nullable();
        });

        Schema::table('procurement_supplier_quote_lines', function (Blueprint $table): void {
            $table->boolean('is_no_bid')->default(false);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('net_unit_price', 18, 4)->default(0);
            $table->decimal('line_total_net', 18, 4)->default(0);
            $table->date('promised_delivery_date')->nullable();
            $table->text('supplier_notes')->nullable();
            $table->decimal('line_weighted_score', 5, 2)->nullable();
            $table->boolean('is_awarded')->default(false)->index();
        });

        Schema::table('purchase_requisition_lines', function (Blueprint $table): void {
            $table->decimal('quantity_quoted', 12, 4)->default(0);
            $table->decimal('quantity_ordered', 12, 4)->default(0);
            $table->string('sourcing_status', 30)->default('open')->index();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->uuid('rfq_line_id')->nullable()->index();
            $table->uuid('supplier_bid_line_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropIndex(['rfq_line_id']);
            $table->dropIndex(['supplier_bid_line_id']);
            $table->dropColumn(['rfq_line_id', 'supplier_bid_line_id']);
        });

        Schema::table('purchase_requisition_lines', function (Blueprint $table): void {
            $table->dropIndex(['sourcing_status']);
            $table->dropColumn(['quantity_quoted', 'quantity_ordered', 'sourcing_status']);
        });

        Schema::table('procurement_supplier_quote_lines', function (Blueprint $table): void {
            $table->dropIndex(['is_awarded']);
            $table->dropColumn([
                'is_no_bid',
                'discount_percent',
                'net_unit_price',
                'line_total_net',
                'promised_delivery_date',
                'supplier_notes',
                'line_weighted_score',
                'is_awarded',
            ]);
        });

        Schema::table('procurement_supplier_quotes', function (Blueprint $table): void {
            $table->dropIndex(['invitation_id']);
            $table->dropColumn([
                'invitation_id',
                'exchange_rate_to_rfq',
                'payment_terms_days',
                'incoterms',
                'shipping_cost',
                'total_net_amount',
                'total_landed_cost',
                'weighted_overall_score',
                'submitted_ip_address',
                'submitted_user_agent',
                'submitted_at',
                'quote_attachment_file_name',
                'quote_attachment_mime_type',
                'quote_attachment_size_bytes',
                'quote_attachment_disk',
                'quote_attachment_path',
                'quote_attachment_sha256',
            ]);
        });

        Schema::table('procurement_rfq_suppliers', function (Blueprint $table): void {
            $table->dropUnique('uq_rfq_supplier_token_hash');
            $table->dropIndex(['token_expires_at']);
            $table->dropColumn([
                'access_token_hash',
                'token_expires_at',
                'first_viewed_at',
                'submitted_at',
                'declined_reason',
                'notified_outcome_at',
            ]);
        });

        Schema::dropIfExists('procurement_rfq_attachments');

        Schema::table('procurement_rfq_lines', function (Blueprint $table): void {
            $table->dropIndex(['item_id']);
            $table->dropIndex(['awarded_supplier_id']);
            $table->dropIndex(['awarded_bid_line_id']);
            $table->dropIndex(['generated_purchase_order_id']);
            $table->dropColumn([
                'item_id',
                'custom_item_code',
                'technical_specifications',
                'target_unit_price',
                'awarded_supplier_id',
                'awarded_bid_line_id',
                'generated_purchase_order_id',
            ]);
        });

        Schema::table('procurement_rfqs', function (Blueprint $table): void {
            $table->dropIndex(['submission_deadline']);
            $table->dropIndex(['buyer_user_id']);
            $table->dropColumn([
                'description',
                'incoterms',
                'submission_deadline',
                'expected_delivery_date',
                'evaluation_weights',
                'sealed_bidding',
                'buyer_user_id',
                'awarded_at',
                'awarded_by_user_id',
                'award_mode',
            ]);
        });
    }
};
