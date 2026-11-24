<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_clock_events', function (Blueprint $table): void {
            $table->decimal('gps_lat', 10, 7)->nullable()->after('note');
            $table->decimal('gps_lng', 10, 7)->nullable()->after('gps_lat');
            // GDPR gate: GPS coordinates stored only when employee consented at punch time (Art. 4 L. 300/1970)
            $table->timestamp('geolocation_consent_at')->nullable()->after('gps_lng');
            // Append-only corrections: soft-deleted original linked to replacement (CJEU C-55/18)
            $table->uuid('amended_from_id')->nullable()->after('geolocation_consent_at');
            // Pseudonymous device identifier — no raw biometrics (Art. 4 L. 300/1970, Art. 26 LPD)
            $table->string('source_device', 100)->nullable()->after('amended_from_id');
        });

        Schema::table('time_audit_entries', function (Blueprint $table): void {
            // SHA-256 hash of this entry's canonical content (tamper-evident audit trail, CJEU C-55/18)
            $table->char('record_hash', 64)->nullable()->after('actor_name');
            $table->char('prev_hash', 64)->nullable()->after('record_hash');
        });

        Schema::table('time_recording_profiles', function (Blueprint $table): void {
            $table->char('jurisdiction', 2)->nullable()->after('mode');
            // Art. 73a OLL 1: waiver requires confirmed salary above threshold and signed agreement
            $table->boolean('income_threshold_confirmed')->nullable()->after('jurisdiction');
            $table->date('waiver_signed_date')->nullable()->after('income_threshold_confirmed');
            // Art. 73b OLL 1: simplified recording with configurable reference period (1–6 months)
            $table->tinyInteger('reference_period_months')->nullable()->after('waiver_signed_date');
            // Italy LUL baseline hours for ordinary/overtime split (Art. 39 D.L. 112/2008)
            $table->decimal('ordinary_weekly_hours', 5, 2)->nullable()->after('reference_period_months');
        });
    }

    public function down(): void
    {
        Schema::table('time_recording_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'ordinary_weekly_hours',
                'reference_period_months',
                'waiver_signed_date',
                'income_threshold_confirmed',
                'jurisdiction',
            ]);
        });

        Schema::table('time_audit_entries', function (Blueprint $table): void {
            $table->dropColumn(['prev_hash', 'record_hash']);
        });

        Schema::table('time_clock_events', function (Blueprint $table): void {
            $table->dropColumn([
                'source_device',
                'amended_from_id',
                'geolocation_consent_at',
                'gps_lng',
                'gps_lat',
            ]);
        });
    }
};
