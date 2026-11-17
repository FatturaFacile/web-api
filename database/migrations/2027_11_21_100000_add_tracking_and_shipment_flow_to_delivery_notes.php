<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDT tracking and triangular / dropshipping shipments: a generated tracking code and the carrier waybill (AWB / lettera
 * di vettura) on the delivery note, the shipment flow, the sender printed on the document and the final recipient.
 * Portable schema-builder syntax only (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_notes', 'tracking_code')) {
                $table->string('tracking_code', 40)->nullable()->index();
            }
            if (! Schema::hasColumn('delivery_notes', 'awb_number')) {
                $table->string('awb_number', 60)->nullable()->index();
            }
            if (! Schema::hasColumn('delivery_notes', 'shipment_flow')) {
                $table->string('shipment_flow', 20)->default('direct'); // direct, triangular, dropshipping
            }
            if (! Schema::hasColumn('delivery_notes', 'sender_display')) {
                $table->string('sender_display', 20)->default('company'); // company, blank, custom
            }
            if (! Schema::hasColumn('delivery_notes', 'sender_name')) {
                $table->string('sender_name', 255)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'sender_address')) {
                $table->string('sender_address', 1000)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'final_recipient_name')) {
                $table->string('final_recipient_name', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table): void {
            foreach (['tracking_code', 'awb_number', 'shipment_flow', 'sender_display', 'sender_name', 'sender_address', 'final_recipient_name'] as $column) {
                if (Schema::hasColumn('delivery_notes', $column)) {
                    if (in_array($column, ['tracking_code', 'awb_number'], true)) {
                        $table->dropIndex([$column]);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
