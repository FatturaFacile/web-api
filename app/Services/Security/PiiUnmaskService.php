<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\Concerns\HasEncryptedFields;
use App\Services\Audit\AuditTrailRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Just-in-time unmasking: returns the clear value of one encrypted field and writes a PII_UNMASKED audit record with
 * the justification of the caller. The value itself never enters the audit trail.
 */
final class PiiUnmaskService
{
    public function __construct(private readonly AuditTrailRecorder $audit) {}

    /**
     * @throws ModelNotFoundException when the alias, row or field is unknown (or belongs to another tenant)
     */
    public function reveal(string $entityType, string $entityId, string $field, string $justification, string $tenantId): string
    {
        /** @var array<string, class-string<Model>> $models */
        $models = config('field_encryption.models', []);
        $class = $models[$entityType] ?? null;

        if ($class === null || ! in_array(HasEncryptedFields::class, class_uses_recursive($class), true)) {
            throw new ModelNotFoundException;
        }

        /** @var Model $model */
        $model = $class::query()->where('tenant_id', $tenantId)->findOrFail($entityId);

        if (! method_exists($model, 'encryptedFieldMap') || ! array_key_exists($field, $model->encryptedFieldMap())) {
            throw new ModelNotFoundException;
        }

        $value = $model->getAttribute($field);

        $this->audit->record(
            event: AuditEventType::PiiUnmasked,
            entityType: $model::class,
            entityId: $entityId,
            tenantId: $tenantId,
            changedFields: [$field],
            context: ['field' => $field, 'justification' => $justification],
        );

        return is_string($value) ? $value : '';
    }
}
