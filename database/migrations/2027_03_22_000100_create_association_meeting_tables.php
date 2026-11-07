<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meetings of the bodies of an association: notice, agenda, participants taken from the registry, delivery of the
     * notice per recipient, attendance, proxies, online ballots and minutes.
     *
     * Ballot voters and votes are append-only and carry no foreign key towards rows that a tenant restore deletes. A
     * secret vote has no link to the person: the voter row says who voted, the vote row says what was chosen, and no
     * column, timestamp or ordered key connects the two.
     */
    public function up(): void
    {
        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_proxies_per_holder')->nullable();
        });

        Schema::create('assoc_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('body', 20);
            $table->string('body_name')->nullable();
            $table->string('title');
            $table->text('subject')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('second_call_at')->nullable();
            $table->string('location')->nullable();
            $table->string('online_url', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('first_call_quorum_percent', 5, 2)->nullable();
            $table->decimal('second_call_quorum_percent', 5, 2)->nullable();
            $table->boolean('proxies_allowed')->default(false);
            $table->timestamp('convened_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->string('held_on_call', 10)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'body', 'scheduled_at']);
        });

        Schema::create('assoc_meeting_agenda_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('meeting_id')->constrained('assoc_meetings')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->text('description')->nullable();
            $table->uuid('application_id')->nullable();
            $table->string('decision', 20)->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'meeting_id']);
            $table->index(['tenant_id', 'application_id']);
        });

        Schema::create('assoc_meeting_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('meeting_id')->constrained('assoc_meetings')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'meeting_id']);
        });

        Schema::create('assoc_meeting_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('meeting_id')->constrained('assoc_meetings')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->boolean('is_eligible_voter')->default(true);
            $table->string('attendance', 20)->default('invited');
            $table->timestamp('attendance_recorded_at')->nullable();
            $table->uuid('proxy_holder_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'meeting_id']);
            $table->index(['meeting_id', 'proxy_holder_id']);
        });

        Schema::create('assoc_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            $table->uuid('member_id')->nullable();
            $table->string('channel', 20);
            $table->text('recipient')->nullable();
            $table->string('status', 20);
            $table->string('driver', 20)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->text('error')->nullable();
            $table->uuid('sent_by')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['subject_id', 'member_id']);
        });

        Schema::create('assoc_ballots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('meeting_id')->constrained('assoc_meetings')->cascadeOnDelete();
            $table->uuid('agenda_item_id')->nullable();
            $table->string('question', 500);
            $table->jsonb('options');
            $table->boolean('is_secret')->default(false);
            $table->string('majority_rule', 20)->default('simple');
            $table->decimal('qualified_percent', 5, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->jsonb('results')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'meeting_id']);
        });

        Schema::create('assoc_ballot_voters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('ballot_id');
            $table->uuid('participant_id');
            $table->uuid('cast_by_participant_id')->nullable();
            $table->string('channel', 20);
            $table->timestamp('voted_at')->nullable();

            $table->unique(['ballot_id', 'participant_id']);
            $table->index(['tenant_id', 'ballot_id']);
        });

        Schema::create('assoc_ballot_votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('ballot_id');
            $table->string('choice', 100);
            $table->uuid('participant_id')->nullable();

            $table->index(['tenant_id', 'ballot_id']);
        });

        Schema::create('assoc_minutes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('meeting_id');
            $table->string('status', 20)->default('draft');
            $table->string('title');
            $table->text('body');
            $table->string('number', 40)->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->unsignedInteger('sequential_number')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index('meeting_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_meeting_participants_unique ON assoc_meeting_participants (meeting_id, member_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_minutes_meeting_unique ON assoc_minutes (meeting_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_minutes_number_unique ON assoc_minutes (tenant_id, number) WHERE number IS NOT NULL');

            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_assoc_ballot_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Ballot voters and votes are append-only and cannot be updated or deleted.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_prevent_assoc_ballot_voter_mutation BEFORE UPDATE OR DELETE ON assoc_ballot_voters
                FOR EACH ROW EXECUTE FUNCTION prevent_assoc_ballot_mutation();
                CREATE TRIGGER trg_prevent_assoc_ballot_vote_mutation BEFORE UPDATE OR DELETE ON assoc_ballot_votes
                FOR EACH ROW EXECUTE FUNCTION prevent_assoc_ballot_mutation();

                CREATE OR REPLACE FUNCTION prevent_assoc_approved_minutes_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    IF OLD.status = 'approved' THEN
                        RAISE EXCEPTION 'Approved minutes are immutable and cannot be updated or deleted.';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_prevent_assoc_approved_minutes_mutation BEFORE UPDATE OR DELETE ON assoc_minutes
                FOR EACH ROW EXECUTE FUNCTION prevent_assoc_approved_minutes_mutation();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_prevent_assoc_approved_minutes_mutation ON assoc_minutes;
                DROP TRIGGER IF EXISTS trg_prevent_assoc_ballot_vote_mutation ON assoc_ballot_votes;
                DROP TRIGGER IF EXISTS trg_prevent_assoc_ballot_voter_mutation ON assoc_ballot_voters;
                DROP FUNCTION IF EXISTS prevent_assoc_approved_minutes_mutation();
                DROP FUNCTION IF EXISTS prevent_assoc_ballot_mutation();
            ');
        }

        Schema::dropIfExists('assoc_minutes');
        Schema::dropIfExists('assoc_ballot_votes');
        Schema::dropIfExists('assoc_ballot_voters');
        Schema::dropIfExists('assoc_ballots');
        Schema::dropIfExists('assoc_deliveries');
        Schema::dropIfExists('assoc_meeting_participants');
        Schema::dropIfExists('assoc_meeting_attachments');
        Schema::dropIfExists('assoc_meeting_agenda_items');
        Schema::dropIfExists('assoc_meetings');

        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->dropColumn('max_proxies_per_holder');
        });
    }
};
