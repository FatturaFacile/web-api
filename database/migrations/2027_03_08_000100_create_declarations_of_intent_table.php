<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declarations of intent (lettere di intento). `received` ones come from a customer and block the sales above the
     * plafond; `issued` ones were sent to a supplier and only track what was bought under them.
     */
    public function up(): void
    {
        Schema::create('declarations_of_intent', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts');
            $table->string('direction', 10)->default('received');
            $table->string('protocol_number', 30);
            $table->date('receipt_date');
            $table->unsignedSmallInteger('year');
            $table->decimal('plafond_amount', 15, 2);
            $table->decimal('consumed_amount', 15, 2)->default(0);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'direction', 'protocol_number']);
            $table->index(['tenant_id', 'contact_id', 'direction', 'valid_from', 'valid_to'], 'declarations_of_intent_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declarations_of_intent');
    }
};
