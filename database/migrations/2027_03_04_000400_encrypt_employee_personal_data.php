<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypted columns need text, and an index on ciphertext is useless because each value encrypts differently.
     *
     * @var array<string, array{columns: list<string>, indexed: list<string>}>
     */
    private array $tables = [
        'hr_employees' => [
            'columns' => ['personal_email', 'phone', 'tax_code', 'social_security_number'],
            'indexed' => ['tax_code'],
        ],
        'employee_profiles' => [
            'columns' => ['social_security_number'],
            'indexed' => ['social_security_number'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $name => $table) {
            Schema::table($name, function (Blueprint $blueprint) use ($table): void {
                foreach ($table['indexed'] as $column) {
                    $blueprint->dropIndex([$column]);
                }

                foreach ($table['columns'] as $column) {
                    $blueprint->text($column)->nullable()->change();
                }
            });

            $this->convert($name, $table['columns'], fn (string $value): string => Crypt::encryptString($value));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $name => $table) {
            $this->convert($name, $table['columns'], fn (string $value): string => Crypt::decryptString($value));
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  callable(string): string  $transform
     */
    private function convert(string $table, array $columns, callable $transform): void
    {
        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $transform): void {
            foreach ($rows as $row) {
                $values = [];

                foreach ($columns as $column) {
                    if ($row->{$column} !== null) {
                        $values[$column] = $transform($row->{$column});
                    }
                }

                if ($values !== []) {
                    DB::table($table)->where('id', $row->id)->update($values);
                }
            }
        });
    }
};
