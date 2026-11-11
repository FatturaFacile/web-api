<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preservation packages: the technical basis of long-term document preservation. This is NOT a certified conservatore
 * (AgID accreditation). On PostgreSQL, triggers back the immutability that the models and policies already enforce, so
 * a closed package, its files and the verification and access records cannot be changed by a raw query either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preservation_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->jsonb('retention_years')->nullable();
            $table->timestamp('retention_confirmed_at')->nullable();
            $table->uuid('retention_confirmed_by')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('preservation_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document_class', 30);
            $table->string('name', 200);
            $table->string('status', 10)->default('open');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedSmallInteger('retention_years')->nullable();
            $table->string('retention_source', 30)->nullable();
            $table->date('retention_until')->nullable();
            $table->longText('manifest_json')->nullable();
            $table->char('package_hash', 64)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->string('timestamp_provider', 60)->nullable();
            $table->text('timestamp_token')->nullable();
            $table->boolean('timestamp_qualified')->default(false);
            $table->timestamp('timestamped_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_class', 'status']);
        });

        Schema::create('preservation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('package_id')->constrained('preservation_packages')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('document_class', 30);
            $table->string('source_type', 30);
            $table->uuid('source_id');
            $table->string('file_name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('storage_disk', 60);
            $table->string('storage_path', 1024);
            $table->string('document_type', 60)->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_number', 100)->nullable();
            $table->string('counterpart_name', 255)->nullable();
            $table->string('counterpart_vat_number', 40)->nullable();
            $table->string('own_vat_number', 40)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'source_type', 'source_id'], 'uq_preservation_item_source');
            $table->unique(['package_id', 'sequence'], 'uq_preservation_item_sequence');
            $table->index(['tenant_id', 'document_class', 'document_date'], 'idx_preservation_items_class_date');
            $table->index(['tenant_id', 'counterpart_vat_number'], 'idx_preservation_items_vat');
            $table->index(['tenant_id', 'document_number'], 'idx_preservation_items_number');
        });

        Schema::create('preservation_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('package_id')->index();
            $table->string('result', 10);
            $table->string('trigger', 10);
            $table->boolean('manifest_ok');
            $table->unsignedInteger('checked_items');
            $table->unsignedInteger('failed_items');
            $table->jsonb('failures')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at');

            $table->index(['tenant_id', 'package_id', 'verified_at']);
        });

        Schema::create('preservation_access_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('package_id')->nullable()->index();
            $table->uuid('user_id')->nullable();
            $table->string('action', 30);
            $table->string('ip_address', 64)->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'action', 'created_at']);
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_preservation_items_guard ON preservation_items;
                DROP TRIGGER IF EXISTS trg_preservation_packages_guard ON preservation_packages;
                DROP TRIGGER IF EXISTS trg_preservation_verifications_guard ON preservation_verifications;
                DROP TRIGGER IF EXISTS trg_preservation_access_logs_guard ON preservation_access_logs;
                DROP FUNCTION IF EXISTS preservation_items_guard();
                DROP FUNCTION IF EXISTS preservation_packages_guard();
                DROP FUNCTION IF EXISTS preservation_append_only_guard();
            ');
        }

        Schema::dropIfExists('preservation_access_logs');
        Schema::dropIfExists('preservation_verifications');
        Schema::dropIfExists('preservation_items');
        Schema::dropIfExists('preservation_packages');
        Schema::dropIfExists('preservation_settings');
    }

    /**
     * The cascade of a tenant deletion runs inside another trigger (depth above one) and stays allowed.
     */
    private function createTriggers(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION preservation_packages_guard() RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN
                    RETURN OLD;
                END IF;
                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'A closed preservation package is immutable and cannot be updated or deleted.';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION preservation_items_guard() RETURNS TRIGGER AS $$
            DECLARE
                package_status TEXT;
                package_key UUID;
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    package_key := NEW.package_id;
                ELSE
                    package_key := OLD.package_id;
                END IF;
                SELECT status INTO package_status FROM preservation_packages WHERE id = package_key;
                IF package_status = 'closed' THEN
                    RAISE EXCEPTION 'The files of a closed preservation package are immutable.';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION preservation_append_only_guard() RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'Preservation verification and access records are append-only.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_preservation_packages_guard ON preservation_packages;
            CREATE TRIGGER trg_preservation_packages_guard BEFORE UPDATE OR DELETE ON preservation_packages
                FOR EACH ROW EXECUTE FUNCTION preservation_packages_guard();

            DROP TRIGGER IF EXISTS trg_preservation_items_guard ON preservation_items;
            CREATE TRIGGER trg_preservation_items_guard BEFORE INSERT OR UPDATE OR DELETE ON preservation_items
                FOR EACH ROW EXECUTE FUNCTION preservation_items_guard();

            DROP TRIGGER IF EXISTS trg_preservation_verifications_guard ON preservation_verifications;
            CREATE TRIGGER trg_preservation_verifications_guard BEFORE UPDATE OR DELETE ON preservation_verifications
                FOR EACH ROW EXECUTE FUNCTION preservation_append_only_guard();

            DROP TRIGGER IF EXISTS trg_preservation_access_logs_guard ON preservation_access_logs;
            CREATE TRIGGER trg_preservation_access_logs_guard BEFORE UPDATE OR DELETE ON preservation_access_logs
                FOR EACH ROW EXECUTE FUNCTION preservation_append_only_guard();
        ");
    }
};
