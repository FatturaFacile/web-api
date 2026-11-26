<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Support\AuditIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Append one entry to the audit chain of a tenant, inside the business transaction of the caller (transactional outbox:
 * a rollback leaves no entry, a commit guarantees it).
 *
 * `before` and `after` are the raw states; the handler computes the attribute diff, drops the excluded attributes, never
 * records secrets and replaces personal attributes with pseudonym tokens.
 */
final readonly class AppendAuditEntryCommand
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $personalAttributes
     * @param  list<string>  $excludedAttributes
     * @param  array<string, mixed>  $context  extra facts covered by the hash (delete mode, export format, restore id...)
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $moduleCode,
        public AuditAction $action,
        public string $resourceType,
        public string $resourceId,
        public ?array $before = null,
        public ?array $after = null,
        public ?string $actorUserId = null,
        public ?string $actorType = null,
        public ?AuditCategory $category = null,
        public array $personalAttributes = [],
        public array $excludedAttributes = [],
        public array $context = [],
        public ?ClientContext $client = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $personalAttributes
     * @param  list<string>  $excludedAttributes
     * @param  array<string, mixed>  $context
     */
    public static function forModel(
        string $action,
        Model $model,
        ?array $before,
        ?array $after,
        string $tenantId,
        string $companyId,
        string $moduleCode,
        array $personalAttributes = [],
        array $excludedAttributes = [],
        array $context = [],
    ): self {
        $rawKey = $model->getKey() ?: $model->getAttribute('id');

        return new self(
            tenantId: $tenantId,
            companyId: $companyId,
            moduleCode: $moduleCode,
            action: AuditAction::make($action),
            resourceType: substr($model::class, 0, 60),
            resourceId: (string) ($rawKey ?: Str::uuid7()),
            before: $before,
            after: $after,
            personalAttributes: $personalAttributes,
            excludedAttributes: $excludedAttributes,
            context: $context,
        );
    }

    /**
     * The command of the array form used by AuditLedgerInterface::recordPayload().
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): self
    {
        $tenantId = (string) ($data['tenant_id'] ?? AuditIds::NIL_TENANT);
        $request = app()->bound('request') ? app('request') : null;
        $client = ClientContext::fromRequest($request instanceof Request ? $request : null);

        if (isset($data['channel']) && in_array($data['channel'], ClientContext::CHANNELS, true)) {
            $client = new ClientContext((string) $data['channel'], $client->requestId, $client->ipAddress, $client->userAgent);
        }

        if (array_key_exists('ip_address', $data) || array_key_exists('user_agent', $data)) {
            $client = new ClientContext(
                $client->channel,
                $client->requestId,
                ClientContext::truncateIp(isset($data['ip_address']) ? (string) $data['ip_address'] : null),
                ClientContext::sanitizeUserAgent(isset($data['user_agent']) ? (string) $data['user_agent'] : null),
            );
        }

        return new self(
            tenantId: $tenantId,
            companyId: (string) ($data['company_id'] ?? $tenantId),
            moduleCode: substr((string) ($data['module_code'] ?? 'CORE'), 0, 30),
            action: AuditAction::make((string) ($data['action'] ?? 'ACTION')),
            resourceType: substr((string) ($data['model_class'] ?? 'Unknown'), 0, 60),
            resourceId: (string) ($data['model_id'] ?? Str::uuid7()),
            before: isset($data['payload_before']) && is_array($data['payload_before']) ? $data['payload_before'] : null,
            after: isset($data['payload_after']) && is_array($data['payload_after']) ? $data['payload_after'] : null,
            actorUserId: isset($data['actor_user_id']) ? (string) $data['actor_user_id'] : null,
            actorType: isset($data['actor_type']) ? (string) $data['actor_type'] : null,
            category: isset($data['category']) ? AuditCategory::tryFrom((string) $data['category']) : null,
            personalAttributes: array_values(array_map('strval', (array) ($data['personal_attributes'] ?? []))),
            excludedAttributes: array_values(array_map('strval', (array) ($data['excluded_attributes'] ?? []))),
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
            client: $client,
        );
    }
}
