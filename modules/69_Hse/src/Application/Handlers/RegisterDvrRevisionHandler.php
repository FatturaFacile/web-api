<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use App\Models\Tenant;
use Modules\Hse\Application\Commands\RegisterDvrRevisionCommand;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Infrastructure\Models\DvrDocumentRegistry;

/**
 * Registers the next revision of the Italian DVR. It is "signed" once the employer, the RSPP, the RLS and the
 * occupational physician have all signed; until then it stays a draft.
 */
final class RegisterDvrRevisionHandler
{
    public function handle(RegisterDvrRevisionCommand $command): DvrDocumentRegistry
    {
        if (Jurisdiction::forCountry(Tenant::query()->findOrFail($command->tenantId)->country) !== Jurisdiction::Italy) {
            throw new HseException('The DVR applies to Italian tenants.');
        }

        $unknown = array_diff(array_keys($command->signatures), DvrDocumentRegistry::RequiredSignatories);

        if ($unknown !== []) {
            throw new HseException('Unknown DVR signatory: '.implode(', ', $unknown).'.');
        }

        $complete = array_diff(DvrDocumentRegistry::RequiredSignatories, array_keys($command->signatures)) === [];
        $next = (int) DvrDocumentRegistry::withTrashed()->where('tenant_id', $command->tenantId)->max('revision_number') + 1;

        return DvrDocumentRegistry::create([
            'tenant_id' => $command->tenantId,
            'revision_number' => $next,
            'issued_at' => $command->issuedAt->toDateString(),
            'review_due_at' => $command->reviewDueAt?->toDateString(),
            'signatures_json' => $command->signatures,
            'document_id' => $command->documentId,
            'status' => $complete ? 'signed' : 'draft',
        ]);
    }
}
