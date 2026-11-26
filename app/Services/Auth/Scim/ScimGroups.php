<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use App\Models\ScimGroup;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 `Group` resources (RFC 7643 section 4.2, RFC 7644) of one tenant. Every query is scoped to the tenant of the
 * SCIM token, so a group (or a member) of another tenant simply does not exist here.
 *
 * Decisions:
 * - `displayName` is unique within the tenant (case-insensitive) and is the key a role mapping uses (ScimGroupMapping);
 * - members are users that already belong to the tenant (provision them through `/Users` first); a member that does
 *   not is a 400, removing a user who is not a member is a no-op;
 * - a change of members assigns or revokes the mapped role through ScimGroupRoles (never the owner, never a role that
 *   no mapping produced); deleting a group removes its members first, so their roles are revoked;
 * - PUT replaces the whole group: members that are not sent are removed.
 *
 * Supported: filter `displayName eq "x"` and `externalId eq "x"`, startIndex/count paging, PATCH add/remove/replace on
 * `members` (also `members[value eq "id"]`), `displayName` and `externalId`. Not supported: nested groups, sort.
 */
final class ScimGroups
{
    public const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    public function __construct(
        private readonly RegistrationAudit $audit,
        private readonly ScimGroupRoles $roles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(string $tenantId, ?string $filter, int $startIndex, int $count): array
    {
        $query = ScimGroup::withoutGlobalScopes()->where('tenant_id', $tenantId);
        $this->applyFilter($query, $filter);

        $total = (clone $query)->count();
        $startIndex = max(1, $startIndex);
        $count = max(0, min($count, ScimUsers::MAX_PAGE_SIZE));
        $page = $count === 0 ? collect() : $query->orderBy('created_at')->orderBy('id')->skip($startIndex - 1)->take($count)->get();
        $members = $this->membersOf($tenantId, $page->pluck('id')->all());

        return [
            'schemas' => [ScimUsers::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $page->count(),
            'Resources' => $page->map(fn (ScimGroup $group): array => $this->represent($group, $members[$group->id] ?? []))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ScimException 404 when the group does not exist in the tenant
     */
    public function get(string $tenantId, string $id): array
    {
        $group = $this->find($tenantId, $id);

        return $this->represent($group, $this->membersOf($tenantId, [$group->id])[$group->id] ?? []);
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
        $name = trim((string) $data['displayName']);
        $this->assertNameFree($tenantId, $name, null);
        $memberIds = $this->memberIds($tenantId, $data['members'] ?? []);

        $group = DB::transaction(function () use ($tenantId, $name, $data, $memberIds): ScimGroup {
            $group = new ScimGroup(['display_name' => $name, 'external_id' => $this->nullableString($data['externalId'] ?? null)]);
            $group->forceFill(['tenant_id' => $tenantId])->save();
            $this->addMembers($tenantId, $group, $memberIds);

            return $group;
        });

        $this->audit->record(RegistrationAudit::SCIM_GROUP_CREATED, null, ['scim_token' => $tokenId, 'group_id' => $group->id, 'members' => count($memberIds)], $tenantId);
        $this->syncRoles($tenantId, $memberIds);

        return $this->get($tenantId, (string) $group->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    public function replace(string $tenantId, string $id, array $payload, string $tokenId): array
    {
        $group = $this->find($tenantId, $id);
        $data = $this->validated($payload, creating: true);
        $name = trim((string) $data['displayName']);
        $this->assertNameFree($tenantId, $name, $group);
        $wanted = $this->memberIds($tenantId, $data['members'] ?? []);

        $this->persist($tenantId, $group, [
            'display_name' => $name,
            'external_id' => $this->nullableString($data['externalId'] ?? null),
        ], $wanted, $tokenId);

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
        $group = $this->find($tenantId, $id);
        $operations = $payload['Operations'] ?? null;

        if (! is_array($operations) || $operations === []) {
            throw new ScimException(400, 'invalidSyntax', 'Operations is required.');
        }

        $attributes = [];
        $members = $this->currentMemberIds($tenantId, (string) $group->id);

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

                foreach ($value as $key => $item) {
                    $this->applyOperation($tenantId, $op === 'remove' ? 'add' : $op, (string) $key, $item, $attributes, $members);
                }

                continue;
            }

            $this->applyOperation($tenantId, $op, (string) $path, $value, $attributes, $members);
        }

        if (isset($attributes['display_name'])) {
            $this->assertNameFree($tenantId, $attributes['display_name'], $group);
        }

        $this->persist($tenantId, $group, $attributes, array_keys($members), $tokenId);

        return $this->get($tenantId, $id);
    }

    /**
     * DELETE: the group goes and its members lose the roles it granted.
     *
     * @throws ScimException
     */
    public function delete(string $tenantId, string $id, string $tokenId): void
    {
        $group = $this->find($tenantId, $id);
        $memberIds = $this->currentMemberIds($tenantId, (string) $group->id);

        DB::transaction(function () use ($tenantId, $group): void {
            DB::table('scim_group_members')->where('tenant_id', $tenantId)->where('scim_group_id', $group->id)->delete();
            $group->delete();
        });

        $this->audit->record(RegistrationAudit::SCIM_GROUP_DELETED, null, ['scim_token' => $tokenId, 'group_id' => $group->id, 'members' => count($memberIds)], $tenantId);
        $this->syncRoles($tenantId, array_keys($memberIds));
    }

    /**
     * @throws ScimException
     */
    private function find(string $tenantId, string $id): ScimGroup
    {
        if (! Str::isUuid($id)) {
            throw new ScimException(404, null, 'Group not found.');
        }

        return ScimGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($id)->first() ?? throw new ScimException(404, null, 'Group not found.');
    }

    /**
     * Writes the new attributes and the new member set of a group, then applies the roles of the members that changed.
     *
     * @param  array<string, mixed>  $attributes  columns of scim_groups
     * @param  array<int|string, mixed>  $wanted  user ids of the members after the change
     */
    private function persist(string $tenantId, ScimGroup $group, array $attributes, array $wanted, string $tokenId): void
    {
        $wanted = array_values(array_unique(array_map('strval', $wanted)));
        $current = array_keys($this->currentMemberIds($tenantId, (string) $group->id));
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        DB::transaction(function () use ($tenantId, $group, $attributes, $added, $removed): void {
            if ($attributes !== []) {
                $group->forceFill($attributes)->save();
            }

            $this->addMembers($tenantId, $group, $added);

            if ($removed !== []) {
                DB::table('scim_group_members')->where('tenant_id', $tenantId)->where('scim_group_id', $group->id)->whereIn('user_id', $removed)->delete();
            }
        });

        if ($attributes !== []) {
            $this->audit->record(RegistrationAudit::SCIM_GROUP_UPDATED, null, ['scim_token' => $tokenId, 'group_id' => $group->id, 'fields' => array_keys($attributes)], $tenantId);
        }

        if ($added !== [] || $removed !== []) {
            $this->audit->record(RegistrationAudit::SCIM_GROUP_MEMBERS_CHANGED, null, ['scim_token' => $tokenId, 'group_id' => $group->id, 'added' => $added, 'removed' => $removed], $tenantId);
        }

        // A renamed group changes which mapping applies to all of its members.
        $this->syncRoles($tenantId, isset($attributes['display_name']) ? [...$wanted, ...$removed] : [...$added, ...$removed]);
    }

    /**
     * @param  list<string>  $userIds
     */
    private function addMembers(string $tenantId, ScimGroup $group, array $userIds): void
    {
        $rows = array_map(static fn (string $userId): array => [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'scim_group_id' => $group->id,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_values(array_unique($userIds)));

        if ($rows !== []) {
            DB::table('scim_group_members')->insertOrIgnore($rows);
        }
    }

    /**
     * @param  array<int|string, mixed>  $userIds
     */
    private function syncRoles(string $tenantId, array $userIds): void
    {
        foreach (array_unique(array_map('strval', $userIds)) as $userId) {
            $this->roles->sync($tenantId, $userId);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, true>  $members  user id => true, the member set being built
     *
     * @throws ScimException
     */
    private function applyOperation(string $tenantId, string $op, string $path, mixed $value, array &$attributes, array &$members): void
    {
        $normalized = Str::lower($path);

        if ($normalized === 'displayname') {
            if ($op === 'remove') {
                throw new ScimException(400, 'mutability', 'displayName cannot be removed.');
            }

            $name = $this->nullableString($value);

            if ($name === null || mb_strlen($name) > 255) {
                throw new ScimException(400, 'invalidValue', 'displayName must be a non-empty string of at most 255 characters.');
            }

            $attributes['display_name'] = $name;

            return;
        }

        if ($normalized === 'externalid') {
            $attributes['external_id'] = $op === 'remove' ? null : $this->nullableString($value);

            return;
        }

        if (preg_match('/^members(?:\[\s*value\s+eq\s+"([^"]+)"\s*\])?$/i', $path, $match) !== 1) {
            throw new ScimException(400, 'noTarget', 'The path '.$path.' is not supported.');
        }

        $target = $match[1] ?? null;

        if ($target !== null) {
            if ($op !== 'remove') {
                throw new ScimException(400, 'invalidPath', 'A members filter path is only valid for remove.');
            }

            unset($members[Str::lower($target)], $members[$target]);

            return;
        }

        $ids = is_array($value) ? $this->memberIds($tenantId, $value, tolerateUnknown: $op === 'remove') : [];

        match ($op) {
            'add' => $members += array_fill_keys($ids, true),
            'replace' => $members = array_fill_keys($ids, true),
            default => $members = $value === null ? [] : array_diff_key($members, array_fill_keys($ids, true)),
        };
    }

    /**
     * The ids of the users of a `members` value, each a member of the tenant.
     *
     * @param  mixed  $entries  list of {value: id} objects (plain ids are tolerated)
     * @return list<string>
     *
     * @throws ScimException when an entry is not a user of the tenant
     */
    private function memberIds(string $tenantId, mixed $entries, bool $tolerateUnknown = false): array
    {
        if ($entries === null || $entries === []) {
            return [];
        }

        if (! is_array($entries)) {
            throw new ScimException(400, 'invalidValue', 'members must be a list.');
        }

        $ids = [];

        foreach ($entries as $entry) {
            $id = is_array($entry) ? ($entry['value'] ?? null) : $entry;

            if (! is_string($id) || ! Str::isUuid($id)) {
                if ($tolerateUnknown) {
                    continue;
                }

                throw new ScimException(400, 'invalidValue', 'Each member needs the id of a user.');
            }

            $ids[] = Str::lower($id);
        }

        $ids = array_values(array_unique($ids));
        $known = DB::table('tenant_user')->where('tenant_id', $tenantId)->whereIn('user_id', $ids)->pluck('user_id')->map(static fn (mixed $id): string => Str::lower((string) $id))->all();
        $unknown = array_diff($ids, $known);

        if ($unknown !== [] && ! $tolerateUnknown) {
            throw new ScimException(400, 'invalidValue', 'Unknown member: '.reset($unknown).'. Provision the user through /Users first.');
        }

        return array_values(array_intersect($ids, $known));
    }

    /**
     * @return array<string, true> user id => true
     */
    private function currentMemberIds(string $tenantId, string $groupId): array
    {
        return array_fill_keys(
            DB::table('scim_group_members')->where('tenant_id', $tenantId)->where('scim_group_id', $groupId)->pluck('user_id')->map(static fn (mixed $id): string => (string) $id)->all(),
            true,
        );
    }

    /**
     * @param  list<string>  $groupIds
     * @return array<string, list<array<string, string>>> group id => members
     */
    private function membersOf(string $tenantId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows = DB::table('scim_group_members as m')
            ->join('users', 'users.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenantId)->whereIn('m.scim_group_id', $groupIds)
            ->orderBy('users.name')->orderBy('users.id')
            ->get(['m.scim_group_id', 'users.id as user_id', 'users.name as user_name']);

        $members = [];

        foreach ($rows as $row) {
            $members[(string) $row->scim_group_id][] = [
                'value' => (string) $row->user_id,
                'display' => (string) $row->user_name,
                '$ref' => url('/api/v1/scim/v2/Users/'.$row->user_id),
                'type' => 'User',
            ];
        }

        return $members;
    }

    /**
     * @param  Builder<ScimGroup>  $query
     *
     * @throws ScimException
     */
    private function applyFilter(Builder $query, ?string $filter): void
    {
        if ($filter === null || trim($filter) === '') {
            return;
        }

        if (preg_match('/^\s*(displayName|externalId)\s+eq\s+"([^"]*)"\s*$/i', $filter, $match) !== 1) {
            throw new ScimException(400, 'invalidFilter', 'Only the filters displayName eq "value" and externalId eq "value" are supported.');
        }

        if (Str::lower($match[1]) === 'displayname') {
            $query->whereRaw('lower(display_name) = ?', [Str::lower($match[2])]);
        } else {
            $query->where('external_id', $match[2]);
        }
    }

    /**
     * @param  list<array<string, string>>  $members
     * @return array<string, mixed>
     */
    private function represent(ScimGroup $group, array $members): array
    {
        $resource = [
            'schemas' => [self::GROUP_SCHEMA],
            'id' => (string) $group->id,
            'displayName' => $group->display_name,
            'members' => $members,
            'meta' => [
                'resourceType' => 'Group',
                'created' => $group->created_at?->toIso8601String(),
                'lastModified' => $group->updated_at?->toIso8601String(),
                'location' => url('/api/v1/scim/v2/Groups/'.$group->id),
            ],
        ];

        if ($group->external_id !== null) {
            $resource['externalId'] = $group->external_id;
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
            'displayName' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'externalId' => ['nullable', 'string', 'max:255'],
            'members' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ScimException(400, 'invalidValue', (string) $validator->errors()->first());
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();

        if (isset($data['displayName']) && trim((string) $data['displayName']) === '') {
            throw new ScimException(400, 'invalidValue', 'displayName is required.');
        }

        return $data;
    }

    /**
     * @throws ScimException 409 when another group of the tenant has the name
     */
    private function assertNameFree(string $tenantId, string $name, ?ScimGroup $except): void
    {
        $query = ScimGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereRaw('lower(display_name) = ?', [Str::lower($name)]);

        if ($except !== null) {
            $query->whereKeyNot($except->id);
        }

        if ($query->exists()) {
            throw new ScimException(409, 'uniqueness', 'A group with this displayName already exists.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
