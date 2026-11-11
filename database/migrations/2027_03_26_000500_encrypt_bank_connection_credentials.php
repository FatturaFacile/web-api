<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank credentials were stored as plain JSON. The column becomes text holding the value encrypted with the application
     * key, the format of the `encrypted:array` cast; each existing row is encrypted in place, inside the migration
     * transaction, and a value that already decrypts is left as it is so a rerun cannot encrypt twice.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bank_connections ALTER COLUMN auth_credentials_json TYPE text USING auth_credentials_json::text');
        } else {
            Schema::table('bank_connections', function (Blueprint $table): void {
                $table->text('auth_credentials_json')->nullable()->change();
            });
        }

        DB::table('bank_connections')->whereNotNull('auth_credentials_json')->orderBy('id')->lazyById(100, 'id')->each(function (object $row): void {
            if ($this->isEncrypted((string) $row->auth_credentials_json)) {
                return;
            }

            DB::table('bank_connections')->where('id', $row->id)->update(['auth_credentials_json' => Crypt::encryptString((string) $row->auth_credentials_json)]);
        });
    }

    public function down(): void
    {
        DB::table('bank_connections')->whereNotNull('auth_credentials_json')->orderBy('id')->lazyById(100, 'id')->each(function (object $row): void {
            if ($this->isEncrypted((string) $row->auth_credentials_json)) {
                DB::table('bank_connections')->where('id', $row->id)->update(['auth_credentials_json' => Crypt::decryptString((string) $row->auth_credentials_json)]);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bank_connections ALTER COLUMN auth_credentials_json TYPE jsonb USING auth_credentials_json::jsonb');
        } else {
            Schema::table('bank_connections', function (Blueprint $table): void {
                $table->json('auth_credentials_json')->nullable()->change();
            });
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
