<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = [
        'customs_tariff_numbers', 'customs_statistical_keys', 'customs_packaging_types', 'customs_countries', 'customs_document_types',
    ];

    /**
     * Code tables of the customs domain. A row without a tenant is generic and read-only for the companies, which can
     * add or override a code with a row of their own. Only official BAZG master data is loaded as generic.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('code', 12);

                if ($name === 'customs_statistical_keys') {
                    $table->string('tariff_code', 12);
                }

                $table->string('name_de', 500)->nullable();
                $table->string('name_fr', 500)->nullable();
                $table->string('name_it', 500)->nullable();
                $table->string('name_en', 500)->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('source', 60)->nullable();
                $table->timestamps();

                $key = $name === 'customs_statistical_keys' ? ['tenant_id', 'tariff_code', 'code'] : ['tenant_id', 'code'];
                $table->unique($key, "{$name}_unique")->nullsNotDistinct();
                $table->index('code', "{$name}_code_idx");
            });
        }

        Schema::create('customs_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('channel', 20)->default('local');
            $table->boolean('auto_retrieve')->default(false);
            $table->string('trader_identification_number', 17)->nullable();
            $table->unsignedInteger('declarant_number')->nullable();
            $table->string('declarant_name', 35)->nullable();
            $table->string('declarant_street', 35)->nullable();
            $table->string('declarant_postal_code', 9)->nullable();
            $table->string('declarant_city', 35)->nullable();
            $table->string('declarant_country', 2)->nullable();
            $table->string('customs_account', 8)->nullable();
            $table->string('vat_account', 8)->nullable();
            $table->string('vat_number', 12)->nullable();
            $table->unsignedTinyInteger('receipt_role')->nullable();
            $table->uuid('duty_expense_account_id')->nullable();
            $table->uuid('customs_payable_account_id')->nullable();
            $table->uuid('import_vat_account_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_settings');

        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
