<?php

declare(strict_types=1);

namespace Modules\Collaboration\Services;

use App\Models\User;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use InvalidArgumentException;

/**
 * The record types that the global search, the comments and the tags work on. A module makes its records searchable,
 * commentable and taggable by registering a RecordType from the boot method of its service provider:
 * app(CollaborationRegistry::class)->register(new RecordType(...)). Types that are not registered are refused, so a
 * client can never point a comment or a tag at an arbitrary table.
 */
final class CollaborationRegistry
{
    /** @var array<string, RecordType> */
    private array $types = [];

    public function __construct(private readonly ModuleAccessGate $gate) {}

    public function register(RecordType $type): void
    {
        if (mb_strlen($type->key) > 40 || preg_match('/^[a-z][a-z0-9_]*$/', $type->key) !== 1) {
            throw new InvalidArgumentException("Invalid collaboration record type key [{$type->key}].");
        }

        $this->types[$type->key] = $type;
    }

    public function find(string $key): ?RecordType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * @return array<string, RecordType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The types whose module the member may read in the company.
     *
     * @return array<string, RecordType>
     */
    public function readableBy(?User $user, ?string $tenantId): array
    {
        return array_filter(
            $this->types,
            fn (RecordType $type): bool => $this->mayRead($user, $tenantId, $type),
        );
    }

    public function mayRead(?User $user, ?string $tenantId, RecordType $type): bool
    {
        return $this->gate->allows($user, $tenantId, $type->module, 'read');
    }
}
