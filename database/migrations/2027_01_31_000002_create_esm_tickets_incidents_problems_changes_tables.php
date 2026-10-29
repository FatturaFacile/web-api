<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Knowledge Base Articles
        if (! Schema::hasTable('esm_knowledge_articles')) {
            Schema::create('esm_knowledge_articles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('category_id')->nullable()->constrained('esm_service_categories')->nullOnDelete();
                $table->string('article_number', 60)->index();
                $table->string('title', 200);
                $table->text('summary')->nullable();
                $table->longText('content');
                $table->jsonb('tags')->nullable();
                $table->boolean('is_published')->default(true);
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('helpful_count')->default(0);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'article_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_knowledge_articles_tags_gin ON esm_knowledge_articles USING GIN (tags)');
            }
        }

        // 2. Tickets (Core Service Request & Incident Entry)
        if (! Schema::hasTable('esm_tickets')) {
            Schema::create('esm_tickets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('customer_id')->index(); // CRM Contact / Customer
                $table->uuid('contact_id')->nullable()->index();
                $table->foreignUuid('service_contract_id')->nullable()->constrained('esm_service_contracts')->nullOnDelete();
                $table->uuid('asset_id')->nullable()->index(); // Module 16 EAM FixedAsset
                $table->uuid('project_id')->nullable()->index(); // Module 15 Project
                $table->foreignUuid('category_id')->nullable()->constrained('esm_service_categories')->nullOnDelete();
                $table->string('ticket_number', 60)->index();
                $table->string('type', 30)->default('incident'); // incident, request, problem, change, inquiry
                $table->string('priority', 20)->default('medium')->index(); // low, medium, high, critical
                $table->string('status', 30)->default('open')->index(); // open, assigned, in_progress, pending_customer, resolved, closed, cancelled
                $table->string('source', 30)->default('portal'); // portal, email, phone, monitoring, api
                $table->string('subject', 200);
                $table->text('description');
                $table->foreignUuid('assigned_team_id')->nullable()->constrained('esm_service_teams')->nullOnDelete();
                $table->foreignUuid('assigned_technician_id')->nullable()->constrained('esm_service_technicians')->nullOnDelete();

                // SLA Tracking
                $table->timestamp('response_due_at')->nullable()->index();
                $table->timestamp('resolution_due_at')->nullable()->index();
                $table->timestamp('first_responded_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->boolean('is_escalated')->default(false);
                $table->unsignedSmallInteger('escalation_level')->default(0);

                // Resolution & Root Cause
                $table->text('resolution_summary')->nullable();
                $table->text('root_cause')->nullable();
                $table->foreignUuid('knowledge_article_id')->nullable()->constrained('esm_knowledge_articles')->nullOnDelete();

                // Workflow & Concurrency
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->string('approval_status', 30)->default('approved');
                $table->boolean('is_locked')->default(false);

                // Metrics & CSAT
                $table->unsignedSmallInteger('csat_score')->nullable();
                $table->jsonb('custom_fields')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'ticket_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("CREATE INDEX esm_tickets_active_priority_idx ON esm_tickets (tenant_id, priority, status) WHERE status IN ('open', 'assigned', 'in_progress') AND deleted_at IS NULL");
                DB::statement('CREATE INDEX esm_tickets_metadata_gin ON esm_tickets USING GIN (metadata)');
            }
        }

        // 3. Ticket Time Entries
        if (! Schema::hasTable('esm_ticket_time_entries')) {
            Schema::create('esm_ticket_time_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
                $table->foreignUuid('technician_id')->constrained('esm_service_technicians')->cascadeOnDelete();
                $table->date('work_date');
                $table->decimal('hours', 6, 2);
                $table->decimal('hourly_cost', 18, 2)->default(0.00);
                $table->decimal('hourly_rate', 18, 2)->default(0.00);
                $table->decimal('total_cost', 18, 2)->default(0.00);
                $table->decimal('total_charge', 18, 2)->default(0.00);
                $table->boolean('is_billable')->default(true);
                $table->text('description')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 4. Incidents (ITSM High-Impact Service Disruptions)
        if (! Schema::hasTable('esm_incidents')) {
            Schema::create('esm_incidents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
                $table->uuid('asset_id')->nullable()->index();
                $table->string('incident_number', 60)->index();
                $table->string('severity', 20)->default('P3'); // P1 (Critical), P2 (Major), P3 (Moderate), P4 (Minor)
                $table->string('impact', 20)->default('moderate'); // extensive, significant, moderate, minor
                $table->string('urgency', 20)->default('medium'); // critical, high, medium, low
                $table->string('priority', 20)->default('P3')->index();
                $table->string('status', 30)->default('identified')->index(); // identified, investigating, identified_fix, contained, resolved, closed
                $table->string('title', 200);
                $table->text('summary');
                $table->timestamp('reported_at');
                $table->timestamp('contained_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->text('workaround')->nullable();
                $table->text('post_mortem_summary')->nullable();
                $table->boolean('post_mortem_completed')->default(false);
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->jsonb('timeline')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'incident_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_incidents_timeline_gin ON esm_incidents USING GIN (timeline)');
            }
        }

        // 5. Problems (ITSM Underlying Root Causes)
        if (! Schema::hasTable('esm_problems')) {
            Schema::create('esm_problems', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('category_id')->nullable()->constrained('esm_service_categories')->nullOnDelete();
                $table->string('problem_number', 60)->index();
                $table->string('title', 200);
                $table->text('description');
                $table->string('status', 30)->default('under_investigation')->index(); // under_investigation, known_error, permanent_solution_identified, resolved, closed
                $table->text('root_cause')->nullable();
                $table->text('workaround')->nullable();
                $table->text('permanent_solution')->nullable();
                $table->boolean('is_known_error')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->jsonb('linked_incident_ids')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'problem_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_problems_linked_incidents_gin ON esm_problems USING GIN (linked_incident_ids)');
            }
        }

        // 6. Change Requests (CAB / Change Management)
        if (! Schema::hasTable('esm_change_requests')) {
            Schema::create('esm_change_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('problem_id')->nullable()->constrained('esm_problems')->nullOnDelete();
                $table->foreignUuid('ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
                $table->string('change_number', 60)->index();
                $table->string('change_type', 30)->default('normal'); // standard, normal, emergency
                $table->string('title', 200);
                $table->text('description');
                $table->string('risk_level', 20)->default('medium'); // low, medium, high, very_high
                $table->text('impact_assessment');
                $table->text('implementation_plan');
                $table->text('rollback_plan');
                $table->timestamp('scheduled_start_at')->nullable();
                $table->timestamp('scheduled_end_at')->nullable();
                $table->timestamp('implemented_at')->nullable();
                $table->timestamp('validated_at')->nullable();
                $table->string('status', 30)->default('draft')->index(); // draft, submitted, cab_review, approved, implementing, validated, rolled_back, closed, rejected
                $table->string('cab_decision', 30)->nullable(); // approved, rejected, deferred
                $table->text('cab_notes')->nullable();
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->jsonb('cab_reviewers')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'change_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_change_requests');
        Schema::dropIfExists('esm_problems');
        Schema::dropIfExists('esm_incidents');
        Schema::dropIfExists('esm_ticket_time_entries');
        Schema::dropIfExists('esm_tickets');
        Schema::dropIfExists('esm_knowledge_articles');
    }
};
