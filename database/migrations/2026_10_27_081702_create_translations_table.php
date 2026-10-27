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
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('locale', 5); // es. 'it', 'en', 'de', 'fr'
            $table->string('group')->default('messages'); // opzionale, per raggruppare (es. auth, common, errors)
            $table->string('key'); // es. 'welcome_message', 'buttons.save'
            $table->text('value'); // il testo tradotto
            $table->timestamps();

            // Indice unico per evitare duplicati per la stessa lingua e chiave
            $table->unique(['tenant_id', 'locale', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
