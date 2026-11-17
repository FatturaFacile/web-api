<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Access\AccessTeam;
use App\Models\Access\TenantInvitation;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adds many people from a CSV file (`email;name;role;team`, semicolon or comma separated, teams separated by `|`).
 * A registered address becomes a member at once, an unknown one gets an e-mail invitation. A dry run checks every row
 * and changes nothing. In the report `created` and `invited` also count the rows a dry run would add and invite.
 */
final class AccessUserImport
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly AccessInvitations $invitations,
        private readonly AccessTeams $teams,
        private readonly AccessPlanLimits $limits,
        private readonly AccessAudit $audit,
    ) {}

    /**
     * @return array{dry_run: bool, total: int, created: int, invited: int, skipped: int, errors: int, rows: list<array{line: int, email: string, status: string, message: string|null}>}
     *
     * @throws DomainException when the file has no usable header or too many rows
     */
    public function run(Tenant $tenant, User $actor, string $csv, bool $dryRun, string $defaultRole): array
    {
        [$header, $lines] = $this->parse($csv);

        if (! in_array('email', $header, true)) {
            throw new DomainException(__('access.errors.import_no_header'));
        }

        if (count($lines) > (int) config('access.import_max_rows')) {
            throw new DomainException(__('access.errors.import_too_many_rows', ['max' => (int) config('access.import_max_rows')]));
        }

        $teamsByName = AccessTeam::query()->where('tenant_id', $tenant->id)->get()->keyBy(fn (AccessTeam $team): string => Str::lower($team->name));
        $assignable = array_values(array_diff($this->memberships->roles(), ['owner']));
        $room = $this->room($tenant);
        $seen = [];
        $rows = [];

        foreach ($lines as $number => $cells) {
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            $role = trim((string) ($row['role'] ?? '')) ?: $defaultRole;
            [$status, $message] = $this->check($email, $role, $assignable, $seen, (string) ($row['team'] ?? ''), $teamsByName);
            $seen[$email] = true;

            if ($status === null) {
                $account = User::query()->whereRaw('lower(email) = ?', [$email])->first();
                $isMember = $account !== null && $tenant->users()->where('users.id', $account->id)->exists();
                $isInvited = TenantInvitation::query()->where('tenant_id', $tenant->id)->where('email', $email)->where('status', TenantInvitation::Pending)->where('expires_at', '>', now())->exists();

                [$status, $message] = match (true) {
                    $isMember => ['skipped', __('access.import.already_member')],
                    $isInvited => ['skipped', __('access.import.already_invited')],
                    $room !== null && $room <= 0 => ['error', __('access.errors.user_limit', ['max' => $this->limits->maxUsers($tenant)])],
                    default => [$account !== null ? 'would_add' : 'would_invite', null],
                };

                if (in_array($status, ['would_add', 'would_invite'], true)) {
                    $room = $room === null ? null : $room - 1;
                    $teamIds = $this->teamIds((string) ($row['team'] ?? ''), $teamsByName);

                    if (! $dryRun) {
                        [$status, $message] = $this->apply($tenant, $actor, $account, $email, $role, $teamIds);
                    }
                }
            }

            $rows[] = ['line' => $number, 'email' => $email, 'status' => $status, 'message' => $message];
        }

        $count = static fn (array $statuses): int => count(array_filter($rows, static fn (array $row): bool => in_array($row['status'], $statuses, true)));
        $report = [
            'dry_run' => $dryRun,
            'total' => count($rows),
            'created' => $count(['created', 'would_add']),
            'invited' => $count(['invited', 'would_invite']),
            'skipped' => $count(['skipped']),
            'errors' => $count(['error']),
            'rows' => $rows,
        ];

        if (! $dryRun) {
            $this->audit->record('access.import.completed', (string) $tenant->id, Tenant::class, (string) $tenant->id, null, array_diff_key($report, ['rows' => true, 'dry_run' => true]));
        }

        return $report;
    }

    /**
     * @param  list<string>  $assignable
     * @param  array<string, true>  $seen
     * @param  Collection<string, AccessTeam>  $teamsByName
     * @return array{0: string|null, 1: string|null} the refusal of the row, or [null, null] when it can be processed
     */
    private function check(string $email, string $role, array $assignable, array $seen, string $teams, $teamsByName): array
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['error', __('access.import.invalid_email')];
        }

        if (isset($seen[$email])) {
            return ['skipped', __('access.import.duplicate')];
        }

        if (! in_array($role, $assignable, true)) {
            return ['error', __('access.import.unknown_role', ['role' => $role])];
        }

        foreach (array_filter(array_map('trim', explode('|', $teams))) as $name) {
            if (! $teamsByName->has(Str::lower($name))) {
                return ['error', __('access.import.unknown_team', ['team' => $name])];
            }
        }

        return [null, null];
    }

    /**
     * @param  list<string>  $teamIds
     * @return array{0: string, 1: string|null}
     */
    private function apply(Tenant $tenant, User $actor, ?User $account, string $email, string $role, array $teamIds): array
    {
        try {
            if ($account !== null) {
                $this->memberships->save($tenant, $account, $role, null);
                $this->teams->join($tenant, $account, $teamIds);

                return ['created', null];
            }

            $this->invitations->invite($tenant, $actor, ['email' => $email, 'role' => $role, 'team_ids' => $teamIds]);

            return ['invited', null];
        } catch (ValidationException $exception) {
            return ['error', (string) collect($exception->errors())->flatten()->first()];
        } catch (Throwable $exception) {
            return ['error', $exception instanceof DomainException ? $exception->getMessage() : __('access.import.failed')];
        }
    }

    /**
     * @param  Collection<string, AccessTeam>  $teamsByName
     * @return list<string>
     */
    private function teamIds(string $names, $teamsByName): array
    {
        $ids = [];

        foreach (array_filter(array_map('trim', explode('|', $names))) as $name) {
            $ids[] = (string) $teamsByName->get(Str::lower($name))?->id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * How many more people the plan allows, null when unlimited.
     */
    private function room(Tenant $tenant): ?int
    {
        $max = $this->limits->maxUsers($tenant);

        return $max === null ? null : $max - $this->limits->activeUsers($tenant) - $this->limits->pendingInvitations($tenant);
    }

    /**
     * @return array{0: list<string>, 1: array<int, list<string>>} the lower-case header and the cells of every data row by line number
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $first = '';

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = $line;

                break;
            }
        }

        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $header = [];
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));

            if ($header === []) {
                $header = array_map(static fn (string $cell): string => Str::lower($cell), $cells);

                continue;
            }

            $rows[$index + 1] = $cells;
        }

        return [$header, $rows];
    }
}
