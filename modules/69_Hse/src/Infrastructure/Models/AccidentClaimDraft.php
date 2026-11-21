<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Hse\Domain\Enums\Jurisdiction;

/**
 * The pre-filled statutory accident declaration of Mod 074 (SUVA Sunet or INAIL), awaiting the review of payroll.
 *
 * @property string $incident_report_id
 * @property Jurisdiction $jurisdiction
 * @property string $schema_version
 * @property string|null $declaration_type
 * @property array<string, mixed> $payload_json
 * @property string $status
 */
final class AccidentClaimDraft extends BaseEnterpriseModel
{
    protected $table = 'hse_accident_claim_drafts';

    protected function casts(): array
    {
        return ['jurisdiction' => Jurisdiction::class, 'payload_json' => 'array'];
    }
}
