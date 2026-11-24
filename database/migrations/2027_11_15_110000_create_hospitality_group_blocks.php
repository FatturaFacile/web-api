<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group bookings: a block of rooms per room type held for a group between a check-in and a check-out date, released
     * on a release date, with the stays picked up from it. A stay may point at its block and, once its folio is
     * invoiced, at the invoice (plain columns: the lodging tables of this module carry no foreign keys).
     */
    public function up(): void
    {
        Schema::create('hosp_group_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('name', 160);
            $table->string('organizer', 160)->nullable();
            $table->uuid('contact_id')->nullable();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->date('release_date');
            $table->string('status', 20)->default('open');
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'check_in_date']);
        });

        Schema::create('hosp_group_block_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('group_block_id')->constrained('hosp_group_blocks')->cascadeOnDelete();
            $table->uuid('room_type_id');
            $table->unsignedSmallInteger('rooms_blocked');
            $table->decimal('nightly_rate', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['group_block_id', 'room_type_id']);
            $table->index(['tenant_id', 'room_type_id']);
        });

        Schema::table('hosp_stays', function (Blueprint $table): void {
            $table->uuid('group_block_id')->nullable()->index();
            $table->uuid('invoice_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hosp_stays', function (Blueprint $table): void {
            $table->dropIndex(['group_block_id']);
            $table->dropColumn(['group_block_id', 'invoice_id']);
        });
        Schema::dropIfExists('hosp_group_block_rooms');
        Schema::dropIfExists('hosp_group_blocks');
    }
};
