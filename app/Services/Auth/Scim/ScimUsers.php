<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Sso\ProvisionableRoles;
use App\Services\Auth\UserSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 `User` resources (RFC 7643 section 4.1, RFC 7644) as the memberships of one tenant. Every query starts from
 * the tenant_user rows of the tenant of the SCIM token, so a user of another tenant simply does not exist here.
 *
 * Decisions, all on the conservative side:
 * - `userName` is the email address and is the only key; it cannot be changed through SCIM, and a `userName` that already
 *   belongs to a user of any tenant is a 409 (a tenant cannot adopt an account that exists elsewhere);
 * - a user is global (one password for every tenant), so the profile attributes (names) are written only for users who
 *   belong to this tenant alone; for users of several tenants only `active` and `externalId`, which are per membership,
 *   change, and the profile attributes of the request are ignored;
 * - `active=false` and DELETE suspend the membership (tenant_user.suspended_at) and revoke the accesses opened through
 *   the tenant's SSO; when no active membership remains every access of the user is revoked. Nothing is deleted;
 * - a provisioned user has no usable password and an unverified email: it signs in through the SSO of the tenant, which
 *   verifies the email, or sets a password through the reset flow;
 * - the role is the first valid entry of `roles`, else the provider default role, else `sso.scim_default_role`; the
 *   owner can never be provisioned.
 *
 * Supported: filter `userName eq "x"` and `externalId eq "x"`, startIndex/count paging, PATCH add/replace/remove on
 * `active`, `externalId`, `displayName`, `name.givenName`, `name.familyName` (also as a value object without path).
 * Not supported: groups, bulk, sort, ETags, change password.
 */
final class ScimUsers
{
    public const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly RegistrationAudit $audit,
        private readonly UserSessions $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(string $tenantId, ?string $filter, int $startIndex, int $count): array
    {
        $query = $this->members($tenantId);
        $this->applyFilter($query, $filter);

        $total = (clone $query)->count('users.id');
        $startIndex = max(1, $startIndex);
        $count = max(0, min($count, self::MAX_PAGE_SIZE));
        $page = $count === 0 ? collect() : $query->orderBy('users.created_at')->orderBy('users.id')->skip($startIndex - 1)->take($count)->get();

        return [
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $page->count(),
            'Resources' => $page->map(fn (User $user): array => $this->represent($user))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ScimException 404 when the user is not a member of the tenant
     */
    public function get(string $tenantId, string $id): array
    {
        return $this->represent($this->member($tenantId, $id));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    public function create(string $tenantId, array $payload, string $tokenId): array
    {
        $data = $this->validated($payload, creating: true);
        $email = Str::lower(trim((string) $data['userName']));
        $this->assertDomainAllowed($tenantId, $email);

        if (User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new ScimException(409, 'uniqueness', 'A user with this userName already exists.');
        }

        $role = $this->roleFor($tenantId, $data['roles'] ?? []);
        $active = $this->toBool($data['active'] ?? true) ?? true;

        $user = DB::transaction(function () use ($tenantId, $email, $data, $role, $active): User {
            $first = $this->nullableString($data['name']['givenName'] ?? null);
            $last = $this->nullableString($data['name']['familyName'] ?? null);
            $display = $this->nullableString($data['displayName'] ?? null) ?? $this->nullableString($data['name']['formatted'] ?? null) ?? trim($first.' '.$last);

            $user = User::query()->create([
                'name' => Str::limit($display !== '' ? $display : Str::before($email, '@'), 250, ''),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => Str::random(64),
                'locale' => 'it',
                'is_active' => true,
            ]);

            $user->tenants()->attach($tenantId, [
                'id' => (string) Str::uuid(),
                'role' => $role,
                'provisioned_via' => 'scim',
                'scim_external_id' => $this->nullableString($data['externalId'] ?? null),
                'suspended_at' => $active ? null : now(),
            ]);

            return $user;
        });

        $this->audit->record(RegistrationAudit::SCIM_USER_CREATED, (string) $user->id, ['scim_token' => $tokenId, 'active' => $active], $tenantId);

        return $this->get($tenantId, (string) $user->id);
    }

    /**
     * PUT: replaces the writable attributes. The `userName` must be the current one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    public function replace(string $tenantId, string $id, array $payload, string $tokenId): array
    {
        $user = $this->member($tenantId, $id);
        $data = $this->validated($payload, creating: false);

        if (isset($data['userName']) && Str::lower(trim((string) $data['userName'])) !== Str::lower((string) $user->email)) {
            throw new ScimException(400, 'mutability', 'userName cannot be changed through SCIM.');
        }

        $changes = [
            'externalId' => $this->nullableString($data['externalId'] ?? null),
            'givenName' => $this->nullableString($data['name']['givenName'] ?? null),
            'familyName' => $this->nullableString($data['name']['familyName'] ?? null),
            'displayName' => $this->nullableString($data['displayName'] ?? null),
        ];

        if (array_key_exists('active', $data)) {
            $changes['active'] = $this->toBool($data['active']) ?? true;
        }

        $this->apply($tenantId, $user, $changes, $tokenId);

        return $this->get($tenantId, $id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    public function patch(string $tenantId, string $id, array $payload, string $tokenId): array
    {
        $user = $this->member($tenantId, $id);
        $operations = $payload['Operations'] ?? null;

        if (! is_array($operations) || $operations === []) {
            throw new ScimException(400, 'invalidSyntax', 'Operations is required.');
        }

        $changes = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                throw new ScimException(400, 'invalidSyntax', 'Each operation must be an object.');
            }

            $op = Str::lower((string) ($operation['op'] ?? ''));

            if (! in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(400, 'invalidSyntax', 'op must be add, replace or remove.');
            }

            $path = $operation['path'] ?? null;
            $value = $operation['value'] ?? null;

            if ($path === null || $path === '') {
                if (! is_array($value)) {
                    throw new ScimException(400, 'invalidSyntax', 'An operation without a path needs an object value.');
                }

                foreach ($this->flatten($value) as $key => $item) {
                    $this->collect($changes, $user, $key, $item, remove: false);
                }

                continue;
            }

            $this->collect($changes, $user, (string) $path, $value, remove: $op === 'remove');
        }

        $this->apply($tenantId, $user, $changes, $tokenId);

        return $this->get($tenantId, $id);
    }

    /**
     * DELETE: deactivates the membership; the user is kept.
     *
     * @throws ScimException
     */
    public function deactivate(string $tenantId, string $id, string $tokenId): void
    {
        $user = $this->member($tenantId, $id);

        $this->apply($tenantId, $user, ['active' => false], $tokenId, deleted: true);
    }

    /**
     * @return Builder<User>
     */
    private function members(string $tenantId): Builder
    {
        return User::query()
            ->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
            ->where('tenant_user.tenant_id', $tenantId)
            ->select(['users.*', 'tenant_user.scim_external_id as scim_external_id', 'tenant_user.suspended_at as membership_suspended_at', 'tenant_user.role as membership_role']);
    }

    /**
     * @throws ScimException
     */
    private function member(string $tenantId, string $id): User
    {
        if (! Str::isUuid($id)) {
            throw new ScimException(404, null, 'User not found.');
        }

        /** @var User|null $user */
        $user = $this->members($tenantId)->where('users.id', $id)->first();

        return $user ?? throw new ScimException(404, null, 'User not found.');
    }

    /**
     * @param  Builder<User>  $query
     *
     * @throws ScimException
     */
    private function applyFilter(Builder $query, ?string $filter): void
    {
        if ($filter === null || trim($filter) === '') {
            return;
        }

        if (preg_match('/^\s*(userName|externalId)\s+eq\s+"([^"]*)"\s*$/i', $filter, $match) !== 1) {
            throw new ScimException(400, 'invalidFilter', 'Only the filters userName eq "value" and externalId eq "value" are supported.');
        }

        if (Str::lower($match[1]) === 'username') {
            $query->whereRaw('lower(users.email) = ?', [Str::lower($match[2])]);
        } else {
            $query->where('tenant_user.scim_external_id', $match[2]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function represent(User $user): array
    {
        $suspended = $user->getAttribute('membership_suspended_at') !== null;
        $externalId = $user->getAttribute('scim_external_id');

        $resource = [
            'schemas' => [self::USER_SCHEMA],
            'id' => (string) $user->id,
            'userName' => $user->email,
            'name' => [
                'formatted' => $user->name,
                'givenName' => $user->first_name,
                'familyName' => $user->last_name,
            ],
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => ! $suspended && $user->is_active,
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => url('/api/v1/scim/v2/Users/'.$user->id),
            ],
        ];

        if ($externalId !== null) {
            $resource['externalId'] = (string) $externalId;
        }

        return $resource;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    private function validated(array $payload, bool $creating): array
    {
        $validator = Validator::make($payload, [
            'userName' => [$creating ? 'required' : 'nullable', 'string', 'email', 'max:255'],
            'externalId' => ['nullable', 'string', 'max:255'],
            'displayName' => ['nullable', 'string', 'max:250'],
            'name' => ['nullable', 'array'],
            'name.givenName' => ['nullable', 'string', 'max:120'],
            'name.familyName' => ['nullable', 'string', 'max:120'],
            'name.formatted' => ['nullable', 'string', 'max:250'],
            'active' => ['nullable'],
            'roles' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ScimException(400, 'invalidValue', (string) $validator->errors()->first());
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();

        return $data;
    }

    /**
     * @throws ScimException when the tenant restricted its identity domains and the address is outside them
     */
    private function assertDomainAllowed(string $tenantId, string $email): void
    {
        /** @var SsoProvider|null $provider */
        $provider = SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($provider === null || (array) $provider->allowed_email_domains === []) {
            return;
        }

        $allowed = array_map(fn (mixed $domain): string => Str::lower(trim((string) $domain)), (array) $provider->allowed_email_domains);

        if (! in_array(Str::after($email, '@'), $allowed, true)) {
            throw new ScimException(400, 'invalidValue', 'The email domain is not allowed for this organization.');
        }
    }

    /**
     * @param  array<int, mixed>  $roles  SCIM `roles` entries ({value: "..."} or plain strings)
     */
    private function roleFor(string $tenantId, array $roles): string
    {
        foreach ($roles as $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;

            if (is_string($value) && ProvisionableRoles::isAllowed($value)) {
                return $value;
            }
        }

        /** @var SsoProvider|null $provider */
        $provider = SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($provider !== null && ProvisionableRoles::isAllowed($provider->default_role)) {
            return (string) $provider->default_role;
        }

        $default = (string) config('sso.scim_default_role');

        return ProvisionableRoles::isAllowed($default) ? $default : 'member';
    }

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws ScimException
     */
    private function collect(array &$changes, User $user, string $path, mixed $value, bool $remove): void
    {
        $normalized = Str::lower($path);

        switch ($normalized) {
            case 'active':
                if ($remove) {
                    throw new ScimException(400, 'mutability', 'active cannot be removed.');
                }
                $bool = $this->toBool($value);
                if ($bool === null) {
                    throw new ScimException(400, 'invalidValue', 'active must be a boolean.');
                }
                $changes['active'] = $bool;
                break;
            case 'externalid':
                $changes['externalId'] = $remove ? null : $this->nullableString($value);
                break;
            case 'displayname':
            case 'name.formatted':
                $changes['displayName'] = $remove ? null : $this->nullableString($value);
                break;
            case 'name.givenname':
                $changes['givenName'] = $remove ? null : $this->nullableString($value);
                break;
            case 'name.familyname':
                $changes['familyName'] = $remove ? null : $this->nullableString($value);
                break;
            case 'username':
            case 'emails':
            case 'emails[type eq "work"].value':
            case 'emails.value':
                $this->assertSameEmail($user, $value);
                break;
            default:
                throw new ScimException(400, 'noTarget', 'The path '.$path.' is not supported.');
        }
    }

    /**
     * @throws ScimException when a patch tries to move the account to another address
     */
    private function assertSameEmail(User $user, mixed $value): void
    {
        $candidate = is_array($value) ? ($value[0]['value'] ?? ($value['value'] ?? null)) : $value;

        if (is_string($candidate) && Str::lower(trim($candidate)) !== Str::lower((string) $user->email)) {
            throw new ScimException(400, 'mutability', 'userName cannot be changed through SCIM.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function flatten(array $value, string $prefix = ''): array
    {
        $flat = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($item) && ! array_is_list($item) && Str::lower((string) $key) === 'name') {
                $flat += $this->flatten($item, $path);
            } else {
                $flat[$path] = $item;
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $changes  keys: active, externalId, displayName, givenName, familyName
     */
    private function apply(string $tenantId, User $user, array $changes, string $tokenId, bool $deleted = false): void
    {
        $membership = DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $user->id);
        $wasActive = $user->getAttribute('membership_suspended_at') === null;
        $becomesActive = $changes['active'] ?? $wasActive;
        $suspending = $wasActive && ! $becomesActive;

        $membershipChanges = [];

        if (array_key_exists('externalId', $changes)) {
            $membershipChanges['scim_external_id'] = $changes['externalId'];
        }

        if ($becomesActive !== $wasActive) {
            $membershipChanges['suspended_at'] = $becomesActive ? null : now();
        }

        DB::transaction(function () use ($membership, $membershipChanges, $user, $changes, $tenantId, $suspending): void {
            if ($membershipChanges !== []) {
                $membership->update($membershipChanges + ['updated_at' => now()]);
            }

            if (DB::table('tenant_user')->where('user_id', $user->id)->count() === 1) {
                $this->applyProfile($user, $changes);
            }

            if ($suspending) {
                $this->revokeAccess($user, $tenantId);
            }
        });

        if ($becomesActive !== $wasActive) {
            $this->audit->record($becomesActive ? RegistrationAudit::SCIM_USER_UPDATED : RegistrationAudit::SCIM_USER_DEACTIVATED, (string) $user->id, ['scim_token' => $tokenId, 'active' => $becomesActive, 'deleted' => $deleted], $tenantId);
        } elseif (! $deleted && $changes !== []) {
            $this->audit->record(RegistrationAudit::SCIM_USER_UPDATED, (string) $user->id, ['scim_token' => $tokenId, 'fields' => array_keys($changes)], $tenantId);
        }
    }

    /**
     * Names are written only for a user of this tenant alone (see the class documentation).
     *
     * @param  array<string, mixed>  $changes
     */
    private function applyProfile(User $user, array $changes): void
    {
        $profile = [];

        foreach (['givenName' => 'first_name', 'familyName' => 'last_name'] as $key => $column) {
            if (array_key_exists($key, $changes)) {
                $profile[$column] = $changes[$key];
            }
        }

        if (array_key_exists('displayName', $changes) && $changes['displayName'] !== null) {
            $profile['name'] = Str::limit((string) $changes['displayName'], 250, '');
        }

        if ($profile !== []) {
            User::query()->whereKey($user->id)->update($profile + ['updated_at' => now()]);
        }
    }

    /**
     * The accesses opened through the SSO of this tenant end with the membership; when the user has no active membership
     * left, every access ends.
     */
    private function revokeAccess(User $user, string $tenantId): void
    {
        $user->tokens()->where('auth_tenant_id', $tenantId)->delete();

        if (! DB::table('tenant_user')->where('user_id', $user->id)->whereNull('suspended_at')->exists()) {
            $this->sessions->revokeAll($user);
        }
    }

    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && in_array(Str::lower($value), ['true', 'false'], true)) {
            return Str::lower($value) === 'true';
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
