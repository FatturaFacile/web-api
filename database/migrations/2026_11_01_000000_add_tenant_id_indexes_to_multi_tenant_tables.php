<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tables requiring an index on tenant_id.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'attachments',
        'billable_activities',
        'bills_of_materials',
        'conformity_certificates',
        'damage_inspections',
        'document_emails',
        'document_events',
        'ei_outbound_logs',
        'loyalty_transactions',
        'payments',
        'payment_batches',
        'payment_imports',
        'payment_reminders',
        'pos_sales',
        'pos_sessions',
        'progress_billings',
        'rental_bookings',
        'rental_vehicles',
        'sdi_uuid_links',
        'subscription_runs',
        'supplier_payments',
        'vehicle_inspections',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->index('tenant_id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropIndex(['tenant_id']);
                });
            }
        }
    }
};
