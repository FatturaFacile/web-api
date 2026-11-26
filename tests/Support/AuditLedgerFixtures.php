<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryCommand;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryHandler;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Builders shared by the audit ledger tests (Mod 005).
 */
final class AuditLedgerFixtures
{
    /**
     * A keyring with two configured master key versions, independent of the application key.
     */
    public static function keyring(int $defaultVersion = 1, ?Closure $resolver = null): AuditKeyring
    {
        return new AuditKeyring(
            ['1' => 'master-key-version-one-0123456789', '2' => 'master-key-version-two-9876543210'],
            $defaultVersion,
            $resolver,
        );
    }

    /**
     * The fields of an entry as the chain takes them, for the unit tests of the hasher and the verifier.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function entryFields(string $tenantId, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid7(),
            'company_id' => $tenantId,
            'category' => 'crud',
            'module_code' => 'CRM',
            'action' => 'UPDATED',
            'resource_type' => 'App\Models\Contact',
            'resource_id' => '0194f7a0-0000-7000-8000-000000000001',
            'actor_type' => 'user',
            'actor_ref' => '~pk:'.str_repeat('a', 32),
            'impersonator_ref' => null,
            'changes' => ['old' => ['name' => 'A'], 'new' => ['name' => 'B']],
            'context' => ['channel' => 'web', 'request_id' => null],
        ], $overrides);
    }

    /**
     * A tenant with an owner, as the other feature tests build them.
     *
     * @return array{tenant: Tenant, user: User}
     */
    public static function tenant(string $name = 'Audit Ledger Tenant'): array
    {
        return tfrTenant($name);
    }

    /**
     * A member of the tenant with the given role.
     */
    public static function member(Tenant $tenant, string $role): User
    {
        return tfrUserWithRole((string) $tenant->id, $role);
    }

    /**
     * Signs the user in and returns the test case with the tenant header set.
     */
    public static function actingAs(User $user, Tenant $tenant): object
    {
        Sanctum::actingAs($user);

        return test()->withHeader('X-Tenant-Id', (string) $tenant->id);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $personal
     * @param  array<string, mixed>  $context
     */
    public static function append(
        string $tenantId,
        string $action = 'UPDATED',
        ?array $before = ['name' => 'before'],
        ?array $after = ['name' => 'after'],
        ?string $resourceId = null,
        string $resourceType = 'App\\Models\\Contact',
        ?string $actorUserId = null,
        array $personal = [],
        string $module = 'CRM',
        ?ClientContext $client = null,
        array $context = [],
    ): AuditLedgerEntry {
        return app(AppendAuditEntryHandler::class)->handle(new AppendAuditEntryCommand(
            tenantId: $tenantId,
            companyId: $tenantId,
            moduleCode: $module,
            action: AuditAction::make($action),
            resourceType: $resourceType,
            resourceId: $resourceId ?? (string) Str::uuid7(),
            before: $before,
            after: $after,
            actorUserId: $actorUserId,
            personalAttributes: $personal,
            context: $context,
            client: $client,
        ));
    }

    /**
     * @return Collection<int, AuditLedgerEntry>
     */
    public static function chain(string $tenantId, int $length, ?string $resourceId = null): Collection
    {
        return collect(range(1, $length))->map(
            static fn (int $i): AuditLedgerEntry => self::append($tenantId, 'UPDATED', ['name' => 'v'.($i - 1)], ['name' => 'v'.$i], $resourceId),
        );
    }

    /**
     * The entries of a tenant, in chain order.
     *
     * @return Collection<int, AuditLedgerEntry>
     */
    public static function entries(string $tenantId): Collection
    {
        return AuditLedgerEntry::query()->where('tenant_id', $tenantId)->orderBy('sequence')->get();
    }

    /**
     * Changes a stored entry behind the back of the model, as somebody with write access to the database would.
     *
     * @param  array<string, mixed>  $values
     */
    public static function tamper(string $tenantId, int $sequence, array $values): void
    {
        DB::table('enterprise_audit_ledger')->where('tenant_id', $tenantId)->where('sequence', $sequence)->update($values);
    }

    public static function deleteRow(string $tenantId, int $sequence): void
    {
        DB::table('enterprise_audit_ledger')->where('tenant_id', $tenantId)->where('sequence', $sequence)->delete();
    }
}
