<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Exceptions\PlanLimitExceededException;
use App\Exceptions\SodConflictException;
use App\Models\Access\TenantRole;
use App\Models\Access\TenantTeam;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Bulk import of users from a CSV file with the columns email (required), name, role, team. An address that already
 * has an account becomes a member straight away; any other address receives an email invitation. A dry run checks
 * every row and the plan capacity and changes nothing.
 */
final class UserImportService
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly InvitationService $invitations,
        private readonly TeamService $teams,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * @return array{dry_run: bool, total: int, created: int, invited: int, skipped: int, errors: int, rows: list<array<string, mixed>>}
     *
     * @throws DomainException when the file has no readable header with an email column or too many rows
     */
    public function import(Tenant $tenant, string $path, bool $dryRun, ?string $defaultRole, ?User $actor): array
    {
        $rows = $this->parse($path);

        if ($rows === []) {
            throw new DomainException('The file has no rows to import.');
        }

        if (count($rows) > (int) config('access_plans.import_max_rows', 500)) {
            throw new DomainException('The file has more than '.config('access_plans.import_max_rows', 500).' rows.');
        }

        $roles = TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $teamsByName = TenantTeam::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->keyBy(fn (TenantTeam $team): string => Str::lower($team->name));
        $maxUsers = $this->limits->maxUsers($tenant);
        $free = $maxUsers === null ? PHP_INT_MAX : $maxUsers - $this->limits->activeMembers($tenant) - $this->limits->pendingInvitations($tenant);
        $seen = [];
        $report = [];
        $counts = ['created' => 0, 'invited' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($rows as $line => $row) {
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            $result = ['line' => $line, 'email' => $email, 'status' => 'error', 'message' => null];

            try {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new DomainException('Invalid email address.');
                }

                if (isset($seen[$email])) {
                    $result['status'] = 'skipped';
                    $result['message'] = 'Duplicate of an earlier row.';
                    $counts['skipped']++;
                    $report[] = $result;

                    continue;
                }

                $seen[$email] = true;

                [$system, $custom] = $this->resolveRole(trim((string) ($row['role'] ?? '')) ?: $defaultRole, $roles);
                $teamIds = $this->resolveTeams((string) ($row['team'] ?? ''), $teamsByName);
                $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();

                if ($existing !== null && $tenant->users()->where('users.id', $existing->id)->exists()) {
                    $result['status'] = 'skipped';
                    $result['message'] = 'Already a member.';
                    $counts['skipped']++;
                    $report[] = $result;

                    continue;
                }

                if ($free < 1) {
                    throw new PlanLimitExceededException('users', (int) $maxUsers);
                }

                if ($dryRun) {
                    $free--;
                    $result['status'] = $existing !== null ? 'would_add' : 'would_invite';
                } elseif ($existing !== null) {
                    $this->memberships->save($tenant, $existing, $custom === null ? $system : null, null, $custom?->id);
                    $this->addToTeams($tenant, $existing, $teamIds);
                    $free--;
                    $result['status'] = 'created';
                } else {
                    $this->invitations->invite($tenant, $email, $custom === null ? $system : null, $custom?->id, null, $teamIds === [] ? null : $teamIds, $actor);
                    $free--;
                    $result['status'] = 'invited';
                }

                if (! $dryRun) {
                    $counts[$result['status']]++;
                }
            } catch (PlanLimitExceededException $exception) {
                $result['message'] = 'Plan limit reached.';
                $counts['errors']++;
            } catch (SodConflictException|DomainException $exception) {
                $result['message'] = $exception->getMessage();
                $counts['errors']++;
            }

            $report[] = $result;
        }

        if ($dryRun) {
            $counts['created'] = count(array_filter($report, static fn (array $row): bool => $row['status'] === 'would_add'));
            $counts['invited'] = count(array_filter($report, static fn (array $row): bool => $row['status'] === 'would_invite'));
        } else {
            AccessAudit::record((string) $tenant->id, AccessAudit::TypeImport, null, 'users.imported', null, [...$counts, 'total' => count($rows)]);
        }

        return ['dry_run' => $dryRun, 'total' => count($rows), ...$counts, 'rows' => $report];
    }

    /**
     * @return array{0: string, 1: TenantRole|null} the system role to use and the custom role, when the cell names one
     *
     * @throws DomainException when the role does not exist or is not assignable
     */
    private function resolveRole(?string $cell, Collection $custom): array
    {
        if ($cell === null || $cell === '') {
            return ['member', null];
        }

        $needle = Str::lower($cell);

        $role = $custom->first(fn (TenantRole $role): bool => Str::lower($role->key) === $needle || Str::lower($role->name) === $needle);

        if ($role !== null) {
            return [$role->base_role, $role];
        }

        if (in_array($needle, array_diff($this->memberships->roles(), ['owner']), true)) {
            return [$needle, null];
        }

        throw new DomainException("Unknown role \"{$cell}\".");
    }

    /**
     * @param  Collection<string, TenantTeam>  $teams
     * @return list<string>
     */
    private function resolveTeams(string $cell, Collection $teams): array
    {
        $ids = [];

        foreach (preg_split('/[|;]/', $cell) ?: [] as $name) {
            $name = Str::lower(trim($name));

            if ($name === '') {
                continue;
            }

            $team = $teams->get($name) ?? throw new DomainException("Unknown team \"{$name}\".");
            $ids[] = (string) $team->id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $teamIds
     */
    private function addToTeams(Tenant $tenant, User $user, array $teamIds): void
    {
        foreach ($teamIds as $teamId) {
            $team = TenantTeam::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($teamId);

            if ($team !== null) {
                $this->teams->syncMembers($tenant, $team, [...$this->teams->memberIds($team), (string) $user->id]);
            }
        }
    }

    /**
     * @return array<int, array<string, string>> rows keyed by their line number in the file
     */
    private function parse(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('The file cannot be read.');
        }

        try {
            $first = (string) fgets($handle);
            rewind($handle);

            $bom = str_starts_with($first, "\xEF\xBB\xBF");
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

            if ($bom) {
                fread($handle, 3);
            }

            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            $header = is_array($header) ? array_map(static fn (?string $name): string => Str::lower(trim((string) $name)), $header) : [];

            if (! in_array('email', $header, true)) {
                throw new DomainException('The first row must be a header with an "email" column.');
            }

            $rows = [];
            $line = 1;

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;

                if ($cells === [null] || array_filter($cells, static fn (?string $cell): bool => trim((string) $cell) !== '') === []) {
                    continue;
                }

                $rows[$line] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')) ?: [];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
