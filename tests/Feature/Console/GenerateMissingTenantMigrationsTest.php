<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\GenerateMissingTenantMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class GenerateMissingTenantMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_when_all_tables_already_have_tenant_id(): void
    {
        $this->artisan('tenancy:generate-missing-columns')
            ->expectsOutput('All tables mapped to multi-tenant models already have the tenant_id column.')
            ->assertSuccessful();
    }

    public function test_it_builds_migration_content_without_foreign_flag(): void
    {
        $command = new GenerateMissingTenantMigrations;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('buildMigrationContent');
        $method->setAccessible(true);

        /** @var string $content */
        $content = $method->invoke($command, ['dummy_table'], false);

        $this->assertStringContainsString("Schema::table('dummy_table'", $content);
        $this->assertStringContainsString("\$table->foreignId('tenant_id')", $content);
        $this->assertStringContainsString('->nullable()', $content);
        $this->assertStringContainsString("->after('id')", $content);
        $this->assertStringContainsString('->index()', $content);
        $this->assertStringNotContainsString("constrained('tenants')", $content);
        $this->assertStringContainsString("\$table->dropIndex(['tenant_id']);", $content);
        $this->assertStringContainsString("\$table->dropColumn('tenant_id');", $content);
    }

    public function test_it_builds_migration_content_with_foreign_flag(): void
    {
        $command = new GenerateMissingTenantMigrations;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('buildMigrationContent');
        $method->setAccessible(true);

        /** @var string $content */
        $content = $method->invoke($command, ['dummy_table'], true);

        $this->assertStringContainsString("Schema::table('dummy_table'", $content);
        $this->assertStringContainsString("\$table->foreignId('tenant_id')", $content);
        $this->assertStringContainsString('->nullable()', $content);
        $this->assertStringContainsString("->after('id')", $content);
        $this->assertStringContainsString("->constrained('tenants')", $content);
        $this->assertStringContainsString('->cascadeOnDelete()', $content);
        $this->assertStringContainsString("\$table->dropConstrainedForeignId('tenant_id');", $content);
    }
}
