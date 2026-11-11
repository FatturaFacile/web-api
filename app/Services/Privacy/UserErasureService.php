<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Erasure of a platform user. Users are global across companies, so the rule is per membership:
 *
 * 1. in the company that receives the request the membership is removed (the user loses access to it) together with the
 *    tenant-scoped sign-in data (SSO identities); the last owner of a company cannot be removed;
 * 2. the global profile is anonymised only when the user has no other membership and no employment record (employee,
 *    employee profile) in any company, since those are kept by law (CO art. 958f, ArG art. 46: to be confirmed);
 *    otherwise it is kept and the hold is reported;
 * 3. records that name the user (audit ledger actor, sensitive access log, authorship) keep the user id: the id is a
 *    pseudonym once the profile is anonymised, and the trails are evidence that is never rewritten.
 *
 * Everything is written to the audit ledger of the company with ids and counts only.
 */
final readonly class UserErasureService
{
    public function __construct(
        private PersonalDataRegistry $registry,
        private PersonalDataAnonymizer $anonymizer,
        private PrivacyAudit $audit,
    ) {}

    /**
     * @return array{legal_holds: list<array{statute: string, retention_years: int|null, reason: string}>, erased_fields: list<string>, retained: list<array{table: string, label: string, retention: string, rows: int, reason: string}>, anonymised: list<array{table: string, rows: int}>}
     */
    public function erase(string $tenantId, string $userId, ?string $actorId): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $actorId): array {
            $membership = DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();
            if ($membership === null) {
                throw new RuntimeException('The user is not a member of this company.');
            }

            if ($membership->role === 'owner' && DB::table('tenant_user')->where('tenant_id', $tenantId)->where('role', 'owner')->where('user_id', '!=', $userId)->doesntExist()) {
                throw new RuntimeException('The last owner of a company cannot be removed: appoint another owner first.');
            }

            $erased = [];
            $anonymised = [];

            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $userId)->delete();
            $erased[] = 'membership';

            if (Schema::hasTable('sso_identities')) {
                $identities = DB::table('sso_identities')->where('tenant_id', $tenantId)->where('user_id', $userId)->delete();
                if ($identities > 0) {
                    $erased[] = 'sso_identities';
                    $anonymised[] = ['table' => 'sso_identities', 'rows' => $identities];
                }
            }

            $this->audit->record($tenantId, 'PRIVACY_USER_MEMBERSHIP_REMOVED', User::class, $userId, ['role' => (string) $membership->role], $actorId);

            $retained = $this->retainedRecords($tenantId, $userId);
            $holds = [];
            $remaining = DB::table('tenant_user')->where('user_id', $userId)->count();
            $employment = $this->employmentRecords($userId);

            if ($remaining > 0) {
                $holds[] = ['statute' => 'Other company memberships', 'retention_years' => null, 'reason' => "The user is still a member of {$remaining} other company(ies): the global profile is kept."];
            }

            if ($employment > 0) {
                $holds[] = ['statute' => 'Employment records (CO art. 958f, ArG art. 46), to be confirmed', 'retention_years' => null, 'reason' => "The user has {$employment} employment record(s) in the companies: the global profile is kept."];
            }

            if ($holds === []) {
                $this->anonymiseProfile($userId);
                $erased = array_merge($erased, ['profile', 'password_histories', 'api_tokens', 'sso_identities_all_companies']);
                $this->audit->record($tenantId, 'PRIVACY_USER_ANONYMISED', User::class, $userId, [], $actorId);
            }

            return ['legal_holds' => $holds, 'erased_fields' => array_values(array_unique($erased)), 'retained' => $retained, 'anonymised' => $anonymised];
        });
    }

    /**
     * The audit ledger entries of the user, which keep the user id and stay (the other records that name the user are
     * reported by the registry: employee records, access log).
     *
     * @return list<array{table: string, label: string, retention: string, rows: int, reason: string}>
     */
    private function retainedRecords(string $tenantId, string $userId): array
    {
        $retained = [];
        $ledger = DB::table('enterprise_audit_ledger')->where('tenant_id', $tenantId)->where('actor_user_id', $userId)->count();
        if ($ledger > 0) {
            $retained[] = ['table' => 'enterprise_audit_ledger', 'label' => 'Audit ledger', 'retention' => PersonalDataRetention::Evidence->value, 'rows' => $ledger, 'reason' => 'Immutable ledger: names the user by id only.'];
        }

        return $retained;
    }

    /**
     * Employment records of the user in any company: they are kept by law and block the anonymisation of the profile.
     */
    private function employmentRecords(string $userId): int
    {
        $count = 0;
        foreach (['hr_employees', 'employee_profiles'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                $count += DB::table($table)->where('user_id', $userId)->count();
            }
        }

        return $count;
    }

    private function anonymiseProfile(string $userId): void
    {
        $this->anonymizer->anonymize(
            $this->registry->findOrFail('users'),
            static function (Builder $query) use ($userId): void {
                $query->where('id', $userId);
            },
        );

        DB::table('users')->where('id', $userId)->update([
            'is_active' => false,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'email_verification_token' => null,
            'deleted_at' => CarbonImmutable::now(),
        ]);

        foreach (['password_histories' => 'user_id', 'sso_identities' => 'user_id'] as $table => $column) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where($column, $userId)->delete();
            }
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $userId)->delete();
        }
    }
}
