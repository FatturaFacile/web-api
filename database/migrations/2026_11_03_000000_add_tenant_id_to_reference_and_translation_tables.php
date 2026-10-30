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
        $tables = [
            'approval_statuses',
            'approval_status_translations',
            'contact_categories',
            'contact_category_translations',
            'contact_types',
            'contact_type_translations',
            'countries',
            'country_translations',
            'currencies',
            'currency_translations',
            'document_statuses',
            'document_status_translations',
            'expense_categories',
            'expense_category_translations',
            'inventory_movement_types',
            'inventory_movement_type_translations',
            'notification_types',
            'notification_type_translations',
            'payment_methods',
            'payment_method_translations',
            'payment_term_translations',
            'project_statuses',
            'project_status_translations',
            'regions',
            'region_translations',
            'task_statuses',
            'task_status_translations',
            'vat_codes',
            'vat_code_translations',
            'workflow_states',
            'workflow_state_translations',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->uuid('tenant_id')->nullable()->index();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'approval_statuses',
            'approval_status_translations',
            'contact_categories',
            'contact_category_translations',
            'contact_types',
            'contact_type_translations',
            'countries',
            'country_translations',
            'currencies',
            'currency_translations',
            'document_statuses',
            'document_status_translations',
            'expense_categories',
            'expense_category_translations',
            'inventory_movement_types',
            'inventory_movement_type_translations',
            'notification_types',
            'notification_type_translations',
            'payment_methods',
            'payment_method_translations',
            'payment_term_translations',
            'project_statuses',
            'project_status_translations',
            'regions',
            'region_translations',
            'task_statuses',
            'task_status_translations',
            'vat_codes',
            'vat_code_translations',
            'workflow_states',
            'workflow_state_translations',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
