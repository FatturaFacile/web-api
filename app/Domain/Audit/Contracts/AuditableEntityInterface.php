<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\Enums\AuditEventType;

/**
 * An entity whose lifecycle (and optionally reads) is written to the immutable audit trail.
 */
interface AuditableEntityInterface
{
    /**
     * Attributes never written to the trail (secrets).
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array;

    /**
     * Attributes written masked (IBAN, AHV number, tax code): the trail never holds their clear value.
     *
     * @return list<string>
     */
    public function auditMaskedAttributes(): array;

    public function auditTracksReads(): bool;

    /**
     * Entity name stored as auditable_type (stable across refactorings of the class name).
     */
    public function auditEntityName(): string;

    /**
     * Lets an entity promote a plain update to a more specific event (a permission change).
     *
     * @param  list<string>  $changedFields
     */
    public function auditEventTypeFor(AuditEventType $default, array $changedFields): AuditEventType;
}
